<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\DataGrids\ProveedorTelaDataGrid;
use ByLopez\CorteConfeccion\DataGrids\ProveedorTelaReferenciaDataGrid;
use ByLopez\CorteConfeccion\Http\Requests\StoreProveedorTelaRequest;
use ByLopez\CorteConfeccion\Http\Requests\UpdateProveedorTelaRequest;
use ByLopez\CorteConfeccion\Models\ProveedorTela;
use ByLopez\CorteConfeccion\Models\ProveedorTelaReferencia;
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

        $tiposTela = $this->tiposTela();

        return view('corteconfeccion::admin.proveedores-tela.index', compact('tiposTela'));
    }

    /**
     * Ficha de detalle: identidad, resumen comercial, compras (bloque principal)
     * y referencias que ofrece (bloque secundario). No confundir con edit(): esta
     * pantalla es solo consulta, salvo por los dos modales que abre (editar datos
     * basicos del proveedor y agregar/editar una referencia).
     */
    public function show(int $id)
    {
        $proveedor = $this->proveedorTelaRepository->with('tiposTela')->findOrFail($id);
        $proveedorPayload = $this->formatearProveedor($proveedor);
        $resumen = $this->resumenProveedor($proveedor);
        $tiposTelaProveedor = $proveedor->tiposTela;
        $tiposTelaCatalogo = $this->tiposTela();
        $coloresTela = $this->coloresTela();

        return view('corteconfeccion::admin.proveedores-tela.show', compact('proveedor', 'proveedorPayload', 'resumen', 'tiposTelaProveedor', 'tiposTelaCatalogo', 'coloresTela'));
    }

    /**
     * Endpoint AJAX propio del DataGrid de referencias (no tiene vista de pantalla
     * completa: solo se consume desde la ficha del proveedor, con ?proveedor_id=).
     */
    public function referencias()
    {
        return app(ProveedorTelaReferenciaDataGrid::class)->toJson();
    }

    public function create()
    {
        $tiposTela = $this->tiposTela();

        return view('corteconfeccion::admin.proveedores-tela.create', compact('tiposTela'));
    }

    public function store(StoreProveedorTelaRequest $request)
    {
        $proveedor = $this->proveedorTelaService->create($request->validated());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => trans('corteconfeccion::app.messages.created'),
                'data' => [
                    'proveedor' => $this->formatearProveedor($proveedor->load('tiposTela')),
                ],
            ]);
        }

        session()->flash('success', trans('corteconfeccion::app.messages.created'));

        return redirect()->route('admin.corte_confeccion.proveedores_tela.index');
    }

    public function edit(int $id)
    {
        $proveedor = $this->proveedorTelaRepository
            ->with(['tiposTela', 'referenciasTela.tipoTela', 'referenciasTela.rollos'])
            ->findOrFail($id);

        $tiposTela = $this->tiposTela();

        return view('corteconfeccion::admin.proveedores-tela.edit', compact('proveedor', 'tiposTela'));
    }

    /**
     * Formulario pequeño para UNA referencia (crear). Reutiliza exactamente el
     * mismo endpoint de actualizacion del proveedor (referencias[0][...] con los
     * demas campos del proveedor sin cambios): no hay una ruta ni una regla de
     * validacion nueva, solo una pantalla mas chica para no obligar a pasar por
     * el formulario completo de edicion.
     */
    public function referenciaCreate(int $proveedorId)
    {
        $proveedor = $this->proveedorTelaRepository->with('tiposTela')->findOrFail($proveedorId);

        if ($proveedor->tiposTela->isEmpty()) {
            session()->flash('warning', 'Este proveedor todavia no tiene tipos de tela asociados. Agrega uno desde "Editar proveedor" antes de crear una referencia.');

            return redirect()->route('admin.corte_confeccion.proveedores_tela.show', $proveedorId);
        }

        $referencia = null;
        $coloresTela = $this->coloresTela();

        return view('corteconfeccion::admin.proveedores-tela.referencia-form', compact('proveedor', 'referencia', 'coloresTela'));
    }

    public function referenciaEdit(int $referenciaId)
    {
        $referencia = ProveedorTelaReferencia::query()->findOrFail($referenciaId);
        $proveedor = $this->proveedorTelaRepository->with('tiposTela')->findOrFail($referencia->proveedor_tela_id);
        $coloresTela = $this->coloresTela();

        return view('corteconfeccion::admin.proveedores-tela.referencia-form', compact('proveedor', 'referencia', 'coloresTela'));
    }

    public function update(UpdateProveedorTelaRequest $request, int $id)
    {
        $proveedor = $this->proveedorTelaRepository->findOrFail($id);
        $proveedor = $this->proveedorTelaService->update($proveedor, $request->validated());

        // Rama JSON: la usan los modales de la ficha de detalle (editar datos
        // basicos, agregar/editar una referencia), que reenvian el mismo endpoint
        // ya validado con solo el fragmento que cambia. El formulario clasico de
        // edicion completa sigue el camino de redireccion de siempre, sin cambios.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => trans('corteconfeccion::app.messages.updated'),
                'data' => [
                    'proveedor' => $this->formatearProveedor($proveedor),
                    'resumen' => $this->resumenProveedor($proveedor),
                ],
            ]);
        }

        session()->flash('success', trans('corteconfeccion::app.messages.updated'));

        // El formulario completo de edicion no envia este campo: sigue cayendo en
        // el destino de siempre. Los formularios pequenos de referencia (crear/
        // editar una sola) lo marcan explicitamente para volver a la ficha.
        if ($request->input('redirect_to') === 'show') {
            return redirect()->route('admin.corte_confeccion.proveedores_tela.show', $id);
        }

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

    /**
     * F1: opciones reales del atributo Bagisto `code = 'color'`, para el
     * selector estructurado de color en referencia/rollo. Mismo patron que
     * tiposTela() -- nunca un ID hardcodeado, siempre resuelto por codigo.
     */
    protected function coloresTela()
    {
        return Attribute::query()
            ->where('code', 'color')
            ->with(['options' => fn ($query) => $query->orderBy('sort_order')->orderBy('admin_name')])
            ->first()
            ?->options
            ?? collect();
    }

    protected function formatearProveedor(ProveedorTela $proveedor): array
    {
        return [
            'id' => $proveedor->id,
            'nombre' => $proveedor->nombre,
            'nit' => $proveedor->nit,
            'contacto' => $proveedor->contacto,
            'telefono' => $proveedor->telefono,
            'direccion' => $proveedor->direccion,
            'estado' => $proveedor->estado,
            'observacion' => $proveedor->observacion,
            'tipo_tela_ids' => $proveedor->tiposTela->pluck('id')->all(),
            'tipos_tela' => $proveedor->tiposTela->pluck('admin_name')->all(),
        ];
    }

    /**
     * Mismos numeros que ProveedorTelaDataGrid, para un solo proveedor: reutiliza
     * su subconsulta (resumenComprasSubquery()) en vez de reescribirla. No es un
     * escenario de N+1: es una ficha de un unico registro, con dos consultas
     * (compras + conteo de referencias activas), no una por fila de un listado.
     *
     * @return array{total_compras:int, total_comprado:float, total_rollos_comprados:int, referencias_activas:int}
     */
    protected function resumenProveedor(ProveedorTela $proveedor): array
    {
        $compras = ProveedorTelaDataGrid::resumenComprasSubquery()
            ->where('proveedor_id', $proveedor->id)
            ->first();

        return [
            'total_compras' => (int) ($compras->total_compras ?? 0),
            'total_comprado' => (float) ($compras->total_comprado ?? 0),
            'total_rollos_comprados' => (int) ($compras->total_rollos_comprados ?? 0),
            'referencias_activas' => $proveedor->referenciasTela()->where('estado', 'activo')->count(),
        ];
    }
}
