<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\DataGrids\CompraTelaDataGrid;
use ByLopez\CorteConfeccion\Http\Requests\StoreCompraTelaRequest;
use ByLopez\CorteConfeccion\Http\Requests\UpdateCompraTelaRequest;
use ByLopez\CorteConfeccion\Models\CompraTela;
use ByLopez\CorteConfeccion\Models\ProveedorTela;
use ByLopez\CorteConfeccion\Models\ProveedorTelaReferencia;
use ByLopez\CorteConfeccion\Repositories\CompraTelaRepository;
use ByLopez\CorteConfeccion\Repositories\ProveedorTelaRepository;
use ByLopez\CorteConfeccion\Services\CompraTelaService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CompraTelaController extends Controller
{
    public function __construct(
        protected CompraTelaRepository $compraTelaRepository,
        protected ProveedorTelaRepository $proveedorTelaRepository,
        protected CompraTelaService $compraTelaService,
    ) {}

    public function index()
    {
        if (request()->ajax()) {
            return app(CompraTelaDataGrid::class)->toJson();
        }

        return view('corteconfeccion::admin.compras-tela.index');
    }

    public function create()
    {
        $proveedores = $this->proveedoresParaFormulario();
        $codigoSugerido = $this->codigoSugerido();

        return view('corteconfeccion::admin.compras-tela.create', compact('proveedores', 'codigoSugerido'));
    }

    public function store(StoreCompraTelaRequest $request)
    {
        $compra = $this->compraTelaService->create($request->validated());

        session()->flash('success', trans('corteconfeccion::app.messages.created'));

        return redirect()->route('admin.corte_confeccion.compras_tela.show', $compra->id);
    }

    public function referencias(Request $request)
    {
        $validated = $request->validate([
            'proveedor_tela_id' => ['required', 'integer', 'exists:bylopez_cc_proveedores_tela,id'],
            'tipo_tela_id'      => ['required', 'integer', 'exists:attribute_options,id'],
            'search'            => ['nullable', 'string', 'max:255'],
        ]);

        $search = trim($validated['search'] ?? '');

        return ProveedorTelaReferencia::query()
            ->where('proveedor_tela_id', $validated['proveedor_tela_id'])
            ->where('tipo_tela_id', $validated['tipo_tela_id'])
            ->where('estado', 'activo')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query
                        ->where('color', 'like', "%{$search}%")
                        ->orWhere('referencia', 'like', "%{$search}%")
                        ->orWhere('gramaje', 'like', "%{$search}%");
                });
            })
            ->orderBy('color')
            ->orderBy('referencia')
            ->limit(20)
            ->get()
            ->map(fn (ProveedorTelaReferencia $referencia) => [
                'id'                    => $referencia->id,
                'label'                 => $this->referenciaLabel($referencia),
                'color'                 => $referencia->color,
                'referencia'            => $referencia->referencia,
                'gramaje'               => $referencia->gramaje,
                'valor_kilo_referencia' => $referencia->valor_kilo_referencia,
            ]);
    }

    public function show(int $id)
    {
        $compra = $this->compraTelaRepository->with(['proveedor', 'rollos.tipoTela', 'rollos.referenciaTela.tipoTela'])->findOrFail($id);

        return view('corteconfeccion::admin.compras-tela.show', compact('compra'));
    }

    public function edit(int $id)
    {
        $compra = $this->compraTelaRepository
            ->with(['proveedor', 'rollos.ordenesCorte', 'rollos.tipoTela', 'rollos.referenciaTela.tipoTela'])
            ->findOrFail($id);

        $proveedores = $this->proveedoresParaFormulario($compra);

        return view('corteconfeccion::admin.compras-tela.edit', compact('compra', 'proveedores'));
    }

    public function update(UpdateCompraTelaRequest $request, int $id)
    {
        $compra = $this->compraTelaRepository->findOrFail($id);
        $compra = $this->compraTelaService->update($compra, $request->validated());

        session()->flash('success', trans('corteconfeccion::app.messages.updated'));

        return redirect()->route('admin.corte_confeccion.compras_tela.show', $compra->id);
    }

    protected function proveedoresParaFormulario(?CompraTela $compra = null)
    {
        return ProveedorTela::query()
            ->where(function ($query) use ($compra) {
                $query->where('estado', 'activo');

                if ($compra?->proveedor_id) {
                    $query->orWhere('id', $compra->proveedor_id);
                }
            })
            ->with([
                'tiposTela' => fn ($query) => $query->orderBy('sort_order')->orderBy('admin_name'),
                'referenciasTela.tipoTela',
            ])
            ->orderBy('nombre')
            ->get();
    }

    protected function referenciaLabel(ProveedorTelaReferencia $referencia): string
    {
        $valor = $referencia->valor_kilo_referencia === null
            ? 'sin valor ref.'
            : '$'.number_format((float) $referencia->valor_kilo_referencia, 0, ',', '.').'/kg';

        return "{$referencia->color} - {$referencia->referencia} - {$referencia->gramaje}gr - {$valor}";
    }

    protected function codigoSugerido(): string
    {
        do {
            $codigo = 'CT-'.now()->format('YmdHis').'-'.random_int(100, 999);
        } while (CompraTela::query()->where('codigo', $codigo)->exists());

        return $codigo;
    }
}
