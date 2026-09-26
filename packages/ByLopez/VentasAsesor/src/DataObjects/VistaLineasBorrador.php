<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Borrador con sus lineas (03C): la vista de 03B (cliente, avisos P-09, totales de
 * Bagisto) mas las lineas. Contrato de salida; nunca expone modelos Eloquent.
 */
final class VistaLineasBorrador
{
    /** @param  list<LineaBorradorVenta>  $lineas */
    public function __construct(
        public readonly VistaBorradorVenta $borrador,
        public readonly array $lineas,
    ) {}

    /** Todas las lineas se pueden entregar hoy con stock vendible (I-12). */
    public function cubreEntregaInmediata(): bool
    {
        foreach ($this->lineas as $linea) {
            if (! $linea->abastecimiento->cubreEntregaInmediata()) {
                return false;
            }
        }

        return true;
    }

    public function linea(int $cartItemId): ?LineaBorradorVenta
    {
        foreach ($this->lineas as $linea) {
            if ($linea->cartItemId === $cartItemId) {
                return $linea;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'borrador' => get_object_vars($this->borrador),
            'lineas' => array_map(fn (LineaBorradorVenta $l) => $l->toArray(), $this->lineas),
            'cubre_entrega_inmediata' => $this->cubreEntregaInmediata(),
        ];
    }
}
