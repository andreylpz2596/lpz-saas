<?php

namespace ByLopez\VentasAsesor\Support;

use Webkul\Customer\Contracts\CustomerGroup;

/**
 * Contexto NORMAL de una operacion comercial: carrito existente + su cliente
 * (validado cart.customer_id == customer_id). Guarda el grupo resuelto la primera
 * vez que se consulta y muere con la operacion.
 *
 * @internal Solo lo usa ContextoPrecioComercial.
 */
final class MarcoPrecioComercial extends MarcoDePrecio
{
    public function __construct(
        public readonly int $cartId,
        int $customerId,
        ?CustomerGroup $grupo = null,
    ) {
        parent::__construct($customerId);

        $this->grupo = $grupo;
    }
}
