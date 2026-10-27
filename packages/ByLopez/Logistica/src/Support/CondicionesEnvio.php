<?php

namespace ByLopez\Logistica\Support;

use ByLopez\Logistica\Enums\CondicionEnvio;

/**
 * Deduce la condicion comercial del envio de un pedido desde `Config/condiciones_envio.php` (por CODIGO de metodo).
 * Unica excepcion: una CartRule de envio gratis (Bagisto deja `base_shipping_discount_amount` igual a la tarifa) hace
 * gratis el envio aunque el metodo tenga tarifa. Nunca se deduce del titulo ni de un monto 0.
 */
class CondicionesEnvio
{
    public function deOrden(object $pedido): CondicionEnvio
    {
        $metodo = (string) ($pedido->shipping_method ?? '');
        $tarifa = (float) ($pedido->base_shipping_amount ?? 0);
        $descuento = (float) ($pedido->base_shipping_discount_amount ?? 0);

        if ($metodo !== PuntosRecogida::METODO && $tarifa > 0 && $descuento >= $tarifa - 0.001) {
            return CondicionEnvio::GRATIS;
        }

        $condicion = config('logistica.condiciones_envio.'.$metodo);

        return is_string($condicion) ? (CondicionEnvio::tryFrom($condicion) ?? CondicionEnvio::SIN_DEFINIR) : CondicionEnvio::SIN_DEFINIR;
    }
}
