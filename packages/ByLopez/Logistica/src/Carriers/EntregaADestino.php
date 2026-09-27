<?php

namespace ByLopez\Logistica\Carriers;

use ByLopez\Logistica\Support\PuntosRecogida;
use Webkul\Checkout\Facades\Cart;

/**
 * Metodo que lleva el pedido a un destino (domicilio local, envio nacional). Tarifa fija
 * por pedido CONFIGURABLE en el panel (sales.carriers.<code>.default_rate), nunca en
 * PHP ni manual por pedido. Sin tarifa configurada valida el metodo NO se ofrece: la
 * tarifa no se inventa. Una promocion de envio gratis la aplica Bagisto con CartRule
 * (free_shipping) sobre esta tarifa, sin cambiar el metodo.
 *
 * A un punto de recogida no se "envia": si la direccion de envio es una bodega, solo
 * aplica la recogida.
 */
abstract class EntregaADestino extends MetodoEntrega
{
    public function calculate()
    {
        if (! $this->isAvailable()) {
            return false;
        }

        if (app(PuntosRecogida::class)->correspondiente(Cart::getCart()?->shipping_address)) {
            return false;
        }

        $valor = $this->getConfigData('default_rate');

        if ($valor === null || $valor === '' || ! is_numeric($valor) || (float) $valor < 0) {
            return false;
        }

        return $this->tarifa((float) $valor);
    }
}
