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
        public readonly ?string $colorSwatch = null,
        public readonly bool $disponible = true,
        public readonly int $tallasConStock = 0,
        public readonly int $tallasTotal = 0,
    ) {}

    /**
     * Anticipo para el selector de color: `disponible` = el producto se puede vender y al menos una talla esta activa y con
     * stock (la MISMA regla con la que la matriz habilita una talla). Solo informa; la matriz y el carrito siguen validando.
     *
     * @param  array{vendible: bool, tallas_total: int, tallas_con_stock: int}  $d
     */
    public function conDisponibilidad(array $d): self
    {
        return new self(
            $this->productId, $this->tipo, $this->sku, $this->nombre, $this->colorOptionId, $this->colorEtiqueta, $this->colorSwatch,
            $d['vendible'] && $d['tallas_con_stock'] > 0, $d['tallas_con_stock'], $d['tallas_total'],
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
