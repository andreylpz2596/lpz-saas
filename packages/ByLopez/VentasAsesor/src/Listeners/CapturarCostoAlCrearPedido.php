<?php

namespace ByLopez\VentasAsesor\Listeners;

use ByLopez\VentasAsesor\Services\Comisiones\CapturarCostoVentaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * COM-01 — enganche del snapshot de costo con la creacion real de pedidos.
 *
 * Corre en `checkout.order.save.after`, DENTRO de la transaccion de OrderRepository::createOrderIfNotThenRetry(),
 * cuando el Order y TODAS sus OrderItems ya existen. Consecuencias:
 *  - cubre todo pedido creado por el repositorio (Nueva venta, panel nativo, tienda): uno de tienda o del panel
 *    puede reasignarse despues a un asesor y tambien necesita su costo congelado;
 *  - si el pedido hace rollback, su snapshot tambien: nunca queda un costo sin pedido.
 *
 * Igual que AtribuirPedidoCreado, NUNCA relanza: si lanzara, el repositorio reintentaria la venta completa o la
 * perderia. Las escrituras van en un savepoint (`DB::transaction` anidada): un fallo del snapshot revierte solo
 * el snapshot y el pedido sigue. Limite honesto: un error que invalide la transaccion entera (conexion caida,
 * deadlock) no se puede contener desde aqui; queda el log. Un pedido sin snapshot se detecta despues con
 * `ventas-asesor:diagnostico-costos`; no se rellena con el costo de hoy (COM-D-13).
 */
class CapturarCostoAlCrearPedido
{
    public const EVENTO = 'checkout.order.save.after';

    public function __construct(protected CapturarCostoVentaService $captura) {}

    public function handle(mixed $pedido): void
    {
        $orderId = is_numeric($pedido->id ?? null) ? (int) $pedido->id : 0;

        if ($orderId <= 0) {
            return;
        }

        try {
            DB::transaction(fn () => $this->captura->capturarPedido($orderId));
        } catch (Throwable $e) {
            try {
                // Campos acotados: nunca el mensaje ni la traza (arrastran SQL con valores).
                Log::warning('ventas_asesor.costo_venta.no_capturado', [
                    'order_id' => $orderId,
                    'excepcion' => $e::class,
                ]);
            } catch (Throwable) {
                // Sin log disponible no hay nada mas que hacer sin arriesgar la venta.
            }
        }
    }
}
