<?php

namespace ByLopez\VentasAsesor\Support\Venta;

use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException;
use ByLopez\VentasAsesor\Support\Clientes\ValidadorGrupoComercial;
use ByLopez\VentasAsesor\Support\ContextoPrecioComercial;
use Closure;
use Webkul\Checkout\Contracts\Cart as CartContract;
use Webkul\Checkout\Facades\Cart;
use Webkul\Customer\Models\CustomerProxy;

/**
 * Operar el Cart de Bagisto de un borrador (03C, 03D): el facade apunta al carrito solo
 * durante la operacion y dentro de RT-03 (cart.customer_id = cliente del contexto), y
 * siempre se suelta al salir, tambien si la operacion lanza.
 */
class CarritoComercial
{
    public function __construct(
        protected ContextoPrecioComercial $contexto,
        protected ValidadorGrupoComercial $validadorGrupo,
    ) {}

    public function operar(CartContract $carrito, int $customerId, Closure $operacion): mixed
    {
        return $this->contexto->durante((int) $carrito->id, $customerId, function () use ($carrito, $operacion) {
            Cart::setCart($carrito);

            try {
                return $operacion();
            } finally {
                Cart::resetCart();
            }
        });
    }

    /**
     * El cliente existe y su grupo ACTUAL es comercial. durante() solo comprueba que el
     * carrito sea del cliente: sin grupo, Bagisto caeria al invitado.
     */
    public function exigirGrupoApto(int $customerId): void
    {
        $cliente = CustomerProxy::modelClass()::query()->find($customerId);

        if (! $cliente) {
            throw new BorradorVentaInvalidoException(BorradorVentaInvalidoException::CLIENTE_INEXISTENTE);
        }

        $this->validadorGrupo->validar($cliente->customer_group_id === null ? null : (int) $cliente->customer_group_id);
    }
}
