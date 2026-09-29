<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Un producto de primer nivel de una linea de venta = "linea x color" (03G-B). `colorEtiqueta`
 * sale del atributo de color de sus variantes (null si el producto no tiene color propio).
 */
final class ColorDeLinea
{
    public function __construct(
        public readonly int $productId,
        public readonly string $tipo,
        public readonly string $sku,
        public readonly string $nombre,
        public readonly ?int $colorOptionId,
        public readonly ?string $colorEtiqueta,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
