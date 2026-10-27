<?php

namespace ByLopez\Cargues\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Solo el rol administrador (id configurable). Va ANTES del middleware `admin` de Bagisto: asi cualquier usuario
 * autenticado que no sea administrador recibe 403, tambien con un rol `all` y tambien con un rol personalizado (que el
 * Bouncer rechazaria con 401 por no haber clave ACL). Sin sesion: redirige al login, como el Admin.
 */
class SoloAdministrador
{
    public function handle(Request $request, Closure $next)
    {
        $admin = auth()->guard('admin')->user();

        if (! $admin) {
            return $request->expectsJson()
                ? response()->json(['mensaje' => 'No autenticado.'], 401)
                : redirect()->route('admin.session.create');
        }

        abort_unless(static::esAdministrador($admin), 403, 'Solo el administrador puede usar Cargues.');

        return $next($request);
    }

    public static function esAdministrador($admin): bool
    {
        return $admin !== null
            && (bool) ($admin->status ?? true)
            && (int) $admin->role_id === (int) config('cargues.rol_administrador_id');
    }
}
