<?php

namespace ByLopez\Logistica\Carriers;

use Webkul\Checkout\Models\CartShippingRate;
use Webkul\Shipping\Carriers\AbstractShipping;

/**
 * Metodo de entrega de ByLopez como carrier de Bagisto (D-40). El codigo del carrier y
 * el del metodo coinciden (p. ej. `domicilio_local`): es el valor estable que queda en
 * cart.shipping_method y orders.shipping_method. Titulo y descripcion salen de la
 * configuracion (sales.carriers.<code>.*), como en FlatRate.
 */
abstract class MetodoEntrega extends AbstractShipping
{
    /** Tarifa de Bagisto con la misma forma que FlatRate/Free (moneda base y del canal). */
    protected function tarifa(float $precioBase): CartShippingRate
    {
        $tarifa = new CartShippingRate;

        $tarifa->carrier = $this->getCode();
        $tarifa->carrier_title = $this->getTitle();
        $tarifa->method = $this->getMethod();
        $tarifa->method_title = $this->getTitle();
        $tarifa->method_description = $this->getDescription();
        $tarifa->price = core()->convertPrice($precioBase);
        $tarifa->base_price = $precioBase;

        return $tarifa;
    }
}
