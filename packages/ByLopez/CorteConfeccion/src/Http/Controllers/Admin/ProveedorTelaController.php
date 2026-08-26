<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\DataGrids\ProveedorTelaDataGrid;
use ByLopez\CorteConfeccion\Http\Requests\StoreProveedorTelaRequest;
use ByLopez\CorteConfeccion\Http\Requests\UpdateProveedorTelaRequest;
use ByLopez\CorteConfeccion\Repositories\ProveedorTelaRepository;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use Illuminate\Routing\Controller;
use Webkul\Attribute\Models\Attribute;

class ProveedorTelaController extends Controller
{
    public function __construct(
        protected ProveedorTelaRepository $proveedorTelaRepository,
        protected ProveedorTelaService $proveedorTelaService,
    ) {}

    public function index()
    {
        if (request()->ajax()) {
            return app(ProveedorTelaDataGrid::class)->toJson();
        }

        return view('corteconfeccion::admin.proveedores-tela.index');
    }

    public function create()
    {
        $tiposTela = $this->tiposTela();

        return view('corteconfeccion::admin.proveedores-tela.create', compact('tiposTela'));
    }

    public function store(StoreProveedorTelaRequest $request)
    {
        $proveedor = $this->proveedorTelaService->create($request->validated());

        session()->flash('success', trans('corteconfeccion::app.messages.created'));

        return redirect()->route('admin.corte_confeccion.proveedores_tela.edit', $proveedor->id);
    }

    public function edit(int $id)
    {
        $proveedor = $this->proveedorTelaRepository
            ->with(['tiposTela', 'referenciasTela.tipoTela', 'referenciasTela.rollos'])
            ->findOrFail($id);

        $tiposTela = $this->tiposTela();

        return view('corteconfeccion::admin.proveedores-tela.edit', compact('proveedor', 'tiposTela'));
    }

    public function update(UpdateProveedorTelaRequest $request, int $id)
    {
        $proveedor = $this->proveedorTelaRepository->findOrFail($id);
        $this->proveedorTelaService->update($proveedor, $request->validated());

        session()->flash('success', trans('corteconfeccion::app.messages.updated'));

        return redirect()->route('admin.corte_confeccion.proveedores_tela.edit', $id);
    }

    protected function tiposTela()
    {
        return Attribute::query()
            ->where('code', 'tipo_tela')
            ->with(['options' => fn ($query) => $query->orderBy('sort_order')->orderBy('admin_name')])
            ->first()
            ?->options
            ?? collect();
    }
}
