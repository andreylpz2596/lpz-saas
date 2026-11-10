<?php

namespace ByLopez\VentasAsesor\Support\Logistica;

use ByLopez\Logistica\Contracts\GuardaAnulacionRecepcion;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\VentasAsesor\Services\Credito\CreditoClienteService;
use Illuminate\Support\Facades\DB;

/**
 * D10 V1 — anular una recepcion C5 no puede dejar credito CONSUMIDO sin respaldo (disponible negativo). Bloquea el pedido origen
 * (mismo orden que las operaciones de credito) y, con la recepcion ya anulada, recalcula generado y usado con lecturas bloqueantes.
 * No reversa nada solo: si quedaria negativo, rechaza y el administrador regulariza primero (reversar la aplicacion).
 */
class GuardaAnulacionRecepcionConCredito implements GuardaAnulacionRecepcion
{
    public function __construct(protected CreditoClienteService $credito) {}

    public function antes(int $orderId): void
    {
        DB::table('orders')->where('id', $orderId)->lockForUpdate()->value('id');
    }

    public function despues(int $orderId): void
    {
        $c = $this->credito->dePedido($orderId, true);

        if ($c['usado'] > $c['generado'] + 0.004) {
            throw new Invalida('recepcion_con_credito_consumido', ['pedido' => $orderId, 'generado' => number_format($c['generado'], 0, ',', '.'), 'usado' => number_format($c['usado'], 0, ',', '.')]);
        }
    }
}
