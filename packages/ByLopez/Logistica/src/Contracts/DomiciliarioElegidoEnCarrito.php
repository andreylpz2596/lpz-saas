<?php

namespace ByLopez\Logistica\Contracts;

/**
 * Domiciliario que el asesor eligio en la venta en construccion (borrador) para un carrito. Lo usa el carrier de domicilio
 * local para fijar la tarifa de la zona. Logistica trae una implementacion sin eleccion; VentasAsesor, dueno del borrador,
 * la reemplaza.
 */
interface DomiciliarioElegidoEnCarrito
{
    public function transportadoraId(int $cartId): ?int;
}
