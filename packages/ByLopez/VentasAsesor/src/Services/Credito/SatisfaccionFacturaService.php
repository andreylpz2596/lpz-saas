<?php

namespace ByLopez\VentasAsesor\Services\Credito;

use Illuminate\Support\Facades\DB;
use Webkul\Sales\Models\Invoice;
use Webkul\Sales\Models\OrderProxy;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Repositories\OrderRepository;

/**
 * D10 V1 — «factura completamente satisfecha»: dinero real (Transactions de la factura) + compensacion con credito ACTIVA >= total
 * de la factura. `invoice.state = paid` NO significa «todo fue dinero recibido»: los reportes financieros distinguen dinero real de
 * compensacion con `SaldoPedido::creditoAplicado`.
 *
 * El credito NO crea Transaction (decision PAG-I), por eso Bagisto jamas llegaria a `paid` solo con Transactions. Este servicio
 * cierra esa brecha SIN modificar Webkul: usa los mismos repositorios nativos que ConfirmarPagoService (`updateState` y
 * `updateOrderStatus` sin estado explicito). Idempotente y sin escrituras si nada cambia.
 *
 * Al reversar el credito, una factura que solo estaba cubierta gracias a el vuelve a `pending`. El estado del PEDIDO lo deriva
 * Bagisto de cantidades y nunca regresa de `processing` a `pending_payment`: limitacion nativa; la verdad financiera sigue en
 * Cartera (saldo) y en el credito, no en `orders.status`.
 *
 * Debe llamarse DENTRO de la transaccion de la operacion, con el pedido ya bloqueado.
 */
class SatisfaccionFacturaService
{
    private const TOLERANCIA = 0.0001;

    public function __construct(
        protected CreditoClienteService $credito,
        protected InvoiceRepository $facturas,
        protected OrderRepository $pedidos,
    ) {}

    /** @return array{pagadas: list<int>, revertidas: list<int>} facturas que cambiaron de estado */
    public function sincronizar(int $orderId): array
    {
        $cambio = ['pagadas' => [], 'revertidas' => []];
        $facturas = DB::table('invoices')->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get(['id', 'state', 'base_grand_total']);

        if ($facturas->isEmpty()) {
            return $cambio;
        }

        // Compensacion = credito aplicado ACTIVO + ajustes de obligacion ACTIVOS (ambos reducen lo que la factura exige en dinero real).
        $ajustes = (float) DB::table('bylopez_va_ajustes_obligacion')->where('order_id', $orderId)->where('estado', 'activo')->sharedLock()->sum('monto');
        $creditoActivo = $this->credito->aplicadoActivoEnDestino($orderId, true) + $ajustes;
        // Solo se revierte una factura `paid` si este pedido uso credito/ajustes alguna vez (nunca se toca una `paid` historica sin evidencia).
        $tuvoCredito = $creditoActivo > 0
            || DB::table('bylopez_va_creditos_cliente_movimientos')->where('order_destino_id', $orderId)->sharedLock()->exists()
            || DB::table('bylopez_va_ajustes_obligacion')->where('order_id', $orderId)->sharedLock()->exists();
        $real = DB::table('order_transactions')->whereIn('invoice_id', $facturas->pluck('id'))->sharedLock()
            ->groupBy('invoice_id')->selectRaw('invoice_id, SUM(amount) AS total')->pluck('total', 'invoice_id');
        $restante = $creditoActivo;

        foreach ($facturas as $factura) {
            $total = (float) $factura->base_grand_total;
            $dinero = (float) ($real[$factura->id] ?? 0);
            $asignado = min($restante, max(0.0, $total - $dinero));
            $restante -= $asignado;
            $cubierta = $total > 0 && $dinero + $asignado >= $total - self::TOLERANCIA;

            if ($cubierta && $factura->state !== Invoice::STATUS_PAID) {
                $this->facturas->updateState(Invoice::query()->findOrFail($factura->id), Invoice::STATUS_PAID);
                $cambio['pagadas'][] = (int) $factura->id;
            } elseif (! $cubierta && $factura->state === Invoice::STATUS_PAID && $tuvoCredito && $dinero < $total - self::TOLERANCIA) {
                $this->facturas->updateState(Invoice::query()->findOrFail($factura->id), Invoice::STATUS_PENDING);
                $cambio['revertidas'][] = (int) $factura->id;
            }
        }

        if ($cambio['pagadas'] !== []) {
            $this->pedidos->updateOrderStatus(OrderProxy::modelClass()::query()->findOrFail($orderId));
        }

        return $cambio;
    }
}
