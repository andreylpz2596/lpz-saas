<?php

namespace ByLopez\CorteConfeccion\Support;

use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use Webkul\User\Models\Admin;

class OrdenConfeccionGate
{
    public static function puedeVer(?OrdenConfeccion $orden = null, ?Admin $user = null): bool
    {
        $user ??= auth()->guard('admin')->user();

        if (! $user || ! $user->role) {
            return false;
        }

        // Aislamiento por rol «TALLER CONFECCION» o por VINCULO con un taller (aunque el rol se llame distinto).
        if (TallerConfeccionAccess::esUsuarioTaller($user) || self::vinculadoATaller($user)) {
            $tieneAclConsulta = bouncer()->hasPermission('corte_confeccion.ordenes_confeccion')
                || bouncer()->hasPermission('corte_confeccion.ordenes_confeccion.reportar_taller');

            if (! $tieneAclConsulta) {
                return false;
            }

            if (! $orden) {
                return true;
            }

            $orden->loadMissing('taller');

            return (int) $orden->taller?->usuario_id === (int) $user->id;
        }

        if (bouncer()->hasPermission('corte_confeccion.ordenes_confeccion')) {
            return true;
        }

        return false;
    }

    public static function puedeReportarTaller(OrdenConfeccion $orden, ?Admin $user = null): bool
    {
        $user ??= auth()->guard('admin')->user();

        if (! $user || ! $user->role) {
            return false;
        }

        /*
         * ACL y ownership son independientes: reportar_taller define la accion,
         * y el taller asignado define sobre que orden puede ejecutarse. Bodega y
         * Cortador siguen bloqueados aunque tengan esa ACL por error.
         */
        if (in_array(TallerConfeccionAccess::roleName($user), ['cortador', 'bodega'], true)) {
            return false;
        }

        if (! bouncer()->hasPermission('corte_confeccion.ordenes_confeccion.reportar_taller')) {
            return false;
        }

        return TallerConfeccionAccess::puedeAccederOrden($orden, $user);
    }

    public static function puedeOperar(OrdenConfeccion $orden, ?Admin $user = null): bool
    {
        $user ??= auth()->guard('admin')->user();

        if (! $user || ! $user->role) {
            return false;
        }

        // H11: el cortador no opera (anula) ordenes de confeccion ni con la ACL «operar» ni con permission_type=all.
        if (TallerConfeccionAccess::roleName($user) === 'cortador') {
            return false;
        }

        if (! bouncer()->hasPermission('corte_confeccion.ordenes_confeccion.operar')) {
            return false;
        }

        return TallerConfeccionAccess::puedeAccederOrden($orden, $user);
    }

    public static function puedeRecibirBodega(?Admin $user = null): bool
    {
        $user ??= auth()->guard('admin')->user();

        if (! $user || ! $user->role) {
            return false;
        }

        $role = TallerConfeccionAccess::roleName($user);

        if (in_array($role, ['taller confeccion', 'cortador'], true)) {
            return false;
        }

        return in_array($role, ['admin', 'bodega'], true)
            || $user->role->permission_type === 'all'
            || bouncer()->hasPermission('corte_confeccion.ordenes_confeccion.recibir_bodega');
    }

    public static function debeFiltrarListadoPorTaller(?Admin $user = null): bool
    {
        $user ??= auth()->guard('admin')->user();

        return $user && (TallerConfeccionAccess::esUsuarioTaller($user) || self::vinculadoATaller($user));
    }

    public static function vinculadoATaller(Admin $user): bool
    {
        return ConsolidadoConfeccionGate::tallerIdsVinculados($user) !== [];
    }
}
