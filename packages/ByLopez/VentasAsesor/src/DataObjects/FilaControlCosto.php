<?php

namespace ByLopez\VentasAsesor\DataObjects;

use ByLopez\VentasAsesor\Enums\EstadoControlCosto;

/**
 * Una variante en «Control de costos» (D-COST-04/07): costo comisionable de Bagisto vs costo productivo de
 * referencia de CorteConfeccion. Solo lectura.
 */
final class FilaControlCosto
{
    /** @param  list<string>  $componentesFaltantes */
    public function __construct(
        public readonly int $productId,
        public readonly string $sku,
        public readonly string $nombre,
        public readonly ?string $producto,
        public readonly ?int $familiaId,
        public readonly ?string $familia,
        public readonly ?float $costoComisionable,
        /** Costo productivo de referencia (hoy solo tela: parcial). */
        public readonly ?float $costoProductivo,
        public readonly bool $productivoCompleto,
        public readonly array $componentesFaltantes,
        public readonly int $piezasProducidas,
        /** productivo − comisionable */
        public readonly ?float $diferencia,
        public readonly ?float $diferenciaPorcentaje,
        public readonly EstadoControlCosto $estado,
        /** Ultimo cambio registrado por «Control de costos» (la ficha nativa no deja traza). */
        public readonly ?string $ultimoCambio,
    ) {}
}
