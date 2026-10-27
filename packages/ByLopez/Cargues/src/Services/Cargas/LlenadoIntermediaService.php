<?php

namespace ByLopez\Cargues\Services\Cargas;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Models\CargaDato;
use ByLopez\Cargues\Models\CargaIncidencia;
use ByLopez\Cargues\Models\Estructura;
use ByLopez\Cargues\Services\Configuracion\ActivarTipoService;
use ByLopez\Cargues\Services\Configuracion\ConexionTipo;
use ByLopez\Cargues\Services\Configuracion\IntermediaService;
use ByLopez\Cargues\Support\Esquema;
use ByLopez\Cargues\Support\Estados;
use ByLopez\Cargues\Support\Reintento;
use ByLopez\Cargues\Support\ExtensionesTipo;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * P7 — llenado de la intermedia con las filas aceptadas (07 §5.5, §7). Solo si la carga NUNCA inicio una ejecucion
 * (sin `ejecucion_token`): desde ese momento los datos de entrada son inmutables. Verifica la estructura antes de
 * abrir ninguna transaccion de negocio. La restriccion real de la BD es la garantia final: una fila que choque se
 * rechaza con su original intacto en T6; nunca se usa INSERT IGNORE ni se descarta en silencio.
 */
class LlenadoIntermediaService
{
    public function __construct(
        protected ConexionTipo $conexiones,
        protected IntermediaService $intermedia,
        protected ActivarTipoService $activacion,
        protected ProgresoService $progreso,
        protected ExtensionesTipo $extension,
    ) {}

    /** @return array{insertadas:int, rechazadas_bd:int, huella_entrada:string} */
    public function llenar(Carga $carga, string $jobUuid): array
    {
        if ($carga->ejecucion_token) {
            throw new OperacionInvalidaException('ENTRADA_INMUTABLE', 'La carga ya inició una ejecución: no se reconstruye su intermedia.');
        }

        $cfg = (array) $carga->config_congelada;
        $map = (array) $cfg['map'];
        $tabla = $map['tabla'];
        $c = $this->conexiones->conexion($cfg['conexion']);

        $this->verificarEstructura($c, $cfg, $map);

        $this->progreso->avanzar($carga, $jobUuid, 'llenando_intermedia', 0, null, 'Preparando la intermedia', true);

        // Borra SOLO lo de esta carga (la tabla es compartida: nunca TRUNCATE).
        Reintento::tabla(fn () => $this->intermedia->borrarCarga($c, $tabla, $carga->id));

        $total = CargaDato::where('carga_id', $carga->id)->where('estado_final', Estados::FILA_PENDIENTE)->count();
        $insertadas = 0;
        $rechazadasBd = 0;
        $lote = (int) config('cargues.lotes.intermedia_filas', 500);
        $campos = array_column($map['campos'], null, 'nombre');
        $derivados = array_column((array) ($map['derivados'] ?? []), null, 'nombre');
        $extension = $this->extension->de($map);

        CargaDato::where('carga_id', $carga->id)->where('estado_final', Estados::FILA_PENDIENTE)->orderBy('id')
            ->chunkById($lote, function ($datos) use ($carga, $jobUuid, $c, $tabla, $campos, $derivados, $extension, $total, &$insertadas, &$rechazadasBd) {
                $filas = [];
                // La extension del tipo calcula los DERIVADOS del lote (solo lectura de la BD; nunca escribe).
                $calculados = $extension && $derivados ? $extension->derivar($carga, $datos->mapWithKeys(fn ($d) => [$d->id => (array) (json_decode((string) $d->datos_normalizados, true)['valores'] ?? [])])->all()) : [];

                foreach ($datos as $d) {
                    $n = json_decode((string) $d->datos_normalizados, true);
                    $fila = [
                        'cg_carga_id' => $carga->id, 'cg_carga_dato_id' => $d->id, 'cg_fila_origen' => $d->fila_origen, 'cg_duplicado' => 0,
                        'cg_ausentes' => $n['ausentes'] ? json_encode(array_keys($n['ausentes']), JSON_UNESCAPED_UNICODE) : null,
                    ];

                    foreach ($campos as $nombre => $_) {
                        $fila[$nombre] = $n['valores'][$nombre] ?? null;
                    }

                    foreach ($derivados as $nombre => $_) {
                        $fila[$nombre] = $calculados[$d->id][$nombre] ?? null;
                    }

                    $filas[] = $fila;
                }

                $fallidas = $this->insertarLote($c, $tabla, $filas);

                foreach ($fallidas as $f) {
                    $rechazadasBd++;
                    CargaDato::where('id', $f['cg_carga_dato_id'])->update(['estado_final' => Estados::FILA_RECHAZADA, 'detalle' => 'LLAVE_DUPLICADA_BD']);
                    CargaIncidencia::registrar([[
                        'carga_id' => $carga->id, 'dato_id' => $f['cg_carga_dato_id'], 'fila_origen' => $f['cg_fila_origen'], 'etapa' => Estados::ETAPA_VALIDACION_MOTOR,
                        'campo' => '', 'codigo' => 'LLAVE_DUPLICADA_BD', 'motivo' => 'La restricción de la base de datos rechazó la fila ('.$f['error'].').', 'severidad' => 'error', 'created_at' => now(),
                    ]]);
                }

                $insertadas += count($filas) - count($fallidas);
                $this->progreso->avanzar($carga, $jobUuid, 'llenando_intermedia', $insertadas + $rechazadasBd, $total);
            });

        $this->marcarDuplicadosDeIndices($c, $tabla, $carga->id, (array) ($map['llaves']['indices'] ?? []));

        $huella = $this->huellaEntrada($c, $map, $carga->id);

        return ['insertadas' => $insertadas, 'rechazadas_bd' => $rechazadasBd, 'huella_entrada' => $huella];
    }

