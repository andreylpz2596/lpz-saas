<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Borrador con sus solicitudes comerciales (03C, D-39). Los totales de `borrador` son
 * los REALES del Cart de Bagisto; aqui no hay "total solicitado" teorico.
 */
final class VistaSolicitudesBorrador
{
    /** @param  list<SolicitudBorradorVenta>  $solicitudes */
    public function __construct(
        public readonly VistaBorradorVenta $borrador,
        public readonly array $solicitudes,
    ) {}

    public function solicitud(int $varianteId): ?SolicitudBorradorVenta
    {
        foreach ($this->solicitudes as $solicitud) {
            if ($solicitud->varianteId === $varianteId) {
                return $solicitud;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'borrador' => get_object_vars($this->borrador),
            'solicitudes' => array_map(fn (SolicitudBorradorVenta $s) => $s->toArray(), $this->solicitudes),
        ];
    }
}
