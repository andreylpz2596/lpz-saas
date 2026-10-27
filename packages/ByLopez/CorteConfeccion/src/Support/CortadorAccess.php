<?php

namespace ByLopez\CorteConfeccion\Support;

use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Webkul\User\Models\Admin;

class CortadorAccess
{
    public const ROLE = 'cortador';

    public static function user(?Admin $user = null): ?Admin
    {
        return $user ?? auth()->guard('admin')->user();
    }

    public static function esUsuarioCortador(?Admin $user = null): bool
    {
        $user = self::user($user);

        return $user && self::roleName($user) === self::ROLE;
    }

    public static function perfil(?Admin $user = null): ?Cortador
    {
        $user = self::user($user);

        if (! $user || ! self::esUsuarioCortador($user)) {
            return null;
        }

        return Cortador::query()->where('usuario_id', $user->id)->first();
    }

    public static function filtrarCortadoresSiAplica(EloquentBuilder|QueryBuilder $query, string $usuarioColumn = 'usuario_id', ?Admin $user = null): EloquentBuilder|QueryBuilder
    {
        $user = self::user($user);

        if (self::esUsuarioCortador($user)) {
            $query->where($usuarioColumn, $user->id);
        }

        return $query;
    }

    public static function filtrarOrdenesCorteSiAplica(EloquentBuilder|QueryBuilder $query, string $cortadorColumn = 'cortador_id', ?Admin $user = null): EloquentBuilder|QueryBuilder
    {
        $user = self::user($user);

        if (! self::esUsuarioCortador($user)) {
            return $query;
        }

        if (! self::perfil($user)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($cortadorColumn, $user->id);
    }

    public static function puedeAccederCortador(Cortador $cortador, ?Admin $user = null): bool
    {
        $user = self::user($user);

        if (! $user || ! $user->role) {
            return false;
        }

        if (! self::esUsuarioCortador($user)) {
            return true;
        }

        return (int) $cortador->usuario_id === (int) $user->id;
    }

    public static function puedeAccederOrden(OrdenCorte $orden, ?Admin $user = null): bool
    {
        $user = self::user($user);

        if (! $user || ! $user->role) {
            return false;
        }

        if (! self::esUsuarioCortador($user)) {
            return true;
        }

        if (! self::perfil($user)) {
            return false;
        }

        return (int) $orden->cortador_id === (int) $user->id;
    }

    public static function puedeVerOrden(OrdenCorte $orden, ?Admin $user = null): bool
    {
        if (! bouncer()->hasPermission('corte_confeccion.ordenes_corte')) {
            return false;
        }

        return self::puedeAccederOrden($orden, $user);
    }

    public static function puedeOperarOrden(OrdenCorte $orden, ?Admin $user = null): bool
    {
        if (! bouncer()->hasPermission('corte_confeccion.ordenes_corte.operar')) {
            return false;
        }

        return self::puedeAccederOrden($orden, $user);
    }

    public static function roleName(Admin $user): string
    {
        return strtolower(trim((string) $user->role?->name));
    }
}
