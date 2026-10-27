<?php

namespace ByLopez\Cargues\Services\Cargas;

use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Models\CargaIncidencia;
use ByLopez\Cargues\Support\Estados;
use Illuminate\Support\Facades\DB;

/**
 * Contadores y "puede ejecutar" de una carga. Las dos politicas de errores se calculan aqui y las aplica la puerta de la
 * ejecucion (CargasService::ejecutar, E2). Tras ejecutar, distingue aplicadas, sin cambio, antiguas, observadas (filas
 * aplicadas o no con una observacion del procedimiento, p. ej. una discrepancia de valores) y rechazadas por etapa.
 *   - bloquear_todo: no se ejecuta si hay una sola fila rechazada;
 *   - procesar_validas: se ejecuta solo con la confirmacion explicita del NUMERO exacto de rechazadas.
 */
class ResumenService
{
    public function calcular(Carga $carga): array
    {
        $porEstado = DB::table('bylopez_cg_carga_datos')->where('carga_id', $carga->id)
            ->selectRaw('estado_final, COUNT(*) AS n')->groupBy('estado_final')->pluck('n', 'estado_final')->all();

        $porEtapa = DB::table('bylopez_cg_carga_incidencias')->where('carga_id', $carga->id)->where('severidad', 'error')
            ->selectRaw('etapa, COUNT(DISTINCT fila_origen) AS n')->groupBy('etapa')->pluck('n', 'etapa')->all();

        $porCodigo = CargaIncidencia::where('carga_id', $carga->id)->selectRaw('codigo, severidad, COUNT(*) AS incidencias, COUNT(DISTINCT fila_origen) AS filas')->groupBy('codigo', 'severidad')
            ->orderByDesc('filas')->orderByDesc('incidencias')->limit(50)->get()
            // «filas» = filas afectadas; «incidencias» = avisos registrados (una fila puede tener varias: p. ej. 5 campos obligatorios vacios).
            ->map(fn ($r) => ['codigo' => $r->codigo, 'severidad' => $r->severidad, 'filas' => (int) $r->filas, 'incidencias' => (int) $r->incidencias])->all();

        $total = array_sum($porEstado);
        $vacias = (int) ($porEstado[Estados::FILA_VACIA] ?? 0);
        $rechazadas = (int) ($porEstado[Estados::FILA_RECHAZADA] ?? 0);
        $validas = (int) ($porEstado[Estados::FILA_PENDIENTE] ?? 0);
        $politica = (string) ($carga->config_congelada['politica_errores'] ?? '');

        $puede = false;
        $motivo = null;
        $confirmar = null;

        if ($validas === 0) {
            $motivo = 'No hay filas válidas para ejecutar.';
        } elseif ($politica === 'bloquear_todo' && $rechazadas > 0) {
            $motivo = "La política de este tipo bloquea la ejecución mientras haya filas rechazadas ({$rechazadas}).";
        } elseif ($politica === 'procesar_validas' || $politica === 'bloquear_todo') {
            $puede = true;
            $confirmar = ($politica === 'procesar_validas' && $rechazadas > 0) ? $rechazadas : null;
        } else {
            $motivo = 'El tipo no tiene política de errores.';
        }

        $observadas = (int) DB::table('bylopez_cg_carga_incidencias')->where('carga_id', $carga->id)->where('etapa', Estados::ETAPA_EJECUCION)
            ->where('severidad', 'advertencia')->distinct()->count('fila_origen');

        return [
            'filas_total' => $total, 'filas_vacias' => $vacias, 'filas_validas' => $validas, 'filas_rechazadas' => $rechazadas,
            'rechazadas_motor' => (int) ($porEtapa[Estados::ETAPA_VALIDACION_MOTOR] ?? 0),
            'rechazadas_procedimiento' => (int) ($porEtapa[Estados::ETAPA_VALIDACION_PROCEDIMIENTO] ?? 0),
            // Ejecucion (E2)
            'ejecutada' => in_array($carga->estado, [Estados::CONSOLIDANDO, Estados::COMPLETADA], true),
            'filas_aplicadas' => (int) ($porEstado[Estados::FILA_APLICADA] ?? 0),
            'filas_sin_cambio' => (int) ($porEstado[Estados::FILA_SIN_CAMBIO] ?? 0),
            'filas_omitidas_antiguas' => (int) ($porEstado[Estados::FILA_OMITIDA_ANTIGUA] ?? 0),
            'filas_no_ejecutadas' => (int) ($porEstado[Estados::FILA_NO_EJECUTADA] ?? 0),
            'filas_observadas' => $observadas,
            'rechazadas_ejecucion' => (int) ($porEtapa[Estados::ETAPA_EJECUCION] ?? 0),
            'por_codigo' => $porCodigo,
            'incidencias_total' => array_sum(array_column($porCodigo, 'incidencias')),
            'politica_errores' => $politica,
            'puede_ejecutar' => $puede, 'motivo_bloqueo' => $motivo,
            // Si no es null, "Ejecutar" exige confirmar EXACTAMENTE este numero de filas rechazadas.
            'confirmar_rechazadas' => $confirmar,
        ];
    }
}
