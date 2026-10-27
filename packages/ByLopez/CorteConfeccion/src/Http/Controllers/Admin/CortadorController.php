<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\DataGrids\CortadorDataGrid;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Http\Requests\StoreCortadorRequest;
use ByLopez\CorteConfeccion\Http\Requests\UpdateCortadorRequest;
use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Repositories\CortadorRepository;
use ByLopez\CorteConfeccion\Services\CortadorService;
use ByLopez\CorteConfeccion\Services\TarifaPrestadorService;
use ByLopez\CorteConfeccion\Support\CortadorAccess;
use ByLopez\CorteConfeccion\Support\CortadorResolver;
use ByLopez\CorteConfeccion\Support\TarifaPrestadorGate;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class CortadorController extends Controller
{
    public function __construct(
        protected CortadorRepository $cortadorRepository,
        protected CortadorService $cortadorService,
        protected TarifaPrestadorService $tarifaService,
    ) {}

    public function index()
    {
        if (request()->ajax()) {
            return app(CortadorDataGrid::class)->toJson();
        }

        return view('corteconfeccion::admin.cortadores.index', [
            'usuariosCortador' => CortadorResolver::options(),
        ]);
    }

    public function show(int $id)
    {
        $cortador = $this->cortadorRepository->with('usuario')->findOrFail($id);

        abort_if(! CortadorAccess::puedeAccederCortador($cortador), 403);

        $cortadorPayload = $this->formatearCortador($cortador);
        $usuariosCortador = CortadorResolver::options();
        $resumen = $this->resumenCortador($cortador);
        $opcionesTarifa = $this->tarifaService->opcionesParaModal(TarifaPrestadorGate::TIPO_CORTADOR);
        $tarifaGeneralAnterior = $this->tarifaService->avisoTarifaGeneral($cortador);
        $puedeGestionarTarifas = TarifaPrestadorGate::puedeGestionar(TarifaPrestadorGate::TIPO_CORTADOR);

        return view('corteconfeccion::admin.cortadores.show', compact('cortador', 'cortadorPayload', 'usuariosCortador', 'resumen', 'opcionesTarifa', 'tarifaGeneralAnterior', 'puedeGestionarTarifas'));
    }

    public function create()
    {
        abort_if(CortadorAccess::esUsuarioCortador(), 403);

        return redirect()->route('admin.corte_confeccion.cortadores.index');
    }

    public function store(StoreCortadorRequest $request)
    {
        $cortador = $this->cortadorService->create($request->validated())->load('usuario');

        if ($request->expectsJson()) {
            return response()->json([
                'message' => trans('corteconfeccion::app.messages.created'),
                'data' => [
                    'cortador' => $this->formatearCortador($cortador),
                ],
            ]);
        }

        session()->flash('success', trans('corteconfeccion::app.messages.created'));

        return redirect()->route('admin.corte_confeccion.cortadores.show', $cortador->id);
    }

    public function edit(int $id)
    {
        abort_if(CortadorAccess::esUsuarioCortador(), 403);

        return redirect()->route('admin.corte_confeccion.cortadores.show', $id);
    }

    public function update(UpdateCortadorRequest $request, int $id)
    {
        $cortador = $this->cortadorRepository->findOrFail($id);
        $cortador = $this->cortadorService->update($cortador, $request->validated())->load('usuario');

        if ($request->expectsJson()) {
            return response()->json([
                'message' => trans('corteconfeccion::app.messages.updated'),
                'data' => [
                    'cortador' => $this->formatearCortador($cortador),
                ],
            ]);
        }

        session()->flash('success', trans('corteconfeccion::app.messages.updated'));

        return redirect()->route('admin.corte_confeccion.cortadores.show', $id);
    }

    /**
     * @return array{ordenes_activas:int, prendas_cortadas:int, pendiente_cortador:int, pendiente_recepcion:int}
     */
    protected function resumenCortador(Cortador $cortador): array
    {
        if (! $cortador->usuario_id) {
            return [
                'ordenes_activas' => 0,
                'prendas_cortadas' => 0,
                'pendiente_cortador' => 0,
                'pendiente_recepcion' => 0,
            ];
        }

        $detallesPorOrden = DB::table('bylopez_cc_ordenes_corte_detalles')
            ->select('orden_corte_id', DB::raw('SUM(cantidad_real_cortada) as prendas_cortadas'))
            ->groupBy('orden_corte_id');

        $fila = DB::table('bylopez_cc_ordenes_corte as oc')
            ->leftJoinSub($detallesPorOrden, 'd', 'oc.id', '=', 'd.orden_corte_id')
            ->where('oc.cortador_id', $cortador->usuario_id)
            ->select(
                DB::raw('COUNT(CASE WHEN oc.estado IN ("'.implode('","', [
                    EstadoOrdenCorte::PendienteEntrega->value,
                    EstadoOrdenCorte::EntregadoACortador->value,
                    EstadoOrdenCorte::EnCorte->value,
                ]).'") THEN 1 END) as ordenes_activas'),
                DB::raw('COALESCE(SUM(CASE WHEN oc.estado <> "'.EstadoOrdenCorte::Anulado->value.'" THEN COALESCE(d.prendas_cortadas, 0) ELSE 0 END), 0) as prendas_cortadas'),
                DB::raw('COUNT(CASE WHEN oc.estado IN ("'.implode('","', [
                    EstadoOrdenCorte::PendienteEntrega->value,
                    EstadoOrdenCorte::EntregadoACortador->value,
                    EstadoOrdenCorte::EnCorte->value,
                ]).'") THEN 1 END) as pendiente_cortador'),
                DB::raw('COUNT(CASE WHEN oc.estado = "'.EstadoOrdenCorte::ReportadoPorCortador->value.'" THEN 1 END) as pendiente_recepcion')
            )
            ->first();

        return [
            'ordenes_activas' => (int) $fila->ordenes_activas,
            'prendas_cortadas' => (int) $fila->prendas_cortadas,
            'pendiente_cortador' => (int) $fila->pendiente_cortador,
            'pendiente_recepcion' => (int) $fila->pendiente_recepcion,
        ];
    }

    protected function formatearCortador(Cortador $cortador): array
    {
        return [
            'id' => $cortador->id,
            'nombre' => $cortador->nombre,
            'nit' => $cortador->nit,
            'contacto' => $cortador->contacto,
            'telefono' => $cortador->telefono,
            'direccion' => $cortador->direccion,
            'estado' => $cortador->estado,
            'observacion' => $cortador->observacion,
            'usuario_id' => $cortador->usuario_id,
            'usuario' => $cortador->usuario ? [
                'id' => $cortador->usuario->id,
                'name' => $cortador->usuario->name,
                'email' => $cortador->usuario->email,
            ] : null,
        ];
    }
}
