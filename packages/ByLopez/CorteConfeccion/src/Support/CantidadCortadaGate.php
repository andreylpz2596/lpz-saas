<?php

namespace ByLopez\CorteConfeccion\Support;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use Webkul\User\Models\Admin;

class CantidadCortadaGate
{
    protected const ROLES = [
        'admin',
        'cortador',
        'bodeguero',
    ];

    public static function userCanEdit(?Admin $user = null, ?OrdenCorte $orden = null): bool
    {
        $user ??= auth()->guard('admin')->user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasAnyRole')) {
            if ($orden?->estado === EstadoOrdenCorte::Cerrado->value) {
                return method_exists($user, 'hasRole') && $user->hasRole('admin');
            }

            return $user->hasAnyRole(self::ROLES);
        }

        if (! $user->role) {
            return false;
        }

        $role = strtolower(trim((string) $user->role->name));
        $isAdmin = $role === 'admin' || $user->role->permission_type === 'all';

        if ($orden?->estado === EstadoOrdenCorte::Cerrado->value) {
            return $isAdmin;
        }

        return $isAdmin || in_array($role, self::ROLES, true);
    }

    public static function roleNames(): array
    {
        return self::ROLES;
    }
}
