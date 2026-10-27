<?php

namespace ByLopez\CorteConfeccion\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CortadorResolver
{
    protected const ROL = 'cortador';

    public static function options(): Collection
    {
        return RoleAdminResolver::options(self::ROL);
    }

    public static function find(int $id): ?object
    {
        return RoleAdminResolver::find($id, self::ROL);
    }

    public static function isCortador(int $id): bool
    {
        return RoleAdminResolver::tieneRol($id, self::ROL);
    }

    public static function buscar(?string $termino): Collection
    {
        return RoleAdminResolver::buscar(self::ROL, $termino);
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
}
