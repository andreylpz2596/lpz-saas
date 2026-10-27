<?php

namespace ByLopez\Cargues\Jobs;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Support\Estados;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base de los jobs de Cargues (cola DEDICADA `cargues`, 09 §2.1):
 *  - `tries = 1`: NINGUN reintento automatico (la reanudacion es explicita y la decide el administrador);
 *  - `timeout` configurable y `failOnTimeout`;
 *  - el job solo actua si sigue siendo el dueno de la carga (`job_uuid`): una entrega duplicada, o un job superado por un
 *    reintento o una cancelacion, termina sin tocar nada (F1-B4).
 * La conexion de cola `cargues` debe tener `retry_after` MAYOR que el timeout (se verifica en `cargues:diagnostico`).
 */
abstract class ProcesoCargaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public int $cargaId, public string $jobUuid)
    {
        $this->timeout = (int) config('cargues.cola.timeout', 900);
        $this->onConnection(config('cargues.cola.conexion'));
        $this->onQueue(config('cargues.cola.nombre'));
    }

    /** Estado de fallo si el job muere sin poder manejarlo (timeout, kill). */
    abstract protected function estadoDeFallo(): string;

    public function failed(Throwable $e): void
    {
        // Una REENTREGA (retry_after vencido) llega con MaxAttemptsExceeded aunque el job original siga trabajando: si la
        // carga muestra progreso reciente, el original esta vivo y la reentrega NO debe marcar la carga como fallida.
        if ($e instanceof MaxAttemptsExceededException && $this->sigueVivo()) {
            return;
        }

        $this->marcarFallo($e instanceof OperacionInvalidaException ? $e : new OperacionInvalidaException('JOB_FALLIDO', 'El proceso terminó de forma inesperada: '.mb_substr($e->getMessage(), 0, 300)));
    }

    /** ¿El job dueno de la carga sigue avanzando? (progreso escrito dentro del umbral de "sin noticias"). */
    protected function sigueVivo(): bool
    {
        $c = Carga::where('id', $this->cargaId)->where('job_uuid', $this->jobUuid)->first();

        return $c !== null
            && in_array($c->estado, [Estados::EN_COLA, ...Estados::EN_CURSO], true)
            && $c->progreso_actualizado_en !== null
            && $c->progreso_actualizado_en->gt(now()->subSeconds((int) config('cargues.umbral_sin_noticias', 120)));
    }

    protected function marcarFallo(OperacionInvalidaException $e): void
    {
        Carga::where('id', $this->cargaId)->where('job_uuid', $this->jobUuid)->whereIn('estado', [Estados::EN_COLA, ...Estados::EN_CURSO])->update([
            'estado' => $this->estadoDeFallo(), 'mensaje' => '['.$e->codigo.'] '.$e->getMessage(), 'progreso_mensaje' => null,
            'resultado' => json_encode(['error' => ['codigo' => $e->codigo, 'detalles' => $e->detalles]], JSON_UNESCAPED_UNICODE),
            'finalizada_en' => now(),
        ]);
    }

    /** Ejecuta `$trabajo` convirtiendo cualquier excepcion en un estado de fallo de la carga. */
    protected function protegido(callable $trabajo): void
    {
        try {
            $trabajo();
        } catch (OperacionInvalidaException $e) {
            if ($e->codigo === 'JOB_SUPERADO') {
                return; // otro proceso es ahora el dueno: no se toca nada
            }

            $this->marcarFallo($e);
        } catch (Throwable $e) {
            report($e);
            Log::error('Cargues: error inesperado en '.static::class.' (carga '.$this->cargaId.'): '.$e->getMessage());
            $this->marcarFallo(new OperacionInvalidaException('ERROR_INESPERADO', 'Error inesperado ('.class_basename($e).'). Revise el registro del servidor.'));
        }
    }
}
