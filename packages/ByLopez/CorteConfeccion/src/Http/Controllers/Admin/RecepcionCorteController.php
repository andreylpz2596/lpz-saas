<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\DataGrids\RecepcionCorteDataGrid;
use ByLopez\CorteConfeccion\Http\Requests\StoreRecepcionCorteRequest;
use ByLopez\CorteConfeccion\Repositories\OrdenCorteRepository;
use ByLopez\CorteConfeccion\Repositories\RecepcionCorteRepository;
use ByLopez\CorteConfeccion\Services\ProductLookupService;
use ByLopez\CorteConfeccion\Services\RecepcionCorteService;
use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class RecepcionCorteController extends Controller
{
    public function __construct(
        protected RecepcionCorteRepository $recepcionCorteRepository,
        protected OrdenCorteRepository $ordenCorteRepository,
        protected RecepcionCorteService $recepcionCorteService,
        protected ProductLookupService $productLookupService,
    ) {}

    public function index()
    {
        if (request()->ajax()) {
            return app(RecepcionCorteDataGrid::class)->toJson();
        }

        return view('corteconfeccion::admin.recepciones-corte.index');
    }

    public function create(int $ordenCorteId)
    {
        $orden = $this->ordenCorteRepository->with(['detalles', 'recepcion'])->findOrFail($ordenCorteId);
        $products = $this->productLookupService->options();
        $canEditCantidadCortada = CantidadCortadaGate::userCanEdit(null, $orden);

        return view('corteconfeccion::admin.recepciones-corte.create', compact('orden', 'products', 'canEditCantidadCortada'));
    }

    public function store(StoreRecepcionCorteRequest $request, int $ordenCorteId)
    {
        $orden = $this->ordenCorteRepository->with('detalles')->findOrFail($ordenCorteId);
        $data = $request->validated();

        if ($this->payloadCambiaCantidadRecibida($data, $orden) && ! CantidadCortadaGate::userCanEdit(null, $orden)) {
            throw ValidationException::withMessages([
                'detalles' => trans('corteconfeccion::app.errors.cantidad-cortada-no-autorizada'),
            ]);
        }

        $recepcion = $this->recepcionCorteService->create($orden, $data);

        session()->flash('success', trans('corteconfeccion::app.messages.created'));

        return redirect()->route('admin.corte_confeccion.recepciones_corte.show', $recepcion->id);
    }

    public function show(int $id)
    {
        $recepcion = $this->recepcionCorteRepository->with(['ordenCorte', 'detalles'])->findOrFail($id);

        return view('corteconfeccion::admin.recepciones-corte.show', compact('recepcion'));
    }

    protected function payloadCambiaCantidadRecibida(array $data, $orden): bool
    {
        foreach (($data['detalles'] ?? []) as $index => $detalle) {
            $actual = (int) ($orden->detalles->values()[$index]?->cantidad_real_cortada ?? 0);
            $nuevo = (int) ($detalle['cantidad_recibida'] ?? 0);

            if ($nuevo !== $actual) {
                return true;
            }
        }

        return false;
    }
}