    /**
     * Huella de los DATOS DE ENTRADA de la carga en la intermedia (no incluye las marcas cg_estado, cg_detalle,
     * cg_codigo, cg_mensaje, cg_procesado_en). Detecta cualquier cambio de entrada, por ejemplo hecho por VALIDAR.
     */
    public function huellaEntrada(Connection $c, array $map, int $cargaId): string
    {
        $partes = ['cg_fila_origen', 'cg_carga_dato_id', 'cg_duplicado', "IFNULL(cg_ausentes,'~')"];

        foreach (Esquema::columnas($map) as $campo) {
            $partes[] = "IFNULL(CAST(`{$campo['nombre']}` AS CHAR),'~')";
        }

        $concat = "CONCAT_WS('\x1F', ".implode(', ', $partes).')';
        $r = Reintento::tabla(fn () => $c->selectOne(
            "SELECT COUNT(*) AS n, IFNULL(BIT_XOR(CRC32({$concat})), 0) AS a, IFNULL(SUM(CRC32(CONCAT({$concat}, 'x'))), 0) AS b FROM `{$map['tabla']}` WHERE cg_carga_id = ?",
            [$cargaId],
        ));

        return hash('sha256', $r->n.'|'.$r->a.'|'.$r->b);
    }

    /** Comprueba que la intermedia exista (la crea si falta) y que coincida con la estructura con la que se configuro el tipo. */
    protected function verificarEstructura(Connection $c, array $cfg, array $map): void
    {
        $tabla = $map['tabla'];

        if ($this->activacion->bloqueada($cfg['conexion'], $tabla)) {
            throw new OperacionInvalidaException('ESTRUCTURA_BLOQUEADA', 'Hay un cambio estructural de la intermedia sin resolver (intervención manual). Las cargas del tipo están bloqueadas.');
        }

        $vigente = Estructura::where('conexion', $cfg['conexion'])->where('nombre_tabla', $tabla)->where('estado', Estructura::ACTIVA)->first();

        if (! $vigente || $vigente->estructura_huella !== ($cfg['estructura_huella'] ?? null)) {
            throw new OperacionInvalidaException('ESTRUCTURA_CAMBIADA', 'La estructura de la intermedia cambió después de configurar esta carga. Cree la carga de nuevo con la versión vigente del tipo.');
        }

        // Se crea ANTES de abrir cualquier transaccion si alguien la borro (07 §5.3).
        if (! $this->intermedia->existe($c, $tabla)) {
            $this->intermedia->crear($c, $map);
        }

        $dif = $this->intermedia->verificar($c, $map);

        if ($dif !== []) {
            throw new OperacionInvalidaException('ESTRUCTURA_ALTERADA', 'La intermedia no coincide con su definición: '.$dif[0], $dif);
        }
    }

    /**
     * Inserta un lote; si la restriccion real choca (1062), repite fila a fila y devuelve las que fallan.
     *
     * @return array<int, array{cg_carga_dato_id:int, cg_fila_origen:int, error:string}>
     */
    protected function insertarLote(Connection $c, string $tabla, array $filas): array
    {
        try {
            Reintento::tabla(fn () => $c->transaction(fn () => $c->table($tabla)->insert($filas)));

            return [];
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }

        $fallidas = [];

        foreach ($filas as $f) {
            try {
                Reintento::tabla(fn () => $c->table($tabla)->insert([$f]));
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }

                $fallidas[] = ['cg_carga_dato_id' => $f['cg_carga_dato_id'], 'cg_fila_origen' => $f['cg_fila_origen'], 'error' => 'duplicado: '.(string) ($e->errorInfo[2] ?? '')];
            }
        }

        return $fallidas;
    }

    /** `cg_duplicado = 1` en las filas que comparten el valor de un indice NO unico (los nombres ya estan validados). */
    protected function marcarDuplicadosDeIndices(Connection $c, string $tabla, int $cargaId, array $indices): void
    {
        foreach ($indices as $campos) {
            $on = implode(' AND ', array_map(fn ($f) => "a.`{$f}` = d.`{$f}`", $campos));
            $cols = implode(', ', array_map(fn ($f) => "`{$f}`", $campos));
            $notNull = implode(' AND ', array_map(fn ($f) => "`{$f}` IS NOT NULL", $campos));

            Reintento::tabla(fn () => $c->update(
                "UPDATE `{$tabla}` a JOIN (SELECT {$cols} FROM `{$tabla}` WHERE cg_carga_id = ? AND {$notNull} GROUP BY {$cols} HAVING COUNT(*) > 1) d ON {$on} SET a.cg_duplicado = 1 WHERE a.cg_carga_id = ?",
                [$cargaId, $cargaId],
            ));
        }
    }
}
