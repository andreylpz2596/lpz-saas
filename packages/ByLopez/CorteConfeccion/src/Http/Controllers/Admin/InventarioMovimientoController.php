<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\DataGrids\InventarioMovimientoDataGrid;
use ByLopez\CorteConfeccion\Http\Requests\AjusteManualInventarioRequest;
use ByLopez\CorteConfeccion\Models\InventarioMovimiento;
use ByLopez\CorteConfeccion\Services\InventarioMovimientoService;
use Illuminate\Routing\Controller;

class InventarioMovimientoController extends Controller
{
    public function __construct(
        protected InventarioMovimientoService $inventarioMovimientoService,
    ) {}

    public function index()
    {
        abort_if(! bouncer()->hasPermission('corte_confeccion.inventario_movimientos'), 403);

        if (request()->ajax()) {
            return app(InventarioMovimientoDataGrid::class)->toJson();
        }

        $hoy = now()->toDateString();

        $resumenHoy = InventarioMovimiento::query()
            ->leftJoin('bylopez_cc_ordenes_confeccion as oc', 'oc.id', '=', 'bylopez_cc_inventario_movimientos.orden_confeccion_id')
            ->whereDate('bylopez_cc_inventario_movimientos.created_at', $hoy)
            ->selectRaw('COUNT(*) as movimientos')
            ->selectRaw('SUM(CASE WHEN bylopez_cc_inventario_movimientos.cantidad_movimiento > 0 THEN 1 ELSE 0 END) as entradas')
            ->selectRaw('COALESCE(SUM(CASE WHEN bylopez_cc_inventario_movimientos.cantidad_movimiento > 0 THEN bylopez_cc_inventario_movimientos.cantidad_movimiento ELSE 0 END), 0) as unidades')
            ->selectRaw('COUNT(DISTINCT oc.taller_id) as talleres')
            ->first();

        return view('corteconfeccion::admin.inventario-movimientos.index', [
            'movimientosHoy' => (int) ($resumenHoy->movimientos ?? 0),
            'entradasHoy' => (int) ($resumenHoy->entradas ?? 0),
            'unidadesHoy' => (int) ($resumenHoy->unidades ?? 0),
            'talleresHoy' => (int) ($resumenHoy->talleres ?? 0),
        ]);
    }

    public function show(int $id)
    {
        abort_if(! bouncer()->hasPermission('corte_confeccion.inventario_movimientos'), 403);

        $movimiento = InventarioMovimiento::query()
            ->with(['product', 'inventorySource', 'ordenConfeccion', 'retorno', 'retornoDetalle', 'admin'])
            ->findOrFail($id);

        return view('corteconfeccion::admin.inventario-movimientos.show', compact('movimiento'));
    }

    public function ajusteManual(AjusteManualInventarioRequest $request)
    {
        $movimiento = $this->inventarioMovimientoService->registrarAjusteManual($request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'movimiento' => [
                    'id' => $movimiento->id,
                    'product_id' => $movimiento->product_id,
                    'inventory_source_id' => $movimiento->inventory_source_id,
                    'cantidad_anterior' => $movimiento->cantidad_anterior,
                    'cantidad_movimiento' => $movimiento->cantidad_movimiento,
                    'cantidad_nueva' => $movimiento->cantidad_nueva,
                ],
            ]);
        }

        return redirect()
            ->route('admin.corte_confeccion.inventario_movimientos.show', $movimiento->id)
            ->with('success', trans('corteconfeccion::app.messages.updated'));
    }
}
