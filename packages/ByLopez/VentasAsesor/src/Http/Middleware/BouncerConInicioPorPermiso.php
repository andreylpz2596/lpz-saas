<?php

namespace ByLopez\VentasAsesor\Http\Middleware;

use ByLopez\VentasAsesor\Support\PrimeraRutaAccesible;
use Closure;
use Webkul\User\Http\Middleware\Bouncer;

/**
 * Bouncer de Bagisto + una sola excepcion: un rol sin el permiso «dashboard» que abre /admin/dashboard
 * (logo del panel, boton «Ir al Dashboard» de la pagina de error, /admin/login con sesion abierta, atras del
 * navegador) va a su primera pantalla accesible en vez de recibir 401. Todo lo demas lo decide el Bouncer
 * original sin cambios (estado, permisos vacios, doble factor, 401 en cualquier otra ruta).
 */
class BouncerConInicioPorPermiso extends Bouncer
{
    public function handle($request, Closure $next, $guard = 'admin')
    {
        if ($destino = $this->inicioPorPermiso($request, $guard)) {
            return redirect()->route($destino);
        }

        return parent::handle($request, $next, $guard);
    }

    private function inicioPorPermiso($request, string $guard): ?string
    {
        if (! $request->isMethod('GET') || $request->wantsJson() || ! $request->routeIs('admin.dashboard.index')) {
            return null;
        }

        $usuario = auth()->guard($guard)->user();

        if (! $usuario || ! $usuario->status || ! $usuario->role || $usuario->role->permission_type === 'all') {
            return null;
        }

        if (empty($usuario->role->permissions) || $this->isTwoFactorRequired($guard) || bouncer()->hasPermission('dashboard')) {
            return null;
        }

        return app(PrimeraRutaAccesible::class)->nombre();
    }
}
