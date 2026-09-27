<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Pago del borrador (03E): metodos ofrecidos (Bagisto ∩ allowlist) y el elegido, leido
 * de cart_payment. Los totales reales siguen en `borrador->totales` (Bagisto); esto NO
 * representa dinero recibido (D-50/D-53, fuera de 03E).
 */
final class VistaPagoBorrador
{
    /** @param  list<MetodoPagoDisponible>  $metodosDisponibles */
    public function __construct(
        public readonly VistaBorradorVenta $borrador,
        public readonly array $metodosDisponibles,
        public readonly ?MetodoPagoDisponible $metodoSeleccionado,
    ) {}

    public function toArray(): array
    {
        return [
            'borrador' => get_object_vars($this->borrador),
            'metodos_disponibles' => array_map(fn (MetodoPagoDisponible $m) => $m->toArray(), $this->metodosDisponibles),
            'metodo_seleccionado' => $this->metodoSeleccionado?->toArray(),
        ];
    }
}
