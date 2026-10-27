<?php

namespace ByLopez\VentasAsesor\Services\Asesores;

use ByLopez\VentasAsesor\Models\Asesor;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Fuente UNICA para resolver «usuario en sesion → asesor comercial» (ASESOR-01).
 *
 * - `asesorDe()`: el perfil ACTIVO del usuario, o null. Un perfil inactivo no resuelve.
 * - `requierePerfil()`: el usuario vende como asesor de forma automatica (D-27: rol `custom` con el permiso marcador
 *   `ventas_asesor.operar_como_asesor`). Se decide por ACL, nunca por nombre de rol.
 * - `operable()`: puede crear ventas. Quien requiere perfil y no lo tiene (o lo tiene inactivo) FALLA CERRADO: no se le
 *   degrada a «sin asignar» ni a «empresa» en silencio. El administrador `all` no requiere perfil (D-27).
 *
 * La visibilidad `view_own` sigue comparando `advisor_id` con el id del usuario: como `admin_id` es unico e inmutable
 * en el perfil, «mis pedidos como usuario» y «mis pedidos como asesor» son exactamente el mismo conjunto.
 */
class AsesorActualService
{
    /** @var array<int, ?Asesor> cache por peticion */
    private array $cache = [];

    public function __construct(protected AutorizacionVentasAsesor $autorizacion) {}

    public function asesorDe(?AdminContract $admin): ?Asesor
    {
        if (! $admin?->id) {
            return null;
        }

        return $this->cache[(int) $admin->id] ??= Asesor::query()->activos()->where('admin_id', (int) $admin->id)->first();
    }

    public function requierePerfil(?AdminContract $admin): bool
    {
        return $this->autorizacion->esAsesorParaAtribucionAutomatica($admin);
    }

    public function operable(?AdminContract $admin): bool
    {
        return ! $this->requierePerfil($admin) || $this->asesorDe($admin) !== null;
    }

    public function olvidar(): void
    {
        $this->cache = [];
    }
}
