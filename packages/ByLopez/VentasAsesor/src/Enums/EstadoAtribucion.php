<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Estado TECNICO del registro de atribucion [M-§10.3].
 *
 * - COMPLETA: se conoce la propiedad comercial (asesor o empresa).
 * - SIN_ASESOR: estado legitimo, pendiente de decision comercial.
 * - FALLO_TECNICO: el modulo no pudo determinar el contexto. Desde la Fase 02A1
 *   existe un camino autorizado para registrarlo
 *   (RegistrarAtribucionService::registrarFalloTecnico), idempotente y sin asesor.
 *   Ningun listener lo produce todavia: la Fase 02B no esta iniciada.
 */
enum EstadoAtribucion: string
{
    case COMPLETA = 'completa';

    case SIN_ASESOR = 'sin_asesor';

    case FALLO_TECNICO = 'fallo_tecnico';

    public static function desdePropiedad(TipoPropiedadComercial $propiedad): self
    {
        return match ($propiedad) {
            TipoPropiedadComercial::ASESOR, TipoPropiedadComercial::EMPRESA => self::COMPLETA,
            TipoPropiedadComercial::SIN_ASIGNAR => self::SIN_ASESOR,
        };
    }

    public function esIncidencia(): bool
    {
        return $this === self::FALLO_TECNICO;
    }
}
