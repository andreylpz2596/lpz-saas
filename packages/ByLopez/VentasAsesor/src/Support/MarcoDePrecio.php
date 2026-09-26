<?php

namespace ByLopez\VentasAsesor\Support;

use Webkul\Customer\Contracts\CustomerGroup;

/**
 * Base comun de los dos tipos de contexto de precio. No se instancia: solo existen
 * MarcoPrecioComercial (carrito + cliente) y MarcoCreacionDeCarrito (cliente cuyo
 * carrito aun no existe). Asi "carrito nulo" nunca es un estado ambiguo.
 *
 * @internal Solo lo usa ContextoPrecioComercial.
 */
abstract class MarcoDePrecio
{
    public ?CustomerGroup $grupo = null;

    public function __construct(public readonly int $customerId) {}
}
