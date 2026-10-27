<?php

namespace ByLopez\CorteConfeccion\Support;

use Webkul\User\Models\Admin;

class InventarioMovimientoGate
{
    public static function puedeAjustarManual(?Admin $user = null): bool
    {
        $user ??= auth()->guard('admin')->user();

        if (! $user?->role) {
            return false;
        }

        $roleName = mb_strtolower(trim((string) $user->role->name));

        if (in_array($roleName, ['bodega', 'taller confeccion', 'cortador'], true)) {
            return false;
        }

        return $user->role->permission_type === 'all'
            || in_array($roleName, ['admin', 'administrador'], true);
    }
}
