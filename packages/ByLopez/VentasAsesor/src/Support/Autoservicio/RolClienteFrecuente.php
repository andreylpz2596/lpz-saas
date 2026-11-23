<?php

namespace ByLopez\VentasAsesor\Support\Autoservicio;

use RuntimeException;
use Webkul\User\Models\Role;

/**
 * Rol de autoservicio del CLIENTE FRECUENTE (doc. 33, §5): creado de forma idempotente y por NOMBRE, nunca por id.
 *
 * Reglas:
 *  - Los permisos son EXACTAMENTE los de autoservicio (hacer pedido, ver sus pedidos). Nunca `all`, nunca claves de
 *    catalogo, ventas, cartera, comisiones, costos ni configuracion.
 *  - Un rol con ese nombre pero con otros permisos NO se corrige ni se reutiliza: se rechaza (hay que revisarlo a mano).
 *  - Los roles de QA con nombres parecidos («CLIENTE FRECUENTE», «CLIENTE FRECUENTE 2») no se tocan ni se aceptan:
 *    solo vale el que cumple esta firma exacta de permisos.
 */
class RolClienteFrecuente
{
    public const NOMBRE = 'CLIENTE FRECUENTE AUTOSERVICIO';

    public const PERMISOS = [
        'autoservicio',
        'autoservicio.comprar',
        'autoservicio.pedidos',
    ];

    /** El rol seguro, o null si todavia no existe. Lanza si hay uno con ese nombre pero permisos distintos. */
    public function existente(): ?Role
    {
        $rol = Role::query()->where('name', self::NOMBRE)->first();

        if ($rol && ! $this->esSeguro($rol)) {
            throw new RuntimeException('El rol «'.self::NOMBRE.'» existe pero sus permisos no son los de autoservicio. Revisalo manualmente: no se modifica ni se usa.');
        }

        return $rol;
    }

    /** Crea el rol si no existe; si existe y es seguro, lo devuelve. Repetible. */
    public function asegurar(): Role
    {
        return $this->existente() ?? Role::query()->create([
            'name' => self::NOMBRE,
            'description' => 'Cliente comercial con acceso para hacer sus pedidos y consultar solo los suyos.',
            'permission_type' => 'custom',
            'permissions' => self::PERMISOS,
        ]);
    }

    /** Firma exacta: tipo custom y el conjunto de permisos de autoservicio (sin mas ni menos). */
    public function esSeguro(Role $rol): bool
    {
        $permisos = (array) $rol->permissions;

        return $rol->permission_type === 'custom'
            && count($permisos) === count(self::PERMISOS)
            && array_diff(self::PERMISOS, $permisos) === []
            && array_diff($permisos, self::PERMISOS) === [];
    }
}
