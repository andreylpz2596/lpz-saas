<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\DataGrids\TallerDataGrid;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion;
use ByLopez\CorteConfeccion\Http\Requests\StoreTallerRequest;
use ByLopez\CorteConfeccion\Http\Requests\UpdateTallerRequest;
use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Repositories\TallerRepository;
use ByLopez\CorteConfeccion\Services\TallerService;
use ByLopez\CorteConfeccion\Services\TarifaPrestadorService;
use ByLopez\CorteConfeccion\Support\TallerConfeccionAccess;
use ByLopez\CorteConfeccion\Support\TallerConfeccionResolver;
use ByLopez\CorteConfeccion\Support\TarifaPrestadorGate;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class TallerController extends Controller
{
    public function __construct(
        protected TallerRepository $tallerRepository,
        protected TallerService $tallerService,
        protected TarifaPrestadorService $tarifaService,
    ) {}

    public function index()
    {
        if (request()->ajax()) {
            return app(TallerDataGrid::class)->toJson();
        }

        return view('corteconfeccion::admin.talleres.index');
    }

    public function buscar(Request $request)
    {
        $termino = $request->query('query');

        $query = Taller::query()
            ->where('estado', 'activo')
            ->when($termino, fn ($query) => $query->where('nombre', 'like', '%'.$termino.'%'))
            ->orderBy('nombre');

        TallerConfeccionAccess::filtrarTalleresSiAplica($query);

        $resultados = $query
            ->limit(20)
            ->get()
            ->map(fn ($taller) => [
                'id' => $taller->id,
                'label' => $taller->nombre,
                'sublabel' => trim(collect([$taller->contacto, $taller->telefono])->filter()->implode(' · ')),
            ]);

        return response()->json(['data' => $resultados]);
    }

    public function show(int $id)
    {
        $taller = $this->tallerRepository->with('usuario')->findOrFail($id);

        abort_if(! TallerConfeccionAccess::puedeAccederTaller($taller), 403);

        $tallerPayload = $this->formatearTaller($taller);
        $usuariosTaller = TallerConfeccionResolver::options();
        $resumen = $this->resumenTaller($taller->id);
        $opcionesTarifa = $this->tarifaService->opcionesParaModal(TarifaPrestadorGate::TIPO_TALLER);
        $tarifaGeneralAnterior = $this->tarifaService->avisoTarifaGeneral($taller);
        $puedeGestionarTarifas = TarifaPrestadorGate::puedeGestionar(TarifaPrestadorGate::TIPO_TALLER);

        return view('corteconfeccion::admin.talleres.show', compact('taller', 'tallerPayload', 'usuariosTaller', 'resumen', 'opcionesTarifa', 'tarifaGeneralAnterior', 'puedeGestionarTarifas'));
    }

    /**
     * Indicadores rapidos de un taller: ordenes activas, prendas asignadas y los
     * dos pendientes (con el taller y con bodega). Misma composicion de
     * subconsultas por orden que usa TallerDataGrid, acotada a un solo taller
     * para la franja de resumen del detalle.
     *
     * @return array{ordenes_activas:int, prendas_asignadas:int, pendiente_taller:int, pendiente_recepcion:int}
     */
    protected function resumenTaller(int $tallerId): array
    {
        $detallesPorOrden = DB::table('bylopez_cc_ordenes_confeccion_detalles')
            ->select('orden_confeccion_id', DB::raw('SUM(cantidad_entregada) as entregado'))
            ->groupBy('orden_confeccion_id');

        $retornosPorOrden = DB::table('bylopez_cc_ordenes_confeccion_retornos as r')
            ->join('bylopez_cc_ordenes_confeccion_retorno_detalles as rd', 'rd.retorno_id', '=', 'r.id')
            ->select(
                'r.orden_confeccion_id',
                DB::raw('SUM(rd.cantidad_reportada) as reportado'),
                DB::raw('SUM(COALESCE(rd.cantidad_recibida, 0)) as recibido')
            )
            ->groupBy('r.orden_confeccion_id');

        $fila = DB::table('bylopez_cc_ordenes_confeccion as oc')
            ->leftJoinSub($detallesPorOrden, 'd', 'oc.id', '=', 'd.orden_confeccion_id')
            ->leftJoinSub($retornosPorOrden, 'rt', 'oc.id', '=', 'rt.orden_confeccion_id')
            ->where('oc.taller_id', $tallerId)
            ->whereIn('oc.estado', [
                EstadoOrdenConfeccion::Activa->value,
                EstadoOrdenConfeccion::EnConfeccion->value,
                EstadoOrdenConfeccion::TerminadaTaller->value,
            ])
            ->select(
                DB::raw('COUNT(*) as ordenes_activas'),
                DB::raw('COALESCE(SUM(COALESCE(d.entregado, 0)), 0) as prendas_asignadas'),
                // Pendiente con el taller: lo entregado que todavia no reporto de vuelta.
                DB::raw('COALESCE(SUM(GREATEST(COALESCE(d.entregado, 0) - COALESCE(rt.reportado, 0), 0)), 0) as pendiente_taller'),
                // Pendiente de recepcion: lo que el taller ya reporto pero bodega aun no confirma recibido.
                DB::raw('COALESCE(SUM(GREATEST(COALESCE(rt.reportado, 0) - COALESCE(rt.recibido, 0), 0)), 0) as pendiente_recepcion')
            )
            ->first();

        return [
            'ordenes_activas' => (int) $fila->ordenes_activas,
            'prendas_asignadas' => (int) $fila->prendas_asignadas,
            'pendiente_taller' => (int) $fila->pendiente_taller,
            'pendiente_recepcion' => (int) $fila->pendiente_recepcion,
        ];
    }

    public function create()
    {
        abort_if(TallerConfeccionAccess::esUsuarioTaller(), 403);

        return view('corteconfeccion::admin.talleres.create');
    }

    public function store(StoreTallerRequest $request)
    {
        $taller = $this->tallerService->create($request->validated());

        session()->flash('success', trans('corteconfeccion::app.messages.created'));

        return redirect()->route('admin.corte_confeccion.talleres.show', $taller->id);
    }

    public function edit(int $id)
    {
        abort_if(TallerConfeccionAccess::esUsuarioTaller(), 403);

        return redirect()->route('admin.corte_confeccion.talleres.show', $id);
    }

    public function update(UpdateTallerRequest $request, int $id)
    {
        $taller = $this->tallerRepository->findOrFail($id);
        $taller = $this->tallerService->update($taller, $request->validated())->load('usuario');

        if ($request->expectsJson()) {
            return response()->json([
                'message' => trans('corteconfeccion::app.messages.updated'),
                'data' => [
                    'taller' => $this->formatearTaller($taller),
                ],
            ]);
        }

        session()->flash('success', trans('corteconfeccion::app.messages.updated'));

        return redirect()->route('admin.corte_confeccion.talleres.show', $id);
    }

    protected function formatearTaller(Taller $taller): array
    {
        return [
            'id' => $taller->id,
            'nombre' => $taller->nombre,
            'nit' => $taller->nit,
            'contacto' => $taller->contacto,
            'telefono' => $taller->telefono,
            'direccion' => $taller->direccion,
            'estado' => $taller->estado,
            'observacion' => $taller->observacion,
            'usuario_id' => $taller->usuario_id,
            'usuario' => $taller->usuario ? [
                'id' => $taller->usuario->id,
                'name' => $taller->usuario->name,
                'email' => $taller->usuario->email,
            ] : null,
        ];
    }
}
