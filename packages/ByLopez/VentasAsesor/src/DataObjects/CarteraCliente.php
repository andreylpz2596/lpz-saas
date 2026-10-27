<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Cartera derivada de un cliente (D-59, Doc. 05 §42.3). Solo lectura; no hay deuda guardada (P-03).
 *
 *   cartera   = suma del saldo de sus pedidos en Cartera, sin Refund
 *   porRevisar = suma del saldo de sus pedidos en Cartera CON Refund (se muestra aparte y no oculta la deuda;
 *                tampoco entra en la propuesta FIFO, P-10)
 *
 * `pedidos` trae solo los pedidos en Cartera, del mas antiguo al mas nuevo (el orden de la propuesta FIFO), con
 * su desglose Total · Aplicado · Verificado · Sin revisar · Saldo. Los pedidos fuera de Cartera (historico,
 * Cartera inactiva, cancelados, cerrados o fraude) solo se cuentan en `fueraDeCartera`.
 */
final class CarteraCliente
{
    /**
     * @param  list<SaldoPedido>  $pedidos
     */
    public function __construct(
        public readonly int $customerId,
        public readonly bool $carteraActiva,
        public readonly ?string $fechaCorte,
        public readonly float $carteraTotal,
        public readonly float $porRevisarTotal,
        public readonly array $pedidos,
        public readonly int $fueraDeCartera,
    ) {}

    /** Suma de una cifra de los pedidos en Cartera (incluye los que requieren revision: se muestran, no se ocultan). */
    private function sumar(string $campo): float
    {
        return round(array_sum(array_map(fn (SaldoPedido $p) => (float) ($p->{$campo} ?? 0), $this->pedidos)), 2);
    }

    public function totalPedidos(): float
    {
        return $this->sumar('total');
    }

    public function aplicadoTotal(): float
    {
        return $this->sumar('aplicadoTotal');
    }

    public function sinRevisarTotal(): float
    {
        return $this->sumar('sinRevisar');
    }

    public function verificadoTotal(): float
    {
        return $this->sumar('verificado');
    }

    /** Pedidos en Cartera con saldo pendiente. */
    public function pedidosConSaldo(): int
    {
        return count(array_filter($this->pedidos, fn (SaldoPedido $p) => ($p->saldo ?? 0) > 0));
    }

    /** Pedidos en Cartera que requieren revision humana antes de recibir pagos. */
    public function pedidosPorRevisar(): int
    {
        return count(array_filter($this->pedidos, fn (SaldoPedido $p) => $p->requiereRevision));
    }

    public function toArray(): array
    {
        return [
            'total_pedidos' => $this->totalPedidos(),
            'aplicado_total' => $this->aplicadoTotal(),
            'sin_revisar_total' => $this->sinRevisarTotal(),
            'verificado_total' => $this->verificadoTotal(),
            'pedidos_con_saldo' => $this->pedidosConSaldo(),
            'pedidos_por_revisar' => $this->pedidosPorRevisar(),
            'customer_id' => $this->customerId,
            'cartera_activa' => $this->carteraActiva,
            'fecha_corte' => $this->fechaCorte,
            'cartera_total' => $this->carteraTotal,
            'por_revisar_total' => $this->porRevisarTotal,
            'pedidos' => array_map(fn (SaldoPedido $p) => $p->toArray(), $this->pedidos),
            'fuera_de_cartera' => $this->fueraDeCartera,
        ];
    }
}
