<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Una solicitud comercial del borrador (03C, D-39), resuelta en tiempo real.
 *
 * - cantidadSolicitada: lo que el cliente QUIERE (persistido).
 * - cantidadEnCarrito: unidades de esa variante hoy en el Cart de Bagisto. NO es una
 *   reserva: Bagisto solo reserva al crear el pedido, y la confirmacion revalidara.
 * - abastecimiento: desglose de lo SOLICITADO contra el stock vendible actual (I-12,
 *   D-36). Nunca depende de lo que haya en el carrito. Produccion null = desconocida (D-37).
 * - excesoCarritoSobreStock: unidades del carrito que el stock vendible actual ya no
 *   cubre (p. ej. carrito 30, stock bajo a 20 → 10). Se informa; no se toca nada.
 * - precioUnitarioReferencia: precio de Bagisto para ESTE cliente (grupo actual, RT-03) y
 *   la cantidad solicitada. Informativo: no se guarda ni es autoridad. null si la variante
 *   no es vendible o el cliente no tiene grupo comercial apto.
 */
final class SolicitudBorradorVenta
{
    /**
     * @param  array<string, array{atributo: string, option_id: ?int, etiqueta: ?string}>  $opciones
     */
    public function __construct(
        public readonly int $solicitudId,
        public readonly int $productoId,
        public readonly int $varianteId,
        public readonly string $sku,
        public readonly string $nombre,
        public readonly array $opciones,
        public readonly bool $vendible,
        public readonly int $cantidadSolicitada,
        public readonly int $cantidadEnCarrito,
        public readonly bool $gestionaStock,
        public readonly int $stockVendible,
        public readonly DesgloseAbastecimiento $abastecimiento,
        public readonly int $excesoCarritoSobreStock,
        public readonly ?float $precioUnitarioReferencia,
    ) {}

    public function toArray(): array
    {
        return [...get_object_vars($this), 'abastecimiento' => $this->abastecimiento->toArray()];
    }
}
