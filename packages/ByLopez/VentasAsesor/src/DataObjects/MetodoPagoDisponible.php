<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Un metodo de pago ofrecido al borrador (03E): disponible segun Bagisto (Payment::
 * getPaymentMethods()) Y permitido por la allowlist de VentasAsesor (D-49). Nunca solo
 * uno de los dos.
 *
 * `estadoInicialEsperado` es INFORMATIVO: describe que ocurrira si se confirma el pedido
 * con este metodo, segun la configuracion VIGENTE de Bagisto (sales.payment_methods.<code>.
 * generate_invoice/invoice_status/order_status). No es una promesa de VentasAsesor ni
 * cambia esa configuracion; D-48 (transferencia pendiente hasta confirmar el dinero) sigue
 * pendiente de desplegarse en produccion, asi que hoy puede reflejar "pago_confirmado" para
 * moneytransfer aunque D-48 diga que deberia ser pendiente.
 */
final class MetodoPagoDisponible
{
    public const ESTADO_PAGO_PENDIENTE = 'pago_pendiente';

    public const ESTADO_PAGO_CONFIRMADO = 'pago_confirmado';

    /** Sin factura automatica (generate_invoice = 0): no hay estado de pago que informar. */
    public const ESTADO_SIN_FACTURA_AUTOMATICA = 'sin_factura_automatica';

    public function __construct(
        public readonly string $codigo,
        public readonly string $titulo,
        public readonly ?string $descripcion,
        public readonly string $estadoInicialEsperado,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
