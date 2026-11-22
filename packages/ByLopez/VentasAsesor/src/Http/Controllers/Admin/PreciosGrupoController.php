<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\Exceptions\PrecioGrupoInvalidoException as Invalido;
use ByLopez\VentasAsesor\Models\LotePrecioGrupo;
use ByLopez\VentasAsesor\Services\Precios\ConsultaPreciosGrupoService;
use ByLopez\VentasAsesor\Services\Precios\EdicionPreciosGrupoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * «Costos y precios › Precios por grupo» (Doc. 32). Controlador delgado: la consulta en ConsultaPreciosGrupoService; vista
 * previa, confirmacion, reindexacion y permisos en EdicionPreciosGrupoService (que vuelve a validar todo).
 *
 * Permisos propios: la consulta con `ventas_asesor.precios_grupo`, las escrituras con `ventas_asesor.precios_grupo.editar`
 * (separados de costos; el costo solo se muestra con `ventas_asesor.costos`).
 */
class PreciosGrupoController extends Controller
{
    public function __construct(
        protected ConsultaPreciosGrupoService $consulta,
        protected EdicionPreciosGrupoService $edicion,
    ) {}

    public function index(Request $request): View
    {
        $actor = auth()->guard('admin')->user();

        abort_unless($this->edicion->puedeVer($actor), 401);

        $filtros = $this->filtros($request->query());
        $grupo = $this->consulta->grupo($filtros['grupo']);

        // Comparacion secundaria entre grupos, bajo demanda desde el editor (fragmento HTML, misma ruta y misma clave ACL).
        if (ctype_digit((string) $request->query('comparar', '')) && $grupo) {
            return view('ventasasesor::admin.costos.precios.otros-grupos', [
                'filas' => $this->consulta->otrosGrupos((int) $request->query('comparar'), (int) $grupo->id, $filtros['qty']),
                'qty' => $filtros['qty'],
            ]);
        }

        $lote = ($uuid = (string) $request->query('lote', '')) !== ''
            ? LotePrecioGrupo::query()->with('cambios')->where('lote', $uuid)->first()
            : null;

        return view('ventasasesor::admin.costos.precios.index', [
            'grupos' => $this->consulta->grupos(),
            'grupo' => $grupo,
            'opciones' => $this->consulta->opciones(),
            'filtros' => $filtros,
            'listado' => $grupo ? $this->consulta->listar($grupo, $filtros, (int) $request->query('page', 1), $this->edicion->puedeVerCostos($actor)) : null,
            'puedeEditar' => $this->edicion->puedeEditar($actor),
            'conCostos' => $this->edicion->puedeVerCostos($actor),
            'lote' => $lote,
            'historial' => LotePrecioGrupo::query()
                ->when($grupo, fn ($q) => $q->where('customer_group_id', $grupo->id))
                ->latest('id')->limit(8)->get(),
            'maximo' => EdicionPreciosGrupoService::MAX_VARIANTES,
        ]);
    }

    public function previsualizar(Request $request): View|RedirectResponse
    {
        $filtros = $this->filtros((array) $request->input('filtros', []));

        return $this->ejecutar($filtros, function () use ($request, $filtros) {
            $entrada = $request->only(['operacion', 'qty', 'tipo', 'valor', 'modo_ajuste', 'redondeo', 'alcance', 'motivo']);
            $entrada['grupo'] = $filtros['grupo'];
            $entrada['filtros'] = $filtros;
            $entrada['ids'] = $request->input('alcance') === 'filtro'
                ? $this->consulta->idsDelFiltro($filtros)
                : (array) $request->input('ids', []);

            $solicitud = $this->edicion->solicitud($entrada);

            return view('ventasasesor::admin.costos.precios.vista-previa', [
                'vista' => $this->edicion->previsualizar(auth()->guard('admin')->user(), $solicitud),
                'filtros' => $filtros,
                'opciones' => $this->consulta->opciones(),
            ]);
        });
    }

