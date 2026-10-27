<?php

namespace ByLopez\CorteConfeccion\Support;

use Webkul\User\Models\Admin;

/**
 * Defensa en profundidad para la distribucion de confeccion (Fase SEC-01).
 * NUNCA debe confiarse unicamente en la ACL configurable (Config/acl.php):
 * un rol operativo (p. ej. "TALLER CONFECCION" o "CORTADOR") podria recibir
 * por error el permiso `corte_confeccion.ordenes_confeccion.create` -- o
 * incluso `permission_type = 'all'`, que en Bagisto salta toda la ACL --
 * sin que eso deba habilitarlo a distribuir mercancia a otros Talleres.
 *
 * Lista de bloqueo explicita (denylist) en vez de una lista de roles
 * autorizados: los roles operativos que esta fase identifico como
 * incompatibles con distribuir quedan vetados por nombre, sin importar
 * `permission_type` ni la ACL configurada. Fuera de esa lista, se respeta
 * el mecanismo estandar de autorizacion de Bagisto (`bouncer()->hasPermission()`,
 * que ya contempla `permission_type='all'`) -- igual que el resto del
 * paquete. Una lista de autorizados en vez de bloqueo hubiera roto todos
 * los admins de prueba existentes que usan nombres de rol arbitrarios con
 * `permission_type='all'` (patron ya establecido en la suite de tests).
 */
class DistribucionConfeccionGate
{
    protected const ROLES_BLOQUEADOS = [
        'taller confeccion',
        'cortador',
    ];

    public static function puedeDistribuir(?Admin $user = null): bool
    {
        $user ??= auth()->guard('admin')->user();

        if (! $user || ! $user->role) {
            return false;
        }

        $role = strtolower(trim((string) $user->role->name));

        if (in_array($role, self::ROLES_BLOQUEADOS, true)) {
            return false;
        }

        return bouncer()->hasPermission('corte_confeccion.ordenes_confeccion.create');
    }
}
