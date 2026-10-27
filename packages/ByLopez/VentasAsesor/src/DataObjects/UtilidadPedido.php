<?php

namespace ByLopez\VentasAsesor\DataObjects;

use ByLopez\VentasAsesor\Enums\EstadoUtilidadLinea;

/**
 * Utilidad de un pedido (COM-02): sus lineas y totales derivados. SOLO LECTURA.
 *
 * `utilidadAutorizada` solo existe si TODAS las lineas con unidades estan AUTORIZADAS; un total parcial podria
 * leerse como completo.
 */
final class UtilidadPedido
{
    /** @param  list<LineaUtilidad>  $lineas */
    public function __construct(
        public readonly int $orderId,
        public readonly array $lineas,
    ) {}

    /** Suma de las lineas con utilidad (diagnostica o autorizada); las sin costo quedan fuera. */
    public function utilidadDiagnostica(): float
    {
        return round(array_sum(array_map(fn (LineaUtilidad $l) => $l->utilidadTotalDiagnostica ?? 0.0, $this->lineas)), 2);
    }

    public function utilidadAutorizada(): ?float
    {
        $conUnidades = array_filter($this->lineas, fn (LineaUtilidad $l) => $l->estado !== EstadoUtilidadLinea::SIN_CANTIDAD);

        if ($conUnidades === [] || array_filter($conUnidades, fn (LineaUtilidad $l) => $l->estado !== EstadoUtilidadLinea::AUTORIZADA) !== []) {
            return null;
        }

        return round(array_sum(array_map(fn (LineaUtilidad $l) => (float) $l->utilidadTotalAutorizada, $conUnidades)), 2);
    }

    public function lineasSinCosto(): int
    {
        return count(array_filter($this->lineas, fn (LineaUtilidad $l) => in_array($l->estado, [EstadoUtilidadLinea::SIN_COSTO, EstadoUtilidadLinea::SIN_SNAPSHOT], true)));
    }
}
