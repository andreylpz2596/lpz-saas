<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\Services\StockMinimoService;
use ByLopez\CorteConfeccion\Support\ConsolidadoConfeccionGate;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Minimos de stock por variante (Doc. 09 §9). Solo personal; un prestador nunca entra aunque su rol traiga la llave. */
class StockMinimoController extends Controller
{
    public function __construct(protected StockMinimoService $stockMinimoService) {}

    public function index(Request $request)
    {
        abort_if(! ConsolidadoConfeccionGate::puedeVerMinimos(), 403);

        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $ambito = $request->query('ambito') === 'todas' ? 'todas' : 'confeccion';
        $paginador = $this->stockMinimoService->buscar($q, $ambito, max((int) $request->query('page', 1), 1))
            ->withPath($request->url())
            ->appends($request->except('page'));

        return view('corteconfeccion::admin.stock-minimos.index', [
            'paginador' => $paginador,
            'q' => $q,
            'ambito' => $ambito,
            'puedeEditar' => ConsolidadoConfeccionGate::puedeEditarMinimos(),
        ]);
    }

    public function guardar(Request $request)
    {
        abort_if(! ConsolidadoConfeccionGate::puedeEditarMinimos(), 403);

        $entradas = $request->input('minimos', []);
        $cambios = $this->stockMinimoService->guardar(is_array($entradas) ? $entradas : [], auth()->guard('admin')->id());

        return back()->with('success', $cambios > 0
            ? trans('corteconfeccion::app.messages.stock-minimos-guardados', ['n' => $cambios])
            : trans('corteconfeccion::app.messages.stock-minimos-sin-cambios'));
    }
}
