<?php

namespace ByLopez\VentasAsesor\DataObjects;

use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Eleccion explicita de propiedad comercial que hara la interfaz de una fase
 * posterior: venta de empresa, asignarme como asesor o asignar otro asesor
 * (D-27). La Fase 02A solo la modela; no la expone por HTTP.
 */
final class SeleccionPropiedadComercial
{
    private function __construct(
        public readonly TipoPropiedadComercial $propiedad,
        public readonly ?AdminContract $asesor,
        public readonly bool $esElCreador,
    ) {}

    public static function ventaDeEmpresa(): self
    {
        return new self(TipoPropiedadComercial::EMPRESA, null, false);
    }

    public static function asignarmeComoAsesor(): self
    {
        return new self(TipoPropiedadComercial::ASESOR, null, true);
    }

    public static function asignarAsesor(AdminContract $asesor): self
    {
        return new self(TipoPropiedadComercial::ASESOR, $asesor, false);
    }

    public static function dejarSinAsignar(): self
    {
        return new self(TipoPropiedadComercial::SIN_ASIGNAR, null, false);
    }
}
