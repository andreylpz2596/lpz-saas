<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\DataGrids\OrdenCorteDataGrid;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Http\Requests\AdicionalesOrdenCorteRequest;
use ByLopez\CorteConfeccion\Http\Requests\CerrarOrdenCorteRequest;
use ByLopez\CorteConfeccion\Http\Requests\EntregarOrdenCorteRequest;
use ByLopez\CorteConfeccion\Http\Requests\ReportarResultadoCorteRequest;
use ByLopez\CorteConfeccion\Http\Requests\RetirarRolloOrdenCorteRequest;
use ByLopez\CorteConfeccion\Http\Requests\SeleccionOrdenCorteRequest;
use ByLopez\CorteConfeccion\Http\Requests\StoreOrdenCorteDetalleRequest;
use ByLopez\CorteConfeccion\Http\Requests\StoreOrdenCorteRequest;
use ByLopez\CorteConfeccion\Http\Requests\StoreOrdenCorteRolloRequest;
use ByLopez\CorteConfeccion\Repositories\OrdenCorteRepository;
use ByLopez\CorteConfeccion\Repositories\RolloRepository;
use ByLopez\CorteConfeccion\Services\DisponibilidadConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenCorteService;
use ByLopez\CorteConfeccion\Services\OrdenTarifaService;
use ByLopez\CorteConfeccion\Services\ProductLookupService;
use ByLopez\CorteConfeccion\Services\SeleccionOrdenCorteService;
use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use ByLopez\CorteConfeccion\Support\CortadorAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class OrdenCorteController extends Controller
{
    public function __construct(
        protected OrdenCorteRepository $ordenCorteRepository,
        protected RolloRepository $rolloRepository,
        protected OrdenCorteService $ordenCorteService,
        protected ProductLookupService $productLookupService,
        protected DisponibilidadConfeccionService $disponibilidadConfeccionService,
        protected SeleccionOrdenCorteService $seleccionOrdenCorteService,
    ) {}

    public function index()
    {
        if (request()->ajax()) {
            return app(OrdenCorteDataGrid::class)->toJson();
        }

        return view('corteconfeccion::admin.ordenes-corte.index');
    }

    public function create()
    {
        $rollos = $this->rolloRepository
            ->findWhereIn('estado', ['disponible', 'parcialmente_usado'])
            ->sortBy('codigo')
            ->values();

        return view('corteconfeccion::admin.ordenes-corte.create', compact('rollos'));
    }

    /**
     * F4: tela, colores REALES de los rollos y matriz linea x color con las
     * tallas/ejes que existen (ResolvedorVarianteCorteService). Solo lectura.
     */
    public function opciones(SeleccionOrdenCorteRequest $request): JsonResponse
    {
        $rollos = $this->ordenCorteService->rollosValidadosParaCorte($request->validated('rollo_ids'));

        return response()->json(['data' => $this->seleccionOrdenCorteService->matriz($rollos)]);
    }

    /** F4: resuelve la seleccion a simples exactos (resolverLote) para revisarla antes de crear. */
    public function previsualizar(SeleccionOrdenCorteRequest $request): JsonResponse
    {
        $rollos = $this->ordenCorteService->rollosValidadosParaCorte($request->validated('rollo_ids'));

        return response()->json(['data' => $this->seleccionOrdenCorteService->resolver($rollos, (array) $request->validated('seleccion'))]);
    }

    /** F5: matriz linea x color (colores = rollos de la orden) para agregar adicionales. Solo lectura. */
    public function adicionalesOpciones(AdicionalesOrdenCorteRequest $request, int $id): JsonResponse
    {
        $orden = $this->ordenCorteRepository->findOrFail($id);

        abort_if(! CortadorAccess::puedeOperarOrden($orden), 403);

        return response()->json(['data' => $this->seleccionOrdenCorteService->matriz($this->ordenCorteService->rollosParaAdicionales($orden))]);
    }

    /** F5: resuelve los adicionales a simples exactos (resolverLote) antes de reportar. */
    public function adicionalesPrevisualizar(AdicionalesOrdenCorteRequest $request, int $id): JsonResponse
    {
        $orden = $this->ordenCorteRepository->findOrFail($id);

        abort_if(! CortadorAccess::puedeOperarOrden($orden), 403);

        $adicionales = (array) $request->validated('adicionales');

        return response()->json(['data' => $adicionales === []
            ? ['filas' => [], 'errores' => []]
            : $this->seleccionOrdenCorteService->resolver($this->ordenCorteService->rollosParaAdicionales($orden), $adicionales)]);
    }

    public function productos(): JsonResponse
    {
        abort_if(
            ! bouncer()->hasPermission('corte_confeccion.ordenes_corte')
            && ! bouncer()->hasPermission('corte_confeccion.ordenes_corte.create')
            && ! bouncer()->hasPermission('corte_confeccion.ordenes_corte.operar'),
            403
        );

        return response()->json([
            'data' => $this->productLookupService->search((string) request('query')),
        ]);
    }

    public function operarIndex()
    {
        return redirect()->route('admin.corte_confeccion.ordenes_corte.index');
    }

    public function store(StoreOrdenCorteRequest $request)
    {
        $orden = $this->ordenCorteService->crearDesdeSeleccion($request->validated());

        session()->flash('success', trans('corteconfeccion::app.messages.created'));

        return redirect()->route('admin.corte_confeccion.ordenes_corte.show', $orden->id);
    }

    public function show(int $id)
    {
        $orden = $this->ordenCorteRepository
            ->with(['rollo', 'rollosCorte.rollo.compraTela', 'detalles', 'recepcion.detalles', 'ordenesConfeccion.taller', 'ordenesConfeccion.detalles', 'creadoPor', 'reportadoPor'])
            ->findOrFail($id);

        abort_if(! CortadorAccess::puedeVerOrden($orden), 403);

        $products = $this->productLookupService->options();
        $canEditCantidadCortada = CantidadCortadaGate::userCanEdit(null, $orden);
        $canEditOrder = bouncer()->hasPermission('corte_confeccion.ordenes_corte.operar')
            && $this->ordenCorteService->puedeEditarSolicitud($orden);
        $canDeliverRolls = bouncer()->hasPermission('corte_confeccion.ordenes_corte.operar')
            && $this->ordenCorteService->puedeEntregarRollos($orden)
            && $orden->rollosCorte->contains(fn ($ordenRollo) => ! $ordenRollo->entregado_en);
        $canConfirmarRecepcion = CantidadCortadaGate::userCanConfirmarRecepcion();
        $usuariosRecepcion = CantidadCortadaGate::usuariosConfirmarRecepcion();
        $resumenDistribucion = $this->disponibilidadConfeccionService->resumenPorOrdenCorte($orden);
        $rollosDisponibles = $this->rolloRepository
            ->findWhereIn('estado', ['disponible', 'parcialmente_usado'])
            ->sortBy('codigo')
            ->values();

        $tarifasOrden = app(OrdenTarifaService::class)->resumen($orden);

        return view('corteconfeccion::admin.ordenes-corte.show', compact('orden', 'products', 'canEditCantidadCortada', 'canEditOrder', 'canDeliverRolls', 'canConfirmarRecepcion', 'usuariosRecepcion', 'resumenDistribucion', 'rollosDisponibles', 'tarifasOrden'));
    }

    public function storeDetalle(StoreOrdenCorteDetalleRequest $request, int $id)
    {
        $orden = $this->ordenCorteRepository->findOrFail($id);

        abort_if(! CortadorAccess::puedeOperarOrden($orden), 403);

        $this->ordenCorteService->agregarDetalle($orden, $request->validated());

        return redirect()->route('admin.corte_confeccion.ordenes_corte.show', $id)
            ->with('success', trans('corteconfeccion::app.messages.created'));
    }

    public function storeRollo(StoreOrdenCorteRolloRequest $request, int $id)
    {
        $orden = $this->ordenCorteRepository->findOrFail($id);

        abort_if(! CortadorAccess::puedeOperarOrden($orden), 403);

        $this->ordenCorteService->agregarRollo($orden, (int) $request->validated('rollo_id'));

        return redirect()->route('admin.corte_confeccion.ordenes_corte.show', $id)
            ->with('success', trans('corteconfeccion::app.messages.created'));
    }

    public function retirarRollo(RetirarRolloOrdenCorteRequest $request, int $id, int $rolloId)
    {
        $orden = $this->ordenCorteRepository->findOrFail($id);

        abort_if(! CortadorAccess::puedeOperarOrden($orden), 403);

        $this->ordenCorteService->retirarRollo($orden, $rolloId);

        return redirect()->route('admin.corte_confeccion.ordenes_corte.show', $id)
            ->with('success', trans('corteconfeccion::app.messages.rollo-retirado'));
    }

    public function entregar(EntregarOrdenCorteRequest $request, int $id)
    {
        $orden = $this->ordenCorteRepository->findOrFail($id);

        abort_if(! CortadorAccess::puedeOperarOrden($orden), 403);

        $this->ordenCorteService->entregar($orden);

        session()->flash('success', trans('corteconfeccion::app.messages.updated'));

        return redirect()->route('admin.corte_confeccion.ordenes_corte.show', $id);
    }

    public function marcarEnCorte(int $id)
    {
        abort_if(! bouncer()->hasPermission('corte_confeccion.ordenes_corte.operar'), 403);

        $orden = $this->ordenCorteRepository->findOrFail($id);

        abort_if(! CortadorAccess::puedeOperarOrden($orden), 403);

        $this->ordenCorteService->cambiarEstado($orden, EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);

        return back()->with('success', trans('corteconfeccion::app.messages.updated'));
    }

    public function reportar(ReportarResultadoCorteRequest $request, int $id)
    {
        $orden = $this->ordenCorteRepository->findOrFail($id);

        abort_if(! CortadorAccess::puedeOperarOrden($orden), 403);

        $this->ordenCorteService->reportarResultado($orden, $request->validated());

        return redirect()->route('admin.corte_confeccion.ordenes_corte.show', $id)
            ->with('success', trans('corteconfeccion::app.messages.updated'));
    }

    public function cerrar(CerrarOrdenCorteRequest $request, int $id)
    {
        $orden = $this->ordenCorteRepository->findOrFail($id);

        abort_if(! CortadorAccess::puedeOperarOrden($orden), 403);

        $this->ordenCorteService->cerrar($orden);

        return back()->with('success', trans('corteconfeccion::app.messages.closed'));
    }

    public function cancel(int $id)
    {
        abort_if(! bouncer()->hasPermission('corte_confeccion.ordenes_corte.operar'), 403);

        $orden = $this->ordenCorteRepository->findOrFail($id);

        abort_if(! CortadorAccess::puedeOperarOrden($orden), 403);

        $this->ordenCorteService->cancel($orden);

        return back()->with('success', trans('corteconfeccion::app.messages.cancelled'));
    }
}
