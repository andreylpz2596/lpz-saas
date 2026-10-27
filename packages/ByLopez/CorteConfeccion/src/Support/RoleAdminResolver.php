<?php

namespace ByLopez\CorteConfeccion\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\User\Models\Admin;

/**
 * Resuelve usuarios Admin activos por el NOMBRE real de su rol (nunca por ID
 * de rol quemado). Fuente compartida usada por CortadorResolver y
 * TallerConfeccionResolver para no duplicar la consulta de roles/admins.
 */
class RoleAdminResolver
{
    public static function options(string $rolNombre): Collection
    {
        return self::query($rolNombre)->orderBy('admins.name')->get();
    }

    public static function find(int $id, string $rolNombre): ?object
    {
        return self::query($rolNombre)->where('admins.id', $id)->first();
    }

    public static function tieneRol(int $id, string $rolNombre): bool
    {
        return self::find($id, $rolNombre) !== null;
    }

    public static function buscar(string $rolNombre, ?string $termino, int $limite = 20): Collection
    {
        $query = self::query($rolNombre);

        if ($termino = trim((string) $termino)) {
            $query->where(function ($subQuery) use ($termino) {
                $subQuery
                    ->where('admins.name', 'like', "%{$termino}%")
                    ->orWhere('admins.email', 'like', "%{$termino}%");
            });
        }

        return $query->orderBy('admins.name')->limit($limite)->get();
    }

    protected static function query(string $rolNombre)
    {
        $query = DB::table('admins')
            ->select('admins.id', 'admins.name', 'admins.email')
            ->where('admins.status', 1);

        $rolNormalizado = strtolower(trim($rolNombre));

        if (self::usesSpatieTables()) {
            return $query
                ->join('model_has_roles as mhr', function ($join) {
                    $join
                        ->on('admins.id', '=', 'mhr.model_id')
                        ->where('mhr.model_type', Admin::class);
                })
                ->join('roles', 'mhr.role_id', '=', 'roles.id')
                ->whereRaw('LOWER(roles.name) = ?', [$rolNormalizado])
                ->distinct();
        }

        return $query
            ->join('roles', 'admins.role_id', '=', 'roles.id')
            ->whereRaw('LOWER(roles.name) = ?', [$rolNormalizado]);
    }

    protected static function usesSpatieTables(): bool
    {
        return Schema::hasTable('model_has_roles')
            && Schema::hasTable('roles')
            && Schema::hasColumn('roles', 'guard_name');
    }
}
