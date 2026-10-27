<?php

namespace ByLopez\CorteConfeccion\Support;

use Illuminate\Support\Collection;

/**
 * Resuelve usuarios Admin activos cuyo rol real (almacenado en BD) sea
 * exactamente "TALLER CONFECCION". No crea usuarios ni asume IDs: siempre
 * consulta el nombre de rol vigente vía RoleAdminResolver.
 */
class TallerConfeccionResolver
{
    protected const ROL = 'TALLER CONFECCION';

    public static function options(): Collection
    {
        return RoleAdminResolver::options(self::ROL);
    }

    public static function find(int $id): ?object
    {
        return RoleAdminResolver::find($id, self::ROL);
    }

    public static function tieneRol(int $id): bool
    {
        return RoleAdminResolver::tieneRol($id, self::ROL);
    }

    public static function buscar(?string $termino): Collection
    {
        return RoleAdminResolver::buscar(self::ROL, $termino);
    }
}
