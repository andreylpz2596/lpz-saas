<?php

namespace ByLopez\VentasAsesor\Support;

use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Punto unico de decision de autorizacion del modulo.
 *
 * - El asesor se identifica por el permiso ACL marcador (D-06), nunca por el
 *   nombre editable del rol.
 * - Falla cerrado: solo reconoce los tipos de permiso 'all' y 'custom'.
 * - No consulta pedidos ni tablas propias: decide con lo que recibe.
 */
class AutorizacionVentasAsesor
{
    public const PERMISO_MODULO = 'ventas_asesor';

    public const PERMISO_OPERAR_COMO_ASESOR = 'ventas_asesor.operar_como_asesor';

    public const PERMISO_PEDIDOS_VER_PROPIOS = 'ventas_asesor.pedidos.view_own';

    public const PERMISO_PEDIDOS_VER_TODOS = 'ventas_asesor.pedidos.view_all';

    public const PERMISO_PEDIDOS_ASIGNAR = 'ventas_asesor.pedidos.assign';

    public const PERMISO_PEDIDOS_REASIGNAR = 'ventas_asesor.pedidos.reassign';

    /**
     * - 'all': concede todo, como Bagisto.
     * - 'custom': concede solo si los permisos son una lista de cadenas que
     *   contiene la clave exacta.
     * - Cualquier otro caso niega: administrador o rol nulo; tipo vacio, nulo o
     *   desconocido; permisos nulos, que no son arreglo o con elementos que no son
     *   cadenas.
     *
     * No se delega en Admin::hasPermission() porque concede con cualquier tipo
     * distinto de 'custom', compara con in_array() no estricto y lanza TypeError
     * con 'all'.
     */
    public function tienePermiso(?AdminContract $admin, string $permiso): bool
    {
        $rol = $admin?->role;

        if (! $rol) {
            return false;
        }

        if ($rol->permission_type === 'all') {
            return true;
        }

        if ($rol->permission_type !== 'custom') {
            return false;
        }

        $permisos = $rol->permissions;

        if (! is_array($permisos) || count(array_filter($permisos, 'is_string')) !== count($permisos)) {
            return false;
        }

        return in_array($permiso, $permisos, true);
    }

    /**
     * Puede SER asesor: incluye al administrador con permission_type 'all', que
     * puede elegirse expresamente como asesor (D-27).
     */
    public function esAsesor(?AdminContract $admin): bool
    {
        return $this->tienePermiso($admin, self::PERMISO_OPERAR_COMO_ASESOR);
    }

    /**
     * Recibe la venta AUTOMATICAMENTE al crear un pedido (D-27, decidida en la
     * Fase 01B). Exige permission_type 'custom' y el permiso marcador explicito.
     *
     * Un administrador con acceso total queda fuera a proposito: tener todos los
     * permisos no lo convierte en asesor ni le genera comision. Puede serlo solo
     * si alguien lo selecciona expresamente, y eso lo decide esAsesor().
     */
    public function esAsesorParaAtribucionAutomatica(?AdminContract $admin): bool
    {
        return $admin?->role?->permission_type === 'custom'
            && $this->tienePermiso($admin, self::PERMISO_OPERAR_COMO_ASESOR);
    }

    public function alcanceDeVisibilidad(?AdminContract $admin): AlcanceVisibilidad
    {
        if ($this->tienePermiso($admin, self::PERMISO_PEDIDOS_VER_TODOS)) {
            return AlcanceVisibilidad::TODOS;
        }

        if ($this->tienePermiso($admin, self::PERMISO_PEDIDOS_VER_PROPIOS)) {
            return AlcanceVisibilidad::PROPIOS;
        }

        return AlcanceVisibilidad::NINGUNO;
    }

    /**
     * Regla de aislamiento horizontal: sin atribucion valida y sin view_all, niega.
     * $advisorId es el asesor propietario del registro, o null si no tiene.
     */
    public function puedeVerRegistroDe(?AdminContract $admin, ?int $advisorId): bool
    {
        return match ($this->alcanceDeVisibilidad($admin)) {
            AlcanceVisibilidad::TODOS => true,
            AlcanceVisibilidad::PROPIOS => $advisorId !== null
                && $admin->id !== null
                && (int) $admin->id === $advisorId,
            AlcanceVisibilidad::NINGUNO => false,
        };
    }
}
