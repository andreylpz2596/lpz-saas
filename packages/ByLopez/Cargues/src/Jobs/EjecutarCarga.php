<?php

namespace ByLopez\Cargues\Jobs;

use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Services\Cargas\EjecucionService;
use ByLopez\Cargues\Support\Estados;
use Illuminate\Queue\MaxAttemptsExceededException;
use Throwable;

/**
 * E2 — `CALL … 'EJECUTAR'` en segundo plano (cola `cargues`, sin reintentos automaticos). Solo actua si sigue siendo el
 * dueno (`job_uuid`) de una carga `lista`; una entrega duplicada no ejecuta dos veces (CAS + bloqueo con nombre).
 */
class EjecutarCarga extends ProcesoCargaJob
{
    protected function estadoDeFallo(): string
    {
        return Estados::FALLIDA_EJECUCION;
    }

    public function handle(EjecucionService $ejecucion): void
    {
        $ejecucion->correr($this->cargaId, $this->jobUuid);
    }

    /**
     * Si el proceso muere (timeout, kill) NO se adivina el resultado: una carga que aun estaba `lista` no ejecuto nada
     * y queda `fallida_ejecucion`; una que ya estaba `ejecutando`/`consolidando` queda asi, con aviso, para la recuperacion.
     */
    public function failed(Throwable $e): void
    {
        $c = Carga::where('id', $this->cargaId)->where('job_uuid', $this->jobUuid)->first();

        if (! $c) {
            return;
        }

        $reciente = $c->progreso_actualizado_en && $c->progreso_actualizado_en->gt(now()->subSeconds((int) config('cargues.umbral_sin_noticias', 120)));

        if ($e instanceof MaxAttemptsExceededException && $reciente) {
            return; // reentrega mientras el original sigue vivo
        }

        if ($c->estado === Estados::LISTA) {
            Carga::where('id', $c->id)->where('job_uuid', $this->jobUuid)->where('estado', Estados::LISTA)->update([
                'estado' => Estados::FALLIDA_EJECUCION, 'mensaje' => '[PROCESO_INTERRUMPIDO] El proceso terminó antes de ejecutar: no se aplicó nada.', 'finalizada_en' => now(),
            ]);
        } elseif (in_array($c->estado, [Estados::EJECUTANDO, Estados::CONSOLIDANDO], true)) {
            Carga::where('id', $c->id)->where('job_uuid', $this->jobUuid)->where('estado', $c->estado)->update([
                'mensaje' => '[PROCESO_INTERRUMPIDO] El proceso terminó durante la ejecución: el resultado se determina con «Recuperar».',
            ]);
        }
    }
}