    public function confirmar(Request $request): View|RedirectResponse
    {
        $filtros = $this->filtros((array) $request->input('filtros', []));

        return $this->ejecutar($filtros, function () use ($request, $filtros) {
            $entrada = $request->only(['operacion', 'qty', 'tipo', 'valor', 'modo_ajuste', 'redondeo', 'alcance', 'motivo', 'ids']);
            $entrada['grupo'] = $filtros['grupo'];
            $entrada['filtros'] = $filtros;

            $solicitud = $this->edicion->solicitud($entrada);

            try {
                $r = $this->edicion->confirmar(auth()->guard('admin')->user(), $solicitud, (string) $request->input('token', ''));
            } catch (Invalido $e) {
                if (isset($e->detalles['vista'])) {
                    // Conflicto, filas con error o nada que cambiar: NO se escribio nada; se muestra la vista previa vigente.
                    return view('ventasasesor::admin.costos.precios.vista-previa', [
                        'vista' => $e->detalles['vista'],
                        'filtros' => $filtros,
                        'opciones' => $this->consulta->opciones(),
                        'aviso' => $e->getMessage(),
                        'avisoCodigo' => $e->codigo,
                    ]);
                }

                throw $e;
            }

            $lote = $r['lote'];
            $mensaje = match (true) {
                $r['ya_aplicado'] => trans('ventasasesor::app.precios.ya-aplicado'),
                $lote->indice_estado === LotePrecioGrupo::INDICE_ERROR => trans('ventasasesor::app.precios.aplicado-sin-indice', ['creadas' => $lote->creadas, 'actualizadas' => $lote->actualizadas, 'quitadas' => $lote->quitadas]),
                default => trans('ventasasesor::app.precios.aplicado', ['creadas' => $lote->creadas, 'actualizadas' => $lote->actualizadas, 'quitadas' => $lote->quitadas]),
            };

            return redirect()
                ->route('admin.ventas_asesor.precios_grupo.index', $this->enlace(['lote' => $lote->lote] + $filtros))
                ->with($lote->indice_estado === LotePrecioGrupo::INDICE_ERROR ? 'error' : 'success', $mensaje);
        });
    }

    /** Recuperacion: vuelve a reindexar las variantes de un lote cuyo indice quedo en error o pendiente. */
    public function reindexar(Request $request, string $lote): RedirectResponse
    {
        $actor = auth()->guard('admin')->user();
        abort_unless($this->edicion->puedeEditar($actor), 403);

        $registro = LotePrecioGrupo::query()->where('lote', $lote)->firstOrFail();
        $registro = $this->edicion->reindexarLote($actor, $registro);
        $ok = $registro->indice_estado === LotePrecioGrupo::INDICE_ACTUALIZADO;

        return redirect()
            ->route('admin.ventas_asesor.precios_grupo.index', $this->enlace(['grupo' => $registro->customer_group_id, 'lote' => $registro->lote]))
            ->with($ok ? 'success' : 'error', trans('ventasasesor::app.precios.'.($ok ? 'reindexado' : 'reindexado-fallo')));
    }

    /**
     * @param  array<string, mixed>  $fuente
     * @return array{grupo: ?int, publico: ?int, familia: ?int, tela: ?int, color: ?int, talla: ?int, q: ?string, regla: ?string, qty: int}
     */
    protected function filtros(array $fuente): array
    {
        $entero = fn (string $k) => ctype_digit((string) ($fuente[$k] ?? '')) && (int) $fuente[$k] > 0 ? (int) $fuente[$k] : null;
        $qty = $entero('qty');
        $regla = in_array($fuente['regla'] ?? null, ['con', 'sin'], true)
            ? $fuente['regla']
            : (in_array((string) ($fuente['sin_regla'] ?? ''), ['1', 'on', 'true'], true) ? 'sin' : null);

        return [
            'grupo' => $entero('grupo'),
            'publico' => $entero('publico'),
            'familia' => $entero('familia'),
            'tela' => $entero('tela'),
            'color' => $entero('color'),
            'talla' => $entero('talla'),
            'q' => ($q = trim((string) ($fuente['q'] ?? ''))) === '' ? null : mb_substr($q, 0, 100),
            'regla' => $regla,
            'qty' => $qty === null ? 1 : min($qty, 9999),
        ];
    }

    /** Parametros de enlace sin vacios ni la cantidad por defecto. */
    private function enlace(array $parametros): array
    {
        return array_filter($parametros, fn ($v, $k) => $v !== null && $v !== '' && $v !== false && ! ($k === 'qty' && (int) $v === 1), ARRAY_FILTER_USE_BOTH);
    }

    private function ejecutar(array $filtros, callable $accion): View|RedirectResponse
    {
        try {
            return $accion();
        } catch (Invalido $e) {
            abort_if($e->codigo === Invalido::SIN_PERMISO, 403);

            return redirect()
                ->route('admin.ventas_asesor.precios_grupo.index', $this->enlace($filtros))
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }
}
