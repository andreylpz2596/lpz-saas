<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class InicioController extends Controller
{
    public function __construct(protected AutorizacionVentasAsesor $autorizacion) {}

    /**
     * Pantalla inicial del modulo. El Bouncer de Bagisto exige la clave
     * 'ventas_asesor', pero deja pasar roles con permission_type desconocido que la
     * listen; el modulo solo acepta 'all' y 'custom'.
     */
    public function index(): View
    {
        $admin = auth()->guard('admin')->user();

        abort_unless($this->autorizacion->tienePermiso($admin, AutorizacionVentasAsesor::PERMISO_MODULO), 401);

        return view('ventasasesor::admin.inicio.index', [
            'esAsesor' => $this->autorizacion->esAsesor($admin),
            'alcance' => $this->autorizacion->alcanceDeVisibilidad($admin),
        ]);
    }
}
