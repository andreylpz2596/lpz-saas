<?php

namespace ByLopez\VentasAsesor\Support;

use Illuminate\Support\Collection;

/**
 * Primera pantalla del panel que el usuario autenticado puede abrir. Replica el criterio de
 * Webkul SessionController::redirectToFirstAccessibleRoute (privado), que solo se usa al iniciar sesion:
 * aqui se reutiliza para que /admin/dashboard no devuelva 401 a un rol sin el permiso «dashboard».
 */
class PrimeraRutaAccesible
{
    /** Nombre de ruta, o null si el rol no tiene ninguna pantalla accesible distinta del dashboard. */
    public function nombre(): ?string
    {
        $usuario = auth()->guard('admin')->user();
        $permisos = $usuario?->role?->permissions;

        if (! is_array($permisos)) {
            return null;
        }

        $todos = collect(config('acl'));

        foreach ($permisos as $permiso) {
            if (! bouncer()->hasPermission($permiso)) {
                continue;
            }

            if (str_contains($permiso, '.')) {
                $ruta = $todos->firstWhere('key', $permiso)['route'] ?? null;
            } else {
                // Igual que Bagisto: primero un hijo accesible; si no hay, la ruta propia de la clave raiz.
                $ruta = $this->primerHijo($todos, $permiso)['route'] ?? ($todos->firstWhere('key', $permiso)['route'] ?? null);
            }

            if ($this->utilizable($ruta)) {
                return $ruta;
            }
        }

        return null;
    }

    private function utilizable(?string $ruta): bool
    {
        return $ruta !== null && $ruta !== 'admin.dashboard.index' && app('router')->has($ruta);
    }

    private function primerHijo(Collection $todos, string $padre): ?array
    {
        $hijos = $todos->filter(fn ($item) => str_starts_with($item['key'], $padre.'.')
            && substr_count($item['key'], '.') === substr_count($padre, '.') + 1
            && bouncer()->hasPermission($item['key']))->values();

        foreach ($hijos as $hijo) {
            if ($this->tieneTodosLosPermisosDeLaRuta($todos, $hijo['route'] ?? null)) {
                return $hijo;
            }

            if ($nieto = $this->primerHijo($todos, $hijo['key'])) {
                return $nieto;
            }
        }

        return null;
    }

    private function tieneTodosLosPermisosDeLaRuta(Collection $todos, ?string $ruta): bool
    {
        if ($ruta === null) {
            return false;
        }

        foreach ($todos->where('route', $ruta) as $permiso) {
            if (! bouncer()->hasPermission($permiso['key'])) {
                return false;
            }
        }

        return true;
    }
}
