<?php

namespace ByLopez\CorteConfeccion\Support;

use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\Taller;
use Webkul\User\Models\Admin;

/**
 * Autorizacion unica de la cartera (denegar por defecto).
 *
 * El aislamiento NO depende del nombre del rol: se decide por el VINCULO del usuario con un prestador
 * (`talleres.usuario_id` / `cortadores.usuario_id`) y por permisos verificados en servidor.
 *   - Un usuario vinculado a un prestador solo puede VER su propia cuenta (permiso `cartera.mi_cuenta`). Nunca registra ni
 *     reversa nada, aunque su rol tenga permission_type=all, se llame distinto o tenga las llaves administrativas.
 *   - Un rol de nombre «TALLER CONFECCION»/«CORTADOR» sin vinculo tampoco tiene acceso administrativo.
 *   - El resto (personal de la empresa) necesita la llave concreta de cada accion.
 */
class CarteraGate
{
    public const VER = 'corte_confeccion.cartera';

    public const REGISTRAR = 'corte_confeccion.cartera.registrar';

    public const PRESTAMOS = 'corte_confeccion.cartera.prestamos';

    public const CORTE = 'corte_confeccion.cartera.corte';

    public const REVERSAR = 'corte_confeccion.cartera.reversar';

    public const ARRANQUE = 'corte_confeccion.cartera.arranque';

    public const MI_CUENTA = 'corte_confeccion.mi_cuenta';

    public static function user(?Admin $user = null): ?Admin
    {
        return $user ?? auth()->guard('admin')->user();
    }

    /** Prestador al que el usuario esta vinculado (por usuario_id), o null si es personal de la empresa. */
    public static function prestadorVinculado(?Admin $user = null): Taller|Cortador|null
    {
        $user = self::user($user);

        if (! $user) {
            return null;
        }

        return Taller::query()->where('usuario_id', $user->id)->first()
            ?? Cortador::query()->where('usuario_id', $user->id)->first();
    }

    /** Usuario que, por su vinculo o por el nombre de su rol, es un prestador y no personal administrativo. */
    public static function esPrestador(?Admin $user = null): bool
    {
        $user = self::user($user);

        if (! $user) {
            return false;
        }

        return self::prestadorVinculado($user) !== null
            || TallerConfeccionAccess::esUsuarioTaller($user)
            || CortadorAccess::esUsuarioCortador($user);
    }

    /** Accion administrativa: personal de la empresa con la llave concreta. */
    public static function puede(string $permiso, ?Admin $user = null): bool
    {
        $user = self::user($user);

        if (! $user || ! $user->role || self::esPrestador($user)) {
            return false;
        }

        return bouncer()->hasPermission($permiso);
    }

    public static function puedeVerCuenta(Taller|Cortador $prestador, ?Admin $user = null): bool
    {
        $user = self::user($user);

        if (! $user || ! $user->role) {
            return false;
        }

        if (self::esPrestador($user)) {
            $propio = self::prestadorVinculado($user);

            return $propio !== null
                && get_class($propio) === get_class($prestador)
                && (int) $propio->getKey() === (int) $prestador->getKey()
                && bouncer()->hasPermission(self::MI_CUENTA);
        }

        return bouncer()->hasPermission(self::VER);
    }

    /** ¿Puede ver (solo lectura) la cuenta de una orden cuyo prestador es $prestador? */
    public static function puedeVerOrden(Taller|Cortador $prestador, ?Admin $user = null): bool
    {
        return self::puedeVerCuenta($prestador, $user);
    }
}
