<?php

namespace ByLopez\Cargues\Services\Cargas;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Models\CargaDato;
use ByLopez\Cargues\Models\CargaIncidencia;
use ByLopez\Cargues\Services\Lectura\LectorFactory;
use ByLopez\Cargues\Services\Lectura\LimitesLectura;
use ByLopez\Cargues\Services\Lectura\OpcionesLectura;
use ByLopez\Cargues\Support\Estados;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

/**
 * P5 + P6 en UNA pasada de lectura, antes de cualquier cambio de negocio:
 *  - T6.datos_originales: la fila TAL COMO VIENE (sin normalizar; ver FilaLeida), una fila de T6 por cada fila del
 *    archivo, vacias incluidas y con su numero real;
 *  - T6.datos_normalizados: resultado separado de Normalizador (el original no se toca);
 *  - T7: los motivos por fila y campo; deteccion de duplicados segun `map.duplicados` al terminar.
 * Cada lote va en una transaccion propia y el progreso se escribe DESPUES de su COMMIT. Repetible: borra primero lo de
 * esta carga (permitido solo mientras la carga nunca inicio una ejecucion).
 */
class AuditoriaService
{
    public function __construct(
        protected LectorFactory $lectores,
        protected ArchivoService $archivos,
        protected Normalizador $normalizador,
        protected DuplicadosService $duplicados,
        protected ProgresoService $progreso,
    ) {}

    /** @return array{total:int, vacias:int, rechazadas:int} */
    public function procesar(Carga $carga, string $jobUuid): array
    {
        if ($carga->ejecucion_token) {
            throw new OperacionInvalidaException('ENTRADA_INMUTABLE', 'La carga ya inició una ejecución: sus datos de entrada son inmutables.');
        }

        $cfg = (array) $carga->config_congelada;
        $map = (array) $cfg['map'];
        $campos = (array) $map['campos'];
        $accion = (string) $carga->accion;
        $relaciones = (array) $carga->relaciones;
        $limites = LimitesLectura::para($cfg['limites'] ?? null);
        $opciones = OpcionesLectura::desdeCarga($carga);
        $ruta = $this->archivos->rutaReal($carga->archivo_ruta);
        $lector = $this->lectores->para($carga->formato);

        $this->limpiar($carga);

        $inspeccion = $lector->inspeccionar($ruta, $opciones, $limites);
        $total = (int) ($inspeccion->filas ?? 0);
        $this->progreso->avanzar($carga, $jobUuid, 'leyendo', 0, $total, 'Leyendo el archivo', true);

        $llaves = $this->gruposDeLlaves($map);
        $claves = array_fill(0, count($llaves), []); // grupo => [fila => valores de la llave]
        $filasLeidas = 0;
        $vacias = 0;
        $maxFilas = $limites->maxFilas;
        $lote = [];
        $incidencias = [];
        $bytes = 0;
        $limiteBytes = (int) config('cargues.lotes.auditoria_bytes');
        $limiteFilas = (int) config('cargues.lotes.auditoria_filas');

        foreach ($lector->filas($ruta, $opciones, $limites) as $fila) {
            $filasLeidas++;

            if ($filasLeidas > $maxFilas) {
                throw new OperacionInvalidaException('ARCHIVO_EXCEDE_FILAS', "El archivo supera el máximo de {$maxFilas} filas.");
            }

            $registro = $this->registro($carga, $fila, $relaciones, $campos, $accion, $incidencias);

            if ($registro['estado'] === Estados::FILA_VACIA) {
                $vacias++;
            } elseif ($registro['estado'] === Estados::FILA_PENDIENTE) {
                foreach ($llaves as $gi => $g) {
                    $claves[$gi][$fila->numero] = array_map(fn ($c) => $registro['valores'][$c] ?? null, $g);
                }
            }

            $lote[] = $registro['fila'];
            $bytes += strlen($registro['fila']['datos_originales']) + strlen($registro['fila']['datos_normalizados'] ?? '') + 64;

            if ($bytes >= $limiteBytes || count($lote) >= $limiteFilas) {
                $this->volcar($lote, $incidencias);
                $lote = [];
                $incidencias = [];
                $bytes = 0;
                $this->progreso->avanzar($carga, $jobUuid, 'leyendo', $filasLeidas, max($total, $filasLeidas));
            }
        }

        $this->volcar($lote, $incidencias);
        $this->progreso->avanzar($carga, $jobUuid, 'comprobando_duplicados', $filasLeidas, max($total, $filasLeidas), 'Comprobando llaves duplicadas', true);

        $this->aplicarDuplicados($carga, $map, $claves);

        DB::statement(
            'UPDATE bylopez_cg_carga_incidencias i JOIN bylopez_cg_carga_datos d ON d.carga_id = i.carga_id AND d.fila_origen = i.fila_origen SET i.dato_id = d.id WHERE i.carga_id = ? AND i.dato_id IS NULL',
            [$carga->id],
        );

        $rechazadas = CargaDato::where('carga_id', $carga->id)->where('estado_final', Estados::FILA_RECHAZADA)->count();

        return ['total' => $filasLeidas, 'vacias' => $vacias, 'rechazadas' => $rechazadas];
    }

