<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\DataGrids\TarifaReferenciaDataGrid;
use ByLopez\CorteConfeccion\Http\Requests\AnularTarifaPrestadorRequest;
use ByLopez\CorteConfeccion\Http\Requests\CambiarTarifaOrdenRequest;
use ByLopez\CorteConfeccion\Http\Requests\StoreTarifaPrestadorRequest;
use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Models\TarifaPrestador;
use ByLopez\CorteConfeccion\Services\OrdenTarifaService;
use ByLopez\CorteConfeccion\Services\TarifaPrestadorService;
use ByLopez\CorteConfeccion\Support\CortadorAccess;
use ByLopez\CorteConfeccion\Support\TallerConfeccionAccess;
use ByLopez\CorteConfeccion\Support\TarifaPrestadorGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Referencias y precios de talleres y cortadores (Doc. 05). El tipo ('taller' | 'cortador') viene como default de cada
 * ruta; la administracion se autoriza en los Form Requests (TarifaPrestadorGate) y la consulta exige poder ver la ficha:
 * el taller o cortador solo ve la suya.
 */
class TarifaPrestadorController extends Controller
{
    public function __construct(
        protected TarifaPrestadorService $tarifaService,
        protected OrdenTarifaService $ordenTarifaService,
    ) {}

    /** DataGrid «Referencias y precios» de la ficha. */
    public function index(Request $request, int $id)
    {
        $this->prestadorAccesible($this->tipo($request), $id);

        return app(TarifaReferenciaDataGrid::class)->toJson();
    }

    /** Todas las vigencias (activas y anuladas) de una combinacion. */
    public function historial(Request $request, int $id): JsonResponse
    {
        $prestador = $this->prestadorAccesible($this->tipo($request), $id);

        $datos = $request->validate([
            'tipo_tela_id' => ['required', 'integer'],
            'attribute_family_id' => ['required', 'integer'],
        ]);

        return response()->json([
            'data' => ['vigencias' => $this->tarifaService->historialCombinacion($prestador, (int) $datos['tipo_tela_id'], (int) $datos['attribute_family_id'])],
        ]);
    }

    /** Agrega una referencia o una nueva vigencia de una referencia existente. */
    public function store(StoreTarifaPrestadorRequest $request, int $id): JsonResponse
    {
        $prestador = $this->prestador($this->tipo($request), $id);

        $this->tarifaService->crearVigencia($prestador, $request->validated(), auth()->guard('admin')->id());

        return response()->json(['message' => trans('corteconfeccion::app.messages.tarifa-creada')]);
    }

    public function anular(AnularTarifaPrestadorRequest $request, int $id, int $tarifaId): JsonResponse
    {
        $tipo = $this->tipo($request);
        $prestador = $this->prestador($tipo, $id);

        $tarifa = TarifaPrestador::query()
            ->where($tipo === TarifaPrestadorGate::TIPO_TALLER ? 'taller_id' : 'cortador_id', $prestador->getKey())
            ->findOrFail($tarifaId);

        $this->tarifaService->anular($tarifa, $request->validated('motivo'), auth()->guard('admin')->id());

        return response()->json(['message' => trans('corteconfeccion::app.messages.tarifa-anulada')]);
    }

    /** Pacta otra tarifa para UNA combinacion de una orden. */
    public function pactarEnOrden(CambiarTarifaOrdenRequest $request, int $id): RedirectResponse
    {
        $orden = $this->tipo($request) === TarifaPrestadorGate::TIPO_TALLER
            ? OrdenConfeccion::query()->findOrFail($id)
            : OrdenCorte::query()->findOrFail($id);

        $this->ordenTarifaService->pactar(
            $orden,
            (int) $request->validated('tipo_tela_id'),
            (int) $request->validated('attribute_family_id'),
            $request->validated('valor_unitario'),
            $request->validated('motivo'),
            auth()->guard('admin')->id(),
        );

        session()->flash('success', trans('corteconfeccion::app.messages.tarifa-orden-actualizada'));

        return back();
    }

    /** Default de la ruta ('taller' | 'cortador'); se lee de la ruta para no depender del orden de los parametros. */
    protected function tipo(Request $request): string
    {
        return (string) $request->route('tipo');
    }

    protected function prestador(string $tipo, int $id): Taller|Cortador
    {
        return $tipo === TarifaPrestadorGate::TIPO_TALLER
            ? Taller::query()->findOrFail($id)
            : Cortador::query()->findOrFail($id);
    }

    /** Ficha que el usuario puede ver: el taller o cortador logueado solo accede a la suya. */
    protected function prestadorAccesible(string $tipo, int $id): Taller|Cortador
    {
        $prestador = $this->prestador($tipo, $id);

        abort_if(
            $prestador instanceof Taller
                ? ! TallerConfeccionAccess::puedeAccederTaller($prestador)
                : ! CortadorAccess::puedeAccederCortador($prestador),
            403
        );

        return $prestador;
    }
}
