<?php

namespace ByLopez\CorteConfeccion\Support;

use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\Taller;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Webkul\User\Models\Admin;

class TallerConfeccionAccess
{
    public const ROLE = 'taller confeccion';

    public static function user(?Admin $user = null): ?Admin
    {
        return $user ?? auth()->guard('admin')->user();
    }

    public static function esUsuarioTaller(?Admin $user = null): bool
    {
        $user = self::user($user);

        return $user && self::roleName($user) === self::ROLE;
    }

    public static function filtrarTalleresSiAplica(EloquentBuilder|QueryBuilder $query, string $usuarioColumn = 'usuario_id', ?Admin $user = null): EloquentBuilder|QueryBuilder
    {
        $user = self::user($user);

        if (self::esUsuarioTaller($user)) {
            $query->where($usuarioColumn, $user->id);
        }

        return $query;
    }

    public static function puedeAccederTaller(Taller $taller, ?Admin $user = null): bool
    {
        $user = self::user($user);

        if (! $user || ! $user->role) {
            return false;
        }

        if (! self::esUsuarioTaller($user)) {
            return true;
        }

        return (int) $taller->usuario_id === (int) $user->id;
    }

    public static function puedeAccederOrden(OrdenConfeccion $orden, ?Admin $user = null): bool
    {
        $user = self::user($user);

        if (! $user || ! $user->role) {
            return false;
        }

        if (! self::esUsuarioTaller($user)) {
            return true;
        }

        $orden->loadMissing('taller');

        return (int) $orden->taller?->usuario_id === (int) $user->id;
    }

    public static function roleName(Admin $user): string
    {
        return strtolower(trim((string) $user->role?->name));
    }
}
