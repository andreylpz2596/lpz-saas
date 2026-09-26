<?php

namespace ByLopez\VentasAsesor\DataObjects;

/** Producto vendible de primer nivel (configurable o simple sin padre). Solo lectura. */
final class ProductoVenta
{
    public function __construct(
        public readonly int $productId,
        public readonly string $tipo,
        public readonly string $sku,
        public readonly string $nombre,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
