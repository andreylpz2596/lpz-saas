<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Composicion del pago y saldo comercial de UN pedido (D-59, Doc. 05 §42.3). Solo lectura: se DERIVA al
 * consultar, nunca se guarda (P-03). Moneda base, redondeo a 2 decimales.
 *
 *   aplicado_total = aplicaciones ByLopez activas + Transactions nativas ajenas a ByLopez
 *   aplicado_total = verificado + sin_revisar
 *   saldo          = max(0, total - devuelto - aplicado_total)       ← «saldo real por cobrar»
 *
 * C5 · DEVOLUCIONES: `devuelto` = valor historico de lo que Bodega RECIBIO fisicamente (aptas y defectuosas; no los
 * faltantes), derivado de las recepciones confirmadas. El pedido original no cambia: `total` sigue siendo el original y
 * `totalOperativo` (total − devuelto) lo que realmente se exige. `saldoAFavor` = lo pagado por encima de `totalOperativo`
 * que se explica por devoluciones; NO es un reembolso ni un pago aplicable (eso es D10): solo queda identificado. *
 * Si el pedido esta FUERA de Cartera (`enCartera = false`) solo se informa el total y el motivo: Cartera no
 * calcula saldos para el historico, ni cuando esta inactiva, ni para pedidos cancelados, cerrados o fraude.
 *
 * `requiereRevision` marca casos que Cartera no resuelve sola (P-10: Refund; factura pagada sin evidencia;
 * sobreaplicacion; aplicacion activa en un pago ya cerrado). La resolucion es humana, nunca automatica.
 */
final class SaldoPedido
{
    public const FUERA_CARTERA_INACTIVA = 'cartera_inactiva';

    public const FUERA_ANTERIOR_A_CORTE = 'anterior_a_fecha_corte';

    public const FUERA_ESTADO_NO_APLICA = 'estado_no_aplica';

    public const REVISAR_REEMBOLSO = 'pedido_con_reembolso';

    public const REVISAR_FACTURA_PAGADA_SIN_EVIDENCIA = 'factura_pagada_sin_evidencia';

    public const REVISAR_SOBREAPLICADO = 'sobreaplicado';

    public const REVISAR_APLICACION_EN_PAGO_CERRADO = 'aplicacion_activa_en_pago_cerrado';

    /**
     * @param  list<string>  $motivosRevision
     */
    public function __construct(
        public readonly int $orderId,
        public readonly ?int $customerId,
        public readonly string $incrementId,
        public readonly string $estadoPedido,
        public readonly float $total,
        public readonly bool $enCartera,
        public readonly ?string $motivoFuera,
        public readonly ?float $aplicadoTotal,
        public readonly ?float $verificado,
        public readonly ?float $sinRevisar,
        public readonly ?float $transaccionesAjenas,
        public readonly ?float $saldo,
        public readonly bool $requiereRevision,
        public readonly array $motivosRevision,
        public readonly float $devuelto = 0.0,
        public readonly int $unidadesDevueltas = 0,
        public readonly ?float $totalOperativo = null,
        public readonly float $saldoAFavor = 0.0,
    ) {}

    /** Pedido fuera de Cartera: solo total y motivo. */
    public static function fuera(int $orderId, ?int $customerId, string $incrementId, string $estadoPedido, float $total, string $motivo): self
    {
        return new self($orderId, $customerId, $incrementId, $estadoPedido, $total, false, $motivo, null, null, null, null, null, false, []);
    }

    /**
     * C5: la mercancia recibida de vuelta cubre TODA la obligacion del pedido (total operativo = 0). Se deriva de los
     * datos de Cartera; no es un Refund ni un reembolso, y no implica que se haya devuelto dinero.
     */
    public function devolucionTotal(): bool
    {
        return $this->enCartera && $this->devuelto > 0 && ($this->totalOperativo ?? $this->total) <= 0;
    }

    public function tieneMotivo(string $motivo): bool
    {
        return in_array($motivo, $this->motivosRevision, true);
    }

    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'customer_id' => $this->customerId,
            'increment_id' => $this->incrementId,
            'estado_pedido' => $this->estadoPedido,
            'total' => $this->total,
            'en_cartera' => $this->enCartera,
            'motivo_fuera' => $this->motivoFuera,
            'aplicado_total' => $this->aplicadoTotal,
            'verificado' => $this->verificado,
            'sin_revisar' => $this->sinRevisar,
            'transacciones_ajenas' => $this->transaccionesAjenas,
            'saldo' => $this->saldo,
            'requiere_revision' => $this->requiereRevision,
            'motivos_revision' => $this->motivosRevision,
            'devuelto' => $this->devuelto,
            'unidades_devueltas' => $this->unidadesDevueltas,
            'total_operativo' => $this->totalOperativo ?? $this->total,
            'saldo_a_favor' => $this->saldoAFavor,
            'devolucion_total' => $this->devolucionTotal(),
        ];
    }
}
