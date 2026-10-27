<?php

namespace ByLopez\VentasAsesor\DataObjects;

use ByLopez\VentasAsesor\Enums\EstadoUtilidadLinea;

/**
 * MARGEN COMISIONABLE de una linea vendida (COM-02; «utilidad» en el codigo, D-COST-01). SOLO LECTURA y derivado:
 * no es una comision ni se guarda.
 *
 * - `utilidad*Diagnostica`: con el costo CAPTURADO, exista o no autoridad. Informativa.
 * - `utilidad*Autorizada`: solo con `autoridadComision = true` (costo comisionable oficial, D-COST-02).
 * Sin costo, las utilidades son null (nunca «utilidad = precio»).
 */
final class LineaUtilidad
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $orderItemId,
        public readonly ?string $sku,
        /** Unidades vigentes: qty_ordered − qty_canceled de la linea padre. */
        public readonly int $cantidad,
        /** base_price − base_discount_amount / qty_ordered; sin impuesto ni envio. */
        public readonly ?float $precioNetoUnitario,
        public readonly ?float $costoUnitario,
        public readonly ?string $fuenteCosto,
        public readonly bool $autoridadComision,
        /** max(0, precio neto − costo) por unidad. */
        public readonly ?float $utilidadUnitariaDiagnostica,
        public readonly ?float $utilidadTotalDiagnostica,
        public readonly ?float $utilidadUnitariaAutorizada,
        public readonly ?float $utilidadTotalAutorizada,
        /** Precio neto por debajo del costo (la utilidad se lleva a 0 por unidad, COM-D-07). */
        public readonly bool $margenNegativo,
        public readonly EstadoUtilidadLinea $estado,
    ) {}
}
