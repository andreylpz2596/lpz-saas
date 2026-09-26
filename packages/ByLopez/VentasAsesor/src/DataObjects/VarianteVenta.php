<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Variante REAL vendible (03B). Para un configurable: el hijo verificado contra su
 * padre; para un simple sin padre: el propio producto (padreId = productId).
 *
 * - opciones: codigo de atributo => ['atributo' => nombre, 'option_id' => id, 'etiqueta' => texto].
 * - stockVendible: el que usa Bagisto (indice de inventario del canal). Sin produccion.
 * - precioUnitario: calculado por Bagisto para ESE cliente y esa cantidad (RT-03).
 *   Es una vista previa: el precio de la venta es el que calcule el carrito.
 */
final class VarianteVenta
{
    /** @param  array<string, array{atributo: string, option_id: int|null, etiqueta: string|null}>  $opciones */
    public function __construct(
        public readonly int $padreId,
        public readonly int $productId,
        public readonly string $sku,
        public readonly string $nombre,
        public readonly array $opciones,
        public readonly bool $activa,
        public readonly bool $gestionaStock,
        public readonly int $stockVendible,
        public readonly ?float $precioUnitario,
        public readonly int $cantidadConsultada,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
