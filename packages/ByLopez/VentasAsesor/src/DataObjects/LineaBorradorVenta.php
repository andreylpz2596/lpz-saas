<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Una linea del borrador (03C), leida del cart_item de Bagisto. Importes en la moneda del
 * carrito, tal como los calculo Bagisto (precio, total, descuento de reglas, impuesto):
 * aqui no se recalcula nada.
 *
 * - productoId: el producto de primer nivel (configurable o simple sin padre).
 * - varianteId: la variante real (hijo del configurable, o el propio simple).
 * - abastecimiento: desglose de la cantidad de la linea contra el stock vendible ACTUAL
 *   (I-12, D-36). Produccion null = desconocida (D-37). Si el stock bajo despues de
 *   agregar, faltanteInmediato lo muestra.
 */
final class LineaBorradorVenta
{
    /**
     * @param  array<string, array{atributo: ?string, option_id: ?int, etiqueta: ?string}>  $opciones
     */
    public function __construct(
        public readonly int $cartItemId,
        public readonly int $productoId,
        public readonly int $varianteId,
        public readonly string $sku,
        public readonly string $nombre,
        public readonly array $opciones,
        public readonly int $cantidad,
        public readonly float $precioUnitario,
        public readonly float $subtotal,
        public readonly float $descuento,
        public readonly float $impuesto,
        public readonly bool $gestionaStock,
        public readonly int $stockVendible,
        public readonly DesgloseAbastecimiento $abastecimiento,
    ) {}

    public function toArray(): array
    {
        return [...get_object_vars($this), 'abastecimiento' => $this->abastecimiento->toArray()];
    }
}
