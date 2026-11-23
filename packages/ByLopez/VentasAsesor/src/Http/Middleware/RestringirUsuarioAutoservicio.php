<?php

namespace ByLopez\VentasAsesor\Http\Middleware;

use ByLopez\VentasAsesor\Models\ClienteAcceso;
use ByLopez\VentasAsesor\Support\Autoservicio\RolClienteFrecuente;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Doc. 33 §5/§14 — DENY-BY-DEFAULT para el usuario de autoservicio. Bagisto deja algunas rutas del panel SIN clave ACL (cualquier
 * administrador autenticado entra): get-notifications devuelve pedidos de TODOS los clientes; account, help y notifications
 * tambien. Como Webkul no se modifica, este middleware se agrega a cada ruta del panel (RouteMatched) y, si el usuario es de
 * autoservicio (rol seguro o vinculo de acceso), solo deja pasar `admin.autoservicio.*` y cerrar sesion.
 */
class RestringirUsuarioAutoservicio
{
    /** Rutas nativas que el cliente SI puede usar. */
    public const PERMITIDAS = ['admin.session.destroy'];

    public function handle(Request $request, Closure $next): Response
    {
        $admin = auth()->guard('admin')->user();

        if ($admin && $this->esDeAutoservicio($admin) && ! $this->permitida((string) $request->route()?->getName())) {
            abort(403);
        }

        return $next($request);
    }

    protected function permitida(string $ruta): bool
    {
        return str_starts_with($ruta, 'admin.autoservicio.') || in_array($ruta, self::PERMITIDAS, true);
    }

    protected function esDeAutoservicio($admin): bool
    {
        if ($admin->role && app(RolClienteFrecuente::class)->esSeguro($admin->role)) {
            return true;
        }

        return ClienteAcceso::query()->where('admin_id', $admin->id)->whereIn('estado', [ClienteAcceso::ACTIVO, ClienteAcceso::SUSPENDIDO])->exists();
    }
}