    /** Borra T7 y T6 de esta carga (solo antes de cualquier ejecucion). */
    protected function limpiar(Carga $carga): void
    {
        do {
            $n = DB::delete('DELETE FROM bylopez_cg_carga_incidencias WHERE carga_id = ? LIMIT 5000', [$carga->id]);
        } while ($n === 5000);

        do {
            $n = DB::delete('DELETE FROM bylopez_cg_carga_datos WHERE carga_id = ? LIMIT 5000', [$carga->id]);
        } while ($n === 5000);
    }

    /**
     * Fila de T6 para una fila del archivo. El original se guarda tal cual; si la normalizacion falla por un error del
     * motor (no del dato), la fila queda rechazada con motivo y el original intacto.
     *
     * @return array{fila: array, estado: string, valores: array}
     */
    protected function registro(Carga $carga, $fila, array $relaciones, array $campos, string $accion, array &$incidencias): array
    {
        try {
            $original = json_encode($fila->valores, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OperacionInvalidaException('FILA_NO_SERIALIZABLE', "La fila {$fila->numero} contiene caracteres que no se pueden guardar ({$e->getMessage()}).");
        }

        $base = ['carga_id' => $carga->id, 'fila_origen' => $fila->numero, 'datos_originales' => $original];

        if ($fila->estaVacia()) {
            return ['fila' => $base + ['datos_normalizados' => null, 'estado_final' => Estados::FILA_VACIA, 'detalle' => null], 'estado' => Estados::FILA_VACIA, 'valores' => []];
        }

        try {
            $n = $this->normalizador->normalizar($fila->valores, $relaciones, $campos, $accion);
        } catch (Throwable $e) {
            $incidencias[] = $this->incidencia($carga, $fila->numero, '', 'ERROR_NORMALIZACION', 'Error interno al normalizar la fila: '.$e->getMessage(), 'error');

            return ['fila' => $base + ['datos_normalizados' => null, 'estado_final' => Estados::FILA_RECHAZADA, 'detalle' => 'ERROR_NORMALIZACION'], 'estado' => Estados::FILA_RECHAZADA, 'valores' => []];
        }

        $errores = array_values(array_filter($n['errores'], fn ($e) => $e['severidad'] === 'error'));

        foreach ($n['errores'] as $e) {
            $incidencias[] = $this->incidencia($carga, $fila->numero, $e['campo'], $e['codigo'], $e['motivo'], $e['severidad']);
        }

        $estado = $errores ? Estados::FILA_RECHAZADA : Estados::FILA_PENDIENTE;

        return [
            'fila' => $base + [
                'datos_normalizados' => json_encode(['valores' => $n['valores'], 'ausentes' => $n['ausentes']], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                'estado_final' => $estado, 'detalle' => $errores[0]['codigo'] ?? null,
            ],
            'estado' => $estado, 'valores' => $n['valores'],
        ];
    }

    protected function incidencia(Carga $carga, int $fila, string $campo, string $codigo, string $motivo, string $severidad, string $etapa = Estados::ETAPA_VALIDACION_MOTOR): array
    {
        return ['carga_id' => $carga->id, 'dato_id' => null, 'fila_origen' => $fila, 'etapa' => $etapa, 'campo' => $campo, 'codigo' => $codigo,
            'motivo' => mb_substr($motivo, 0, 500), 'severidad' => $severidad, 'created_at' => now()];
    }

    protected function volcar(array $lote, array $incidencias): void
    {
        if ($lote === []) {
            return;
        }

        DB::transaction(function () use ($lote, $incidencias) {
            CargaDato::insert($lote);

            if ($incidencias) {
                CargaIncidencia::registrar($incidencias);
            }
        });
    }

    /** @return array<int, string[]> grupos de campos: primaria y unicas (para detectar duplicados). */
    protected function gruposDeLlaves(array $map): array
    {
        $ll = $map['llaves'] ?? [];

        return array_values(array_merge(! empty($ll['primaria']) ? [$ll['primaria']] : [], $ll['unicas'] ?? []));
    }

    /** Rechaza las filas que violarian una llave primaria o unica, segun `map.duplicados` (07 §5.5). */
    protected function aplicarDuplicados(Carga $carga, array $map, array $claves): void
    {
        $grupos = $this->gruposDeLlaves($map);

        if ($grupos === []) {
            return;
        }

        // Convierte "grupo => fila => valores" en "fila => campo => valor" solo con los campos de llave.
        $filas = [];

        foreach ($grupos as $gi => $g) {
            foreach ($claves[$gi] as $n => $vals) {
                foreach ($g as $k => $campo) {
                    $filas[$n][$campo] = $vals[$k];
                }
            }
        }

        [, $rechazadas] = $this->duplicados->detectar($filas, $map['llaves'], (string) ($map['duplicados'] ?? 'rechazar_todas'));

        foreach (array_chunk($rechazadas, 500, true) as $lote) {
            DB::transaction(function () use ($carga, $lote) {
                $inc = [];

                foreach ($lote as $n => $r) {
                    $inc[] = $this->incidencia($carga, $n, $r['campo'], 'LLAVE_DUPLICADA', $r['motivo'], 'error');
                }

                CargaDato::where('carga_id', $carga->id)->whereIn('fila_origen', array_keys($lote))
                    ->update(['estado_final' => Estados::FILA_RECHAZADA, 'detalle' => 'LLAVE_DUPLICADA']);
                CargaIncidencia::registrar($inc);
            });
        }
    }
}
