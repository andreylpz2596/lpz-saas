<?php

namespace ByLopez\CorteConfeccion\Support;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use Illuminate\Support\Collection;
use Webkul\User\Models\Admin;

class CantidadCortadaGate
{
    protected const ROLES = [
        'admin',
        'cortador',
        'bodega',
    ];

    /**
     * Roles que pueden ejecutar la accion "Confirmar recepcion" (crear la
     * RecepcionCorte y mover la orden a recibido_bodega). A diferencia de
     * userCanEdit(), el Cortador queda excluido a proposito: puede reportar
     * su propio resultado, pero no puede confirmar lo que Bodega recibio.
     */
    protected const ROLES_CONFIRMAR_RECEPCION = [
        'admin',
        'bodega',
    ];

    /**
     * Roles que pueden crear la solicitud de corte (Card 1). El Cortador
     * queda excluido a proposito: solo reporta el resultado real, nunca
     * define que se solicita cortar.
     */
    protected const ROLES_SOLICITUD = [
        'admin',
        'bodega',
    ];

    public static function userCanEdit(?Admin $user = null, ?OrdenCorte $orden = null): bool
    {
        return self::evaluar($user, self::ROLES, $orden);
    }

    public static function userCanConfirmarRecepcion(?Admin $user = null): bool
    {
        return self::evaluar($user, self::ROLES_CONFIRMAR_RECEPCION);
    }

    public static function userCanGestionarSolicitud(?Admin $user = null): bool
    {
        return self::evaluar($user, self::ROLES_SOLICITUD);
    }

    public static function roleNames(): array
    {
        return self::ROLES;
    }

    /**
     * Usuarios activos que SI pueden confirmar la recepcion (BODEGA/Admin),
     * para poblar el selector "Recibido por" -- reutiliza userCanConfirmarRecepcion()
     * en vez de duplicar la logica de roles, asi la lista nunca queda
     * desincronizada de quien realmente esta autorizado a ejecutar la accion.
     */
    public static function usuariosConfirmarRecepcion(): Collection
    {
        return Admin::query()
            ->with('role')
            ->where('status', 1)
            ->get()
            ->filter(fn (Admin $admin) => self::userCanConfirmarRecepcion($admin))
            ->sortBy('name')
            ->values();
    }

    protected static function evaluar(?Admin $user, array $rolesPermitidos, ?OrdenCorte $orden = null): bool
    {
        $user ??= auth()->guard('admin')->user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasAnyRole')) {
            if ($orden && method_exists($user, 'hasRole') && $user->hasRole('cortador') && ! CortadorAccess::puedeAccederOrden($orden, $user)) {
                return false;
            }

            if ($orden?->estado === EstadoOrdenCorte::Cerrado->value) {
                return method_exists($user, 'hasRole') && $user->hasRole('admin');
            }

            return $user->hasAnyRole($rolesPermitidos);
        }

        if (! $user->role) {
            return false;
        }

        $role = strtolower(trim((string) $user->role->name));
        $isAdmin = $role === 'admin' || $user->role->permission_type === 'all';

        if ($role === 'cortador' && $orden && ! CortadorAccess::puedeAccederOrden($orden, $user)) {
            return false;
        }

        if ($orden?->estado === EstadoOrdenCorte::Cerrado->value) {
            return $isAdmin;
        }

        return $isAdmin || in_array($role, $rolesPermitidos, true);
    }
}
