<?php

namespace ByLopez\VentasAsesor\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

/**
 * Un formulario del panel enviado con un token CSRF vencido (pagina abierta mucho tiempo, doble envio del
 * login, pagina restaurada con «atras») produce 419, y el Handler de Bagisto lo muestra como «500 error
 * interno». Aqui se convierte en volver al formulario con un aviso; para el login se conserva el correo.
 * Las peticiones JSON siguen igual.
 */
class PaginaPanelExpiradaException extends TokenMismatchException
{
    public static function aplica(Request $request): bool
    {
        return $request->is(config('app.admin_url').'/*') && ! $request->expectsJson();
    }

    public function render(Request $request)
    {
        $aviso = trans('ventasasesor::app.sesion.pagina-expirada');

        if ($request->routeIs('admin.session.store')) {
            return redirect()->route('admin.session.create')
                ->withInput($request->only('email'))
                ->with('warning', $aviso);
        }

        return redirect()->back()->with('warning', $aviso);
    }
}
