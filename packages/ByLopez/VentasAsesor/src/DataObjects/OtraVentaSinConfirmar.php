<?php

namespace ByLopez\VentasAsesor\DataObjects;

use Carbon\CarbonInterface;

/**
 * Otra venta (borrador) sin confirmar que lleva la misma variante en su Cart. Coordinacion
 * interna entre asesores: solo el nombre del asesor y la cantidad. Nunca cliente, telefono,
 * direccion, precio ni total. Ver el nombre NO da permiso para abrir, editar, reclamar ni
 * confirmar ese borrador (las reglas de propiedad no cambian).
 */
final class OtraVentaSinConfirmar
{
    public function __construct(
        public readonly int $asesorId,
        public readonly ?string $asesorNombre,
        public readonly int $cantidad,
        /** `activo` o `confirmando`. */
        public readonly string $estado,
        /** Ultimo cambio del Cart o de sus lineas. No es presencia en linea. */
        public readonly ?CarbonInterface $ultimaActividad,
    ) {}

    public function toArray(): array
    {
        return [
            'asesor_id' => $this->asesorId,
            'asesor_nombre' => $this->asesorNombre,
            'cantidad' => $this->cantidad,
            'estado' => $this->estado,
            'ultima_actividad' => $this->ultimaActividad?->toIso8601String(),
        ];
    }
}
