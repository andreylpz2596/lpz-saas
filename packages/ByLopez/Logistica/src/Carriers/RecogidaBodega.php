<?php

namespace ByLopez\Logistica\Carriers;

use ByLopez\Logistica\Support\PuntosRecogida;
use Webkul\Checkout\Facades\Cart;

/**
 * Recogida en bodega (D-40, D-41). Tarifa 0 por naturaleza del metodo (no es una
 * promocion). Solo se ofrece si la direccion de envio del carrito es un punto de
 * recogida real (una fuente de inventario activa): la recogida no se aplica a un
 * domicilio.
 */
class RecogidaBodega extends MetodoEntrega
{
    protected $code = 'recogida_bodega';

    protected $method = 'recogida_bodega';

    public function calculate()
    {
        if (! $this->isAvailable()) {
            return false;
        }

        if (! app(PuntosRecogida::class)->correspondiente(Cart::getCart()?->shipping_address)) {
            return false;
        }

        return $this->tarifa(0);
    }
}
