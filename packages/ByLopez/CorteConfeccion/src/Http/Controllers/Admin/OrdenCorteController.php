<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\DataGrids\OrdenCorteDataGrid;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Http\Requests\CerrarOrdenCorteRequest;
use ByLopez\CorteConfeccion\Http\Requests\EntregarOrdenCorteRequest;
use ByLopez\CorteConfeccion\Http\Requests\StoreOrdenCorteRequest;
use ByLopez\CorteConfeccion\Repositories\OrdenCorteRepository;
use ByLopez\CorteConfeccion\Repositories\RolloRepository;
use ByLopez\CorteConfeccion\Services\OrdenCorteService;
use ByLopez\CorteConfeccion\Services\ProductLookupService;
use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use ByLopez\CorteConfeccion\Support\CortadorResolver;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class OrdenCorteController extends Controller
{
    public function __construct(
        protected OrdenCorteRepository $ordenCorteRepository,
        protected RolloRepository $rolloRepository,
        protected OrdenCorteService $ordenCorteService,
        protected ProductLookupService $productLookupService,
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

        $products = $this->productLookupService->options();
        $canEditCantidadCortada = CantidadCortadaGate::userCanEdit();
        $cortadores = CortadorResolver::options();

        return view('corteconfeccion::admin.ordenes-corte.create', compact('rollos', 'products', 'canEditCantidadCortada', 'cortadores'));
    }

    public function store(StoreOrdenCorteRequest $request)
    {
        if ($this->payloadHasCantidadCortada($request->validated()) && ! CantidadCortadaGate::userCanEdit()) {
            throw ValidationException::withMessages([
                'detalles' => trans('corteconfeccion::app.errors.cantidad-cortada-no-autorizada'),
            ]);
        }

        $this->ordenCorteService->create($request->validated());

        session()->flash('success', trans('corteconfeccion::app.messages.created'));

        return redirect()->route('admin.corte_confeccion.ordenes_corte.index');
    }

    public function show(int $id)
    {
        $orden = $this->ordenCorteRepository->with(['rollo', 'rollosCorte.rollo.compraTela', 'detalles', 'recepcion.detalles'])->findOrFail($id);

        return view('corteconfeccion::admin.ordenes-corte.show', compact('orden'));
    }

    public function entregarForm(int $id)
    {
        $orden = $this->ordenCorteRepository->with(['rollo', 'rollosCorte.rollo'])->findOrFail($id);

        return view('corteconfeccion::admin.ordenes-corte.entregar', compact('orden'));
    }

    public function entregar(EntregarOrdenCorteRequest $request, int $id)
    {
        $orden = $this->ordenCorteRepository->findOrFail($id);

        $this->ordenCorteService->entregar($orden, (float) $request->validated('peso_entregado'));

        session()->flash('success', trans('corteconfeccion::app.messages.updated'));

        return redirect()->route('admin.corte_confeccion.ordenes_corte.show', $id);
    }

    public function marcarEnCorte(int $id)
    {
        $orden = $this->ordenCorteRepository->findOrFail($id);

        $this->ordenCorteService->cambiarEstado($orden, EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);

        return back()->with('success', trans('corteconfeccion::app.messages.updated'));
    }

    public function reportar(int $id)
    {
        $orden = $this->ordenCorteRepository->findOrFail($id);

        $this->ordenCorteService->cambiarEstado($orden, EstadoOrdenCorte::EnCorte->value, EstadoOrdenCorte::ReportadoPorCortador->value);

        return back()->with('success', trans('corteconfeccion::app.messages.updated'));
    }

    public function cerrar(CerrarOrdenCorteRequest $request, int $id)
    {
        $orden = $this->ordenCorteRepository->findOrFail($id);

        $this->ordenCorteService->cerrar($orden);

        return back()->with('success', trans('corteconfeccion::app.messages.closed'));
    }

    public function cancel(int $id)
    {
        $orden = $this->ordenCorteRepository->findOrFail($id);

        $this->ordenCorteService->cancel($orden);

        return back()->with('success', trans('corteconfeccion::app.messages.cancelled'));
    }

    protected function payloadHasCantidadCortada(array $data): bool
    {
        foreach ($data['detalles'] ?? [] as $detalle) {
            $cantidad = $detalle['cantidad_cortada'] ?? $detalle['cantidad_real_cortada'] ?? null;

            if ($cantidad !== null && $cantidad !== '' && (int) $cantidad !== 0) {
                return true;
            }
        }

        return false;
    }

}
