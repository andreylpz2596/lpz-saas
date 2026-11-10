<?php

namespace ByLopez\CorteConfeccion\Support;

use Webkul\User\Models\Admin;

/**
 * Autorizacion para reasignar prendas entre talleres. NUNCA basta la ACL configurable: un usuario de taller o un
 * cortador con la ACL asignada por error (o con permission_type = all) sigue bloqueado, igual que en
 * DistribucionConfeccionGate. ACL determina la accion; el bloqueo por rol impide que quien recibe el trabajo decida
 * a quien se mueve.
 */
class ReasignacionGate
{
    public const ACL = 'corte_confeccion.ordenes_confeccion.reasignar';

    protected const ROLES_BLOQUEADOS = ['taller confeccion', 'cortador'];

    public static function puedeReasignar(?Admin $user = null): bool
    {
        $user ??= auth()->guard('admin')->user();

        if (! $user || ! $user->role) {
            return false;
        }

        if (in_array(strtolower(trim((string) $user->role->name)), self::ROLES_BLOQUEADOS, true)) {
            return false;
        }

        return bouncer()->hasPermission(self::ACL);
    }
}
