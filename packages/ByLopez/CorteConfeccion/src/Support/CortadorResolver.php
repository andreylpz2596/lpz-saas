<?php

namespace ByLopez\CorteConfeccion\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Webkul\User\Models\Admin;

class CortadorResolver
{
    public static function options(): Collection
    {
        return self::query()
            ->orderBy('admins.name')
            ->get();
    }

    public static function find(int $id): ?object
    {
        return self::query()
            ->where('admins.id', $id)
            ->first();
    }

    public static function isCortador(int $id): bool
    {
        return self::find($id) !== null;
    }

    public static function snapshotName(object $cortador): string
    {
        return trim((string) ($cortador->name ?? '')) ?: (string) ($cortador->email ?? $cortador->id);
    }

    public static function codePrefix(string $snapshotName): string
    {
        $prefix = preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii($snapshotName)));

        return str_pad(substr($prefix ?: 'COR', 0, 3), 3, 'X');
    }

    protected static function query()
    {
        $query = DB::table('admins')
            ->select('admins.id', 'admins.name', 'admins.email')
            ->where('admins.status', 1);

        if (self::usesSpatieTables()) {
            return $query
                ->join('model_has_roles as mhr', function ($join) {
                    $join
                        ->on('admins.id', '=', 'mhr.model_id')
                        ->where('mhr.model_type', Admin::class);
                })
                ->join('roles', 'mhr.role_id', '=', 'roles.id')
                ->whereRaw('LOWER(roles.name) = ?', ['cortador'])
                ->distinct();
        }

        return $query
            ->join('roles', 'admins.role_id', '=', 'roles.id')
            ->whereRaw('LOWER(roles.name) = ?', ['cortador']);
    }

    protected static function usesSpatieTables(): bool
    {
        return Schema::hasTable('model_has_roles')
            && Schema::hasTable('roles')
            && Schema::hasColumn('roles', 'guard_name');
    }
}
