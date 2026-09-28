<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Resultado de confirmar un borrador (03F, Doc. 05 §37.10). Datos de dominio leidos del
 * pedido de Bagisto ya creado; sin Eloquent ni acciones de interfaz.
 *
 * `estadoPago` y `saldo` son DERIVADOS (Doc. 05 §34.6 y §34.11) y no se persisten. 03F no
 * registra dinero: una factura `paid` sin transacciones (p. ej. transferencia con la
 * configuracion actual, D-48 sin desplegar) sale como `revisar`, nunca como cobro verificado.
 */
final class ConfirmacionVenta
{
    public const PAGO_PENDIENTE = 'pendiente';

    public const PAGO_PARCIAL = 'parcial';

    public const PAGO_PAGADO = 'pagado';

    /** Factura `paid` sin transaccion que la respalde: pagado sin evidencia. */
    public const PAGO_REVISAR = 'revisar';

    /** Pedido `canceled`, `closed` o `fraud` (p. ej. al devolver uno ya existente): sin saldo. */
    public const PAGO_NO_APLICA = 'no_aplica';

    /**
     * @param  array{customer_id: int|null, nombre: string}  $cliente
     * @param  array{codigo: string, titulo: string|null}  $metodoPago
     * @param  array{codigo: string|null, titulo: string|null}|null  $entrega
     * @param  array{invoice_id: int, estado: string}|null  $factura
     */
    public function __construct(
        public readonly int $borradorId,
        public readonly int $orderId,
        public readonly string $incrementId,
        public readonly array $cliente,
        public readonly float $total,
        public readonly string $moneda,
        public readonly array $metodoPago,
        public readonly ?array $entrega,
        public readonly ?array $factura,
        public readonly string $estadoPago,
        public readonly float $saldo,
        public readonly bool $yaExistia,
    ) {}

    public function toArray(): array
    {
        return [
            'borrador_id' => $this->borradorId,
            'order_id' => $this->orderId,
            'increment_id' => $this->incrementId,
            'cliente' => $this->cliente,
            'total' => $this->total,
            'moneda' => $this->moneda,
            'metodo_pago' => $this->metodoPago,
            'entrega' => $this->entrega,
            'factura' => $this->factura,
            'estado_pago' => $this->estadoPago,
            'saldo' => $this->saldo,
            'ya_existia' => $this->yaExistia,
        ];
    }
}
