<?php

namespace ByLopez\Cargues\Services\Cargas;

use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Models\CargaDato;
use ByLopez\Cargues\Models\CargaIncidencia;
use ByLopez\Cargues\Services\Configuracion\ConexionTipo;
use ByLopez\Cargues\Support\Estados;
use Illuminate\Support\Facades\DB;

/**
 * Consolidacion (07 §3.5, 09 §2.2): refleja en T6/T7 (conexion principal) las marcas que EJECUTAR dejo CONFIRMADAS en la
 * intermedia (conexion del tipo). No ejecuta negocio ni necesita el bloqueo.
 *
 * IDEMPOTENTE y por lotes: solo toca filas de T6 que siguen `pendiente` (las rechazadas en la validacion ya estan
 * consolidadas), y las incidencias se escriben con upsert. Repetirla —por un reintento o una recuperacion— no cambia
 * nada (F1-E5).
 *   - aplicada / sin_cambio / omitida_antigua / rechazada → T6.estado_final y T6.detalle;
 *   - rechazada en ejecucion → incidencia `error` de la etapa `ejecucion`;
 *   - fila NO rechazada con `cg_codigo` → OBSERVACION: incidencia `advertencia` de la etapa `ejecucion` (p. ej. una
 *     discrepancia de valores que el tipo decidio aplicar igual);
 *   - fila `pendiente` sin marca al final → `no_ejecutada`.
 */
class ConsolidacionService
{
    public function __construct(protected ConexionTipo $conexiones) {}

    /** @return array{consolidadas:int, no_ejecutadas:int} */
    public function consolidar(Carga $carga): array
    {
        $cfg = (array) $carga->config_congelada;
        $tabla = (string) $cfg['map']['tabla'];
        $c = $this->conexiones->conexion((string) $cfg['conexion']);
        $lote = (int) config('cargues.lotes.intermedia_filas', 500);
        $desde = 0;
        $consolidadas = 0;

        do {
            $filas = $c->table($tabla)->where('cg_carga_id', $carga->id)->where('cg_id', '>', $desde)->orderBy('cg_id')->limit($lote)
                ->get(['cg_id', 'cg_carga_dato_id', 'cg_fila_origen', 'cg_estado', 'cg_detalle', 'cg_codigo', 'cg_mensaje']);

            if ($filas->isEmpty()) {
                break;
            }

            $desde = (int) $filas->last()->cg_id;
            $consolidadas += $this->lote($carga, $filas->all());
        } while (true);

        // Lo que quede pendiente no fue alcanzado por la ejecucion (no deberia pasar en el modelo `motor`).
        $noEjecutadas = CargaDato::where('carga_id', $carga->id)->where('estado_final', Estados::FILA_PENDIENTE)
            ->update(['estado_final' => Estados::FILA_NO_EJECUTADA, 'detalle' => 'La ejecución no marcó esta fila.']);

        return ['consolidadas' => $consolidadas, 'no_ejecutadas' => $noEjecutadas];
    }

    protected function lote(Carga $carga, array $filas): int
    {
        return DB::transaction(function () use ($carga, $filas) {
            $pendientes = CargaDato::where('carga_id', $carga->id)->whereIn('id', array_map(fn ($f) => (int) $f->cg_carga_dato_id, $filas))
                ->where('estado_final', Estados::FILA_PENDIENTE)->pluck('id')->flip();

            $grupos = [];
            $incidencias = [];

            foreach ($filas as $f) {
                $id = (int) $f->cg_carga_dato_id;

                if (! isset($pendientes[$id]) || ! in_array($f->cg_estado, Estados::MARCAS_EJECUCION, true)) {
                    continue;
                }

                $detalle = $f->cg_detalle !== null ? mb_substr((string) $f->cg_detalle, 0, 255) : null;
                $grupos[$f->cg_estado."\x1F".$detalle][] = $id;

                $rechazada = $f->cg_estado === Estados::FILA_RECHAZADA;

                if ($rechazada || $f->cg_codigo !== null) {
                    $incidencias[] = [
                        'carga_id' => $carga->id, 'dato_id' => $id, 'fila_origen' => (int) $f->cg_fila_origen,
                        'etapa' => Estados::ETAPA_EJECUCION, 'campo' => '',
                        'codigo' => mb_substr((string) ($f->cg_codigo ?: 'RECHAZADA_EN_EJECUCION'), 0, 60),
                        'motivo' => mb_substr((string) ($f->cg_mensaje ?: ($rechazada ? 'El procedimiento rechazó la fila al ejecutar sin indicar el motivo.' : 'Observación del procedimiento.')), 0, 500),
                        'severidad' => $rechazada ? 'error' : 'advertencia', 'created_at' => now(),
                    ];
                }
            }

            foreach ($grupos as $clave => $ids) {
                [$estado, $detalle] = explode("\x1F", $clave, 2);
                CargaDato::where('carga_id', $carga->id)->whereIn('id', $ids)->where('estado_final', Estados::FILA_PENDIENTE)
                    ->update(['estado_final' => $estado, 'detalle' => $detalle === '' ? null : $detalle]);
            }

            if ($incidencias) {
                CargaIncidencia::registrar($incidencias);
            }

            return array_sum(array_map('count', $grupos));
        });
    }
}
