<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\DataObjects\FilaControlCosto;
use ByLopez\VentasAsesor\Enums\EstadoControlCosto;
use ByLopez\VentasAsesor\Exceptions\CostoComisionableInvalidoException as Invalido;
use ByLopez\VentasAsesor\Services\Costos\ActualizarCostoComisionableService;
use ByLopez\VentasAsesor\Services\Costos\ControlCostosService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * Ventas Asesor › Control de costos (D-COST-04/05/07). Controlador delgado: lectura en ControlCostosService; vista previa
 * y confirmacion en ActualizarCostoComisionableService (que tambien valida el permiso).
 */
class ControlCostosController extends Controller
{
    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected ControlCostosService $control,
        protected ActualizarCostoComisionableService $actualizar,
    ) {}

    public function index(Request $request): View
    {
        $admin = auth()->guard('admin')->user();

        abort_unless($this->autorizacion->tienePermiso($admin, AutorizacionVentasAsesor::PERMISO_COSTOS), 401);

        $filtros = $this->filtros($request);

        return view('ventasasesor::admin.costos.index', [
            'filas' => $this->control->listar($filtros, (int) $request->input('page', 1))->withQueryString(),
            'filtros' => $filtros,
            'indicadores' => $this->control->indicadores(),
            'familias' => $this->control->familias(),
            'puedeActualizar' => $this->autorizacion->tienePermiso($admin, AutorizacionVentasAsesor::PERMISO_COSTOS_ACTUALIZAR),
        ]);
    }

    public function previsualizar(Request $request): View|RedirectResponse
    {
        $filtros = $this->filtros($request);
        $ids = $request->input('alcance') === 'filtro'
            ? $this->control->filas($filtros)->map(fn (FilaControlCosto $f) => $f->productId)->all()
            : (array) $request->input('ids', []);

        return $this->ejecutar(fn () => view('ventasasesor::admin.costos.vista-previa', [
            'vista' => $this->actualizar->previsualizar(auth()->guard('admin')->user(), $ids, $request->input('nuevo_costo')),
            'motivo' => mb_substr(trim((string) $request->input('motivo', '')), 0, 500),
            'filtros' => $filtros,
        ]));
    }

    public function actualizar(Request $request): RedirectResponse
    {
        return $this->ejecutar(function () use ($request) {
            $r = $this->actualizar->confirmar(
                auth()->guard('admin')->user(),
                (array) $request->input('ids', []),
                $request->input('nuevo_costo'),
                (string) $request->input('token', ''),
                $request->input('motivo'),
            );

            return redirect()->route('admin.ventas_asesor.costos.index')
                ->with('success', trans('ventasasesor::app.costos.actualizado', ['cantidad' => $r['actualizados'], 'sin_cambio' => $r['sin_cambio']]));
        });
    }

    /** @return array{q: ?string, familia: ?int, estado: ?string} */
    private function filtros(Request $request): array
    {
        return [
            'q' => ($q = trim((string) $request->input('q', ''))) === '' ? null : mb_substr($q, 0, 100),
            'familia' => ctype_digit((string) $request->input('familia')) ? (int) $request->input('familia') : null,
            'estado' => in_array($request->input('estado'), EstadoControlCosto::valores(), true) ? $request->input('estado') : null,
        ];
    }

    private function ejecutar(callable $accion): View|RedirectResponse
    {
        try {
            return $accion();
        } catch (Invalido $e) {
            abort_if($e->codigo === Invalido::SIN_PERMISO, 403);

            return redirect()->route('admin.ventas_asesor.costos.index')->with('error', $e->getMessage());
        }
    }
}
