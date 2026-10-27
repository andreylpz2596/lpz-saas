<?php

namespace ByLopez\Logistica\Support;

use ByLopez\Logistica\Contracts\DomiciliarioElegidoEnCarrito;

/** Implementacion por defecto: sin ventas no hay domiciliario elegido. */
class SinDomiciliarioElegido implements DomiciliarioElegidoEnCarrito
{
    public function transportadoraId(int $cartId): ?int
    {
        return null;
    }
}
