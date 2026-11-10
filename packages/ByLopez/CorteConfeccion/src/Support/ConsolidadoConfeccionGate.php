<?php

namespace ByLopez\CorteConfeccion\Support;

use ByLopez\CorteConfeccion\Models\Taller;
use Webkul\User\Models\Admin;

/**
 * Autorizacion del Consolidado general y del cambio de prioridad por cantidad (denegar por defecto).
 *
 * Igual que CarteraGate, el aislamiento depende del VINCULO del usuario (`talleres.usuario_id`), no del nombre del rol:
 *   - Usuario vinculado a uno o mas talleres: ve SOLO esos talleres, sin importar como se llame su rol ni si tiene
 *     permission_type=all. Nunca cambia prioridades.
 *   - Rol de prestador («TALLER CONFECCION»/«CORTADOR») sin vinculo, o vinculado solo como cortador: no ve nada.
 *   - Personal de la empresa: ve todos los talleres con la llave del listado; cambiar prioridad exige su propia llave.
 */
class ConsolidadoConfeccionGate
{
    public const VER = 'corte_confeccion.ordenes_confeccion';

    public const PRIORIDAD = 'corte_confeccion.ordenes_confeccion.prioridad';

    public const MINIMOS = 'corte_confeccion.stock_minimos';

    public const MINIMOS_EDITAR = 'corte_confeccion.stock_minimos.editar';

    /** @return array<int, int> */
    public static function tallerIdsVinculados(?Admin $user = null): array
    {
        $user = CarteraGate::user($user);

        if (! $user) {
            return [];
        }

        return Taller::query()->where('usuario_id', $user->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Talleres que el usuario puede consultar: null = todos (personal de la empresa); lista = solo esos (vacia = ninguno).
     *
     * @return array<int, int>|null
     */
    public static function alcance(?Admin $user = null): ?array
    {
        $user = CarteraGate::user($user);

        if (! $user) {
            return [];
        }

        $vinculados = self::tallerIdsVinculados($user);

        if ($vinculados !== [] || CarteraGate::esPrestador($user)) {
            return $vinculados;
        }

        return null;
    }

    public static function puedeVer(?Admin $user = null): bool
    {
        $user = CarteraGate::user($user);

        if (! $user || ! $user->role) {
            return false;
        }

        $alcance = self::alcance($user);

        if ($alcance !== null) {
            return $alcance !== []
                && (bouncer()->hasPermission(self::VER) || bouncer()->hasPermission('corte_confeccion.ordenes_confeccion.reportar_taller'));
        }

        return bouncer()->hasPermission(self::VER);
    }

    public static function puedeVerTaller(int $tallerId, ?Admin $user = null): bool
    {
        if (! self::puedeVer($user)) {
            return false;
        }

        $alcance = self::alcance($user);

        return $alcance === null || in_array($tallerId, $alcance, true);
    }

    /**
     * Cifras de inventario (disponible y minimo) en la sugerencia por stock: solo personal de la empresa. Un usuario
     * vinculado a un taller (o un prestador sin vinculo) ve el nivel y el motivo, nunca cifras globales de inventario.
     */
    public static function puedeVerCifrasStock(?Admin $user = null): bool
    {
        $user = CarteraGate::user($user);

        return $user && $user->role && self::alcance($user) === null;
    }

    /** Consultar los minimos de stock por variante (personal de la empresa con la llave). */
    public static function puedeVerMinimos(?Admin $user = null): bool
    {
        return self::puedeVerCifrasStock($user) && bouncer()->hasPermission(self::MINIMOS);
    }

    /** Configurar minimos: llave propia, nunca para prestadores aunque su rol la traiga. */
    public static function puedeEditarMinimos(?Admin $user = null): bool
    {
        return self::puedeVerMinimos($user) && bouncer()->hasPermission(self::MINIMOS_EDITAR);
    }

    /** Cambiar prioridades es accion administrativa: un prestador nunca la tiene, aunque su rol traiga la llave. */
    public static function puedeCambiarPrioridad(?Admin $user = null): bool
    {
        $user = CarteraGate::user($user);

        if (! $user || ! $user->role || self::alcance($user) !== null) {
            return false;
        }

        return bouncer()->hasPermission(self::VER) && bouncer()->hasPermission(self::PRIORIDAD);
    }
}
