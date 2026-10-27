<?php

namespace ByLopez\Cargues\Services\Cargas;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Support\Estados;
use Illuminate\Support\Facades\DB;

/**
 * Progreso consultable (09 §2.1). Lo escribe el job en T5 FUERA de la transaccion del lote (siempre despues de su
 * COMMIT) y solo si el job sigue siendo el dueno de la carga (`job_uuid`): un job zombi que fue reemplazado por un
 * reintento deja de escribir y aborta.
 */
class ProgresoService
{
    protected array $ultimo = [];

    /**
     * @param  bool  $forzar  escribe aunque no haya pasado el umbral de filas/segundos
     */
    public function avanzar(Carga $carga, string $jobUuid, string $etapa, int $procesadas, ?int $total, ?string $mensaje = null, bool $forzar = false): void
    {
        $k = $carga->id;
        $ahora = microtime(true);
        $u = $this->ultimo[$k] ?? ['t' => 0, 'n' => 0, 'etapa' => null];
        $cadaFilas = (int) config('cargues.lotes.progreso_cada_filas', 500);
        $cadaSeg = (float) config('cargues.lotes.progreso_cada_segundos', 2);

        if (! $forzar && $u['etapa'] === $etapa && ($procesadas - $u['n']) < $cadaFilas && ($ahora - $u['t']) < $cadaSeg) {
            return;
        }

        $n = Carga::where('id', $carga->id)->where('job_uuid', $jobUuid)->update([
            'etapa' => $etapa, 'filas_procesadas' => $procesadas, 'filas_total' => $total ?? DB::raw('filas_total'),
            'progreso_mensaje' => $mensaje, 'progreso_actualizado_en' => now(),
        ]);

        if ($n === 0) {
            throw new OperacionInvalidaException('JOB_SUPERADO', 'Otro proceso tomó esta carga; este se detiene.');
        }

        $this->ultimo[$k] = ['t' => $ahora, 'n' => $procesadas, 'etapa' => $etapa];
    }

    /** Estado consultable por la UI (JSON). */
    public function consultar(Carga $carga): array
    {
        $carga->refresh();
        $umbral = (int) config('cargues.umbral_sin_noticias', 120);
        $enCurso = in_array($carga->estado, Estados::EN_CURSO, true);
        $enEjecucion = in_array($carga->estado, Estados::EN_EJECUCION, true);
        $enCola = $carga->estado === Estados::EN_COLA || ($carga->estado === Estados::PREPARANDO && $carga->etapa === 'en_cola_2');
        $ref = $carga->progreso_actualizado_en ?? $carga->encolada_en;
        $segundos = $ref ? max(0, (int) now()->diffInSeconds($ref, true)) : null;

        // El CALL del procedimiento es UNA sola sentencia: no avanza fila a fila; solo se vigila contra el timeout del job.
        $limite = in_array($carga->etapa, ['procedimiento'], true) || $carga->estado === Estados::EJECUTANDO ? (int) config('cargues.cola.timeout', 900) : $umbral;
        $sinNoticias = (($enCurso || $enEjecucion) && $segundos !== null && $segundos > $limite);

        $total = $carga->filas_total;
        $pct = $total ? min(100, (int) floor(100 * $carga->filas_procesadas / max(1, $total))) : null;

        return [
            'id' => $carga->id, 'uuid' => $carga->uuid,
            'estado' => $carga->estado, 'etapa' => $carga->etapa,
            'filas_procesadas' => (int) $carga->filas_procesadas, 'filas_total' => $total === null ? null : (int) $total,
            'porcentaje' => $pct, 'mensaje' => $carga->progreso_mensaje ?: $carga->mensaje,
            'actualizado_en' => $carga->progreso_actualizado_en?->toIso8601String(),
            'segundos_sin_avance' => $segundos,
            'en_cola' => $enCola, 'en_cola_segundos' => $enCola ? $segundos : null,
            'en_curso' => $enCurso || $enCola || $enEjecucion, 'sin_noticias' => $sinNoticias, 'en_ejecucion' => $enEjecucion,
            'terminal' => ! ($enCurso || $enCola || $enEjecucion) && $carga->estado !== Estados::RECIBIDA,
            'puede_reintentar' => ($sinNoticias && ! $enEjecucion) || in_array($carga->estado, [Estados::FALLIDA_LECTURA, Estados::FALLIDA_PREPARACION, Estados::FALLIDA_VALIDACION], true),
            // Ejecucion interrumpida: solo la recuperacion clasifica (nunca un reintento ciego). Tambien si el job dejo aviso.
            'puede_recuperar' => $enEjecucion && ($sinNoticias || str_starts_with((string) $carga->mensaje, '[')),
        ];
    }
}
