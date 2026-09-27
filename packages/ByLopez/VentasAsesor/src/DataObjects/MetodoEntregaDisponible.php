<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Una tarifa que Bagisto ofrece para el carrito y su destino (03D), tal como la calculo
 * su carrier (cart_shipping_rates). VentasAsesor no conoce la lista de metodos: la da
 * Shipping::collectRates.
 *
 * `precio`: lo que Bagisto cobra por ese metodo. Una CartRule con envio gratis
 * (free_shipping) pone a 0 el precio de la tarifa ELEGIDA y deja la regla en
 * cart.applied_cart_rule_ids; el metodo no cambia. `descuento`: descuentos de CartRule
 * sobre el envio (apply_to_shipping). Un precio 0 por naturaleza (recogida) o por flete
 * por pagar no es una promocion.
 */
final class MetodoEntregaDisponible
{
    public function __construct(
        /** El valor que se guarda en cart.shipping_method / orders.shipping_method. */
        public readonly string $codigo,
        public readonly string $carrier,
        public readonly string $titulo,
        public readonly ?string $descripcion,
        public readonly float $precio,
        public readonly float $descuento,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
