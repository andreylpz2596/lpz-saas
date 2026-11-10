<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\Models\Notificacion;
use ByLopez\CorteConfeccion\Support\NotificacionPresentador;
use Illuminate\Routing\Controller;

/**
 * Avisos del modulo para el usuario autenticado, mostrados en la campana y el listado nativos de Bagisto.
 * Cada usuario solo ve, abre y marca sus propios avisos. Marcar como leido no significa que el trabajo comenzo.
 */
class NotificacionController extends Controller
{
    public function lista()
    {
        $base = Notificacion::query()->where('admin_id', auth()->guard('admin')->id());

        return response()->json([
            'no_leidas' => (clone $base)->whereNull('leida_en')->count(),
            'avisos' => (clone $base)->whereNull('leida_en')->latest('id')->limit(5)->get()->map(fn ($n) => NotificacionPresentador::presentar($n))->values(),
        ]);
    }

    public function abrir(int $id)
    {
        $notificacion = Notificacion::query()->whereKey($id)->where('admin_id', auth()->guard('admin')->id())->firstOrFail();

        if (! $notificacion->leida_en) {
            $notificacion->update(['leida_en' => now()]);
        }

        $primero = $notificacion->datos['cambios'][0] ?? [];

        return redirect()->route('admin.corte_confeccion.ordenes_confeccion.consolidado', array_filter([
            'taller' => $notificacion->datos['taller_id'] ?? null,
            'producto' => $primero['product_id'] ?? null,
            'foco' => $primero['detalle_id'] ?? null,
            'aviso' => $notificacion->id,
        ]));
    }

    public function leerTodas()
    {
        Notificacion::query()->where('admin_id', auth()->guard('admin')->id())->whereNull('leida_en')->update(['leida_en' => now()]);

        return response()->json(['no_leidas' => 0, 'avisos' => []]);
    }
}
