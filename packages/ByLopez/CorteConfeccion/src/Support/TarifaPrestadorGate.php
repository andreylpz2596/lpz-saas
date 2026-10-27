<?php

namespace ByLopez\CorteConfeccion\Support;

use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\Taller;

/**
 * Autorizacion unica de las tarifas (Doc. 05). `$tipo` es 'taller' (ficha de taller y ordenes de confeccion) o
 * 'cortador' (ficha de cortador y ordenes de corte). Quien administra tarifas es la empresa: el usuario con rol
 * Taller Confeccion o Cortador nunca puede, aunque su rol tuviera el permiso por error. Se valida en backend
 * (Form Requests) y se reutiliza para mostrar u ocultar los controles.
 */
class TarifaPrestadorGate
{
    public const TIPO_TALLER = 'taller';

    public const TIPO_CORTADOR = 'cortador';

    public static function permiso(string $tipo): string
    {
        return $tipo === self::TIPO_CORTADOR
            ? 'corte_confeccion.cortadores.tarifas'
            : 'corte_confeccion.talleres.tarifas';
    }

    public static function puedeGestionar(string $tipo): bool
    {
        if (! in_array($tipo, [self::TIPO_TALLER, self::TIPO_CORTADOR], true)) {
            return false;
        }

        if (TallerConfeccionAccess::esUsuarioTaller() || CortadorAccess::esUsuarioCortador()) {
            return false;
        }

        return bouncer()->hasPermission(self::permiso($tipo));
    }

    /** La tarifa de una orden anulada ya no se pacta ni se cambia. */
    public static function ordenAdmiteTarifa(OrdenConfeccion|OrdenCorte $orden): bool
    {
        return ! in_array($orden->estado, ['anulada', 'anulado'], true);
    }

    public static function prestadorTipo(Taller|Cortador $prestador): string
    {
        return $prestador instanceof Taller ? self::TIPO_TALLER : self::TIPO_CORTADOR;
    }
}
