<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\Support\CortadorResolver;
use ByLopez\CorteConfeccion\Support\TallerConfeccionResolver;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class UsuarioRolController extends Controller
{
    public function cortadores(Request $request)
    {
        return response()->json(['data' => $this->formatear(CortadorResolver::buscar($request->query('query')))]);
    }

    public function usuariosTaller(Request $request)
    {
        return response()->json(['data' => $this->formatear(TallerConfeccionResolver::buscar($request->query('query')))]);
    }

    protected function formatear($admins)
    {
        return $admins->map(fn ($admin) => [
            'id' => $admin->id,
            'label' => $admin->name,
            'sublabel' => $admin->email,
        ]);
    }
}
