<?php

namespace ByLopez\VentasAsesor\Jobs;

use ByLopez\VentasAsesor\Services\Comisiones\ReevaluacionComisionesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Doc. 23 §10.2 — reevalua los pedidos marcados en la bandeja durable, DESPUES del commit del hecho que los marco.
 *
 * `tries = 1`: el reintento NO es de la cola sino de la bandeja (cada pedido guarda sus intentos y su proxima espera) y lo
 * toma `ventas-asesor:comisiones:evaluar --bandeja` (programado cada 5 minutos). Nunca lanza: con la cola `sync` corre en
 * la misma peticion del hecho, que ya quedo confirmado y no debe ver un error de comisiones.
 */
class ReevaluarComisionesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /** @param  list<int>  $orderIds */
    public function __construct(public array $orderIds) {}

    public function handle(ReevaluacionComisionesService $servicio): void
    {
        try {
            $servicio->procesar($this->orderIds);
        } catch (Throwable $e) {
            try {
                Log::warning('ventas_asesor.comisiones.reevaluacion.job_fallido', ['pedidos' => array_slice($this->orderIds, 0, 20), 'excepcion' => $e::class]);
            } catch (Throwable) {
                // Sin log disponible: los pedidos siguen pendientes en la bandeja.
            }
        }
    }
}
