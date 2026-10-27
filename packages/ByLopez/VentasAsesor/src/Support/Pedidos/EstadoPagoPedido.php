<?php

namespace ByLopez\VentasAsesor\Support\Pedidos;

use ByLopez\VentasAsesor\DataObjects\ConfirmacionVenta;
use ByLopez\VentasAsesor\DataObjects\SaldoPedido;

/**
 * Estado de pago DERIVADO de un pedido, sin persistir (Doc. 05 §34.6, §34.11): la misma
 * regla que ConfirmarVentaService::estadoPago(), aplicada a datos ya agregados en lote
 * para un listado (sin una consulta por pedido).
 *
 *  - pagado = Σ transacciones de sus facturas;
 *  - una factura `paid` SIN transacciones cuenta como pagada pero SIN evidencia → «revisar»
 *    (D-48: el metodo puede marcar la factura paid automaticamente; eso no es dinero recibido);
 *  - pedido cancelado, cerrado o fraude → no aplica.
 *
 * Deuda conocida: ConfirmarVentaService (03F, cerrada) conserva su copia de la regla; unificar
 * cuando se reabra 03F, sin cambiar la semantica.
 */
final class EstadoPagoPedido
{
    /**
     * Estado de un pedido DENTRO de Cartera (PAG-C): la fuente es el dominio de Cartera, no las facturas.
     * `pagado` significa «aplicado por completo», no «verificado»: la diferencia entre dinero sin revisar y
     * verificado la da SaldoPedido (sin_revisar / verificado), que la interfaz muestra aparte.
     *
     * @return array{0: string, 1: float} estado (ConfirmacionVenta::PAGO_*) y saldo
     */
    public static function desdeCartera(SaldoPedido $cartera): array
    {
        $saldo = (float) ($cartera->saldo ?? 0);

        $estado = match (true) {
            $cartera->requiereRevision => ConfirmacionVenta::PAGO_REVISAR,
            $cartera->devolucionTotal() && ($cartera->aplicadoTotal ?? 0) <= 0 => ConfirmacionVenta::PAGO_DEVUELTO,
            ($cartera->aplicadoTotal ?? 0) <= 0 => ConfirmacionVenta::PAGO_PENDIENTE,
            $saldo > 0 => ConfirmacionVenta::PAGO_PARCIAL,
            default => ConfirmacionVenta::PAGO_PAGADO,
        };

        return [$estado, $saldo];
    }

    /**
     * @param  list<array{state: string, base_grand_total: float|string, transacciones: float|string}>  $facturas
     * @return array{0: string, 1: float} estado (ConfirmacionVenta::PAGO_*) y saldo
     */
    public static function derivar(string $estadoPedido, float $baseGrandTotal, array $facturas): array
    {
        if (in_array($estadoPedido, ['canceled', 'closed', 'fraud'], true)) {
            return [ConfirmacionVenta::PAGO_NO_APLICA, 0.0];
        }

        $pagado = 0.0;
        $sinEvidencia = false;

        foreach ($facturas as $factura) {
            $transacciones = (float) $factura['transacciones'];

            if ($transacciones > 0) {
                $pagado += $transacciones;
            } elseif ($factura['state'] === 'paid') {
                $pagado += (float) $factura['base_grand_total'];
                $sinEvidencia = true;
            }
        }

        $saldo = max(0.0, round($baseGrandTotal - $pagado, 2));

        $estado = match (true) {
            $sinEvidencia => ConfirmacionVenta::PAGO_REVISAR,
            $pagado <= 0 => ConfirmacionVenta::PAGO_PENDIENTE,
            $saldo > 0 => ConfirmacionVenta::PAGO_PARCIAL,
            default => ConfirmacionVenta::PAGO_PAGADO,
        };

        return [$estado, $saldo];
    }
}
