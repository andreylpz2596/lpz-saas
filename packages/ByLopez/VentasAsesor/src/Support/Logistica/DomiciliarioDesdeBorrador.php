<?php

namespace ByLopez\VentasAsesor\Support\Logistica;

use ByLopez\Logistica\Contracts\DomiciliarioElegidoEnCarrito;
use ByLopez\VentasAsesor\Models\BorradorVenta;

/**
 * Domiciliario que el asesor eligio en la venta en construccion: el borrador dueno del carrito. Solo lectura; lo usa el
 * carrier de domicilio local de Logistica para fijar la tarifa de la zona.
 */
class DomiciliarioDesdeBorrador implements DomiciliarioElegidoEnCarrito
{
    public function transportadoraId(int $cartId): ?int
    {
        $id = BorradorVenta::query()->where('cart_id', $cartId)->first(['transportadora_id'])?->transportadora_id;

        return $id === null ? null : (int) $id;
    }
}
