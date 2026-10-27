<?php

namespace ByLopez\VentasAsesor\Support\Cartera;

use ByLopez\VentasAsesor\DataObjects\SaldoPedido;

/**
 * Propuesta FIFO de un abono multipedido (D-59 / P-07, Doc. 05 §42.10): el pedido elegible MAS ANTIGUO primero.
 * Es solo una PROPUESTA editable: el servicio de abono exige la distribucion explicita y la revalida entera con el
 * saldo real bajo bloqueo. Aritmetica en centavos enteros para no arrastrar errores de coma flotante.
 *
 * Elegible = pedido en Cartera, con saldo y sin `requiere_revision` (un pedido con Refund, sobreaplicado o con
 * factura pagada sin evidencia no recibe pagos nuevos).
 */
final class DistribucionFifo
{
    public static function esElegible(SaldoPedido $pedido): bool
    {
        return $pedido->enCartera && ! $pedido->requiereRevision && ($pedido->saldo ?? 0) > 0;
    }

    /**
     * @param  list<SaldoPedido>  $pedidos  ya ordenados del mas antiguo al mas nuevo (carteraDelCliente)
     * @return array<int, float> order_id => monto propuesto; solo pedidos con monto > 0 (puede sobrar si el abono supera la deuda)
     */
    public static function proponer(array $pedidos, float $monto): array
    {
        $restante = (int) round($monto * 100);
        $propuesta = [];

        foreach ($pedidos as $pedido) {
            if ($restante <= 0) {
                break;
            }

            if (! self::esElegible($pedido)) {
                continue;
            }

            $aplicar = min($restante, (int) round(((float) $pedido->saldo) * 100));

            if ($aplicar > 0) {
                $propuesta[$pedido->orderId] = round($aplicar / 100, 2);
                $restante -= $aplicar;
            }
        }

        return $propuesta;
    }

    /** Deuda elegible total (tope de un abono: no existe saldo a favor). */
    public static function deudaElegible(array $pedidos): float
    {
        $centavos = 0;

        foreach ($pedidos as $pedido) {
            if (self::esElegible($pedido)) {
                $centavos += (int) round(((float) $pedido->saldo) * 100);
            }
        }

        return round($centavos / 100, 2);
    }
}
