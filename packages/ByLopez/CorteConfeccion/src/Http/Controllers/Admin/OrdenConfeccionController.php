<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\DataGrids\OrdenConfeccionDataGrid;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccionRetorno;
use ByLopez\CorteConfeccion\Exceptions\ReasignacionConflictoException;
use ByLopez\CorteConfeccion\Http\Requests\DistribuirConfeccionRequest;
use ByLopez\CorteConfeccion\Http\Requests\ReasignarPrendasRequest;
use ByLopez\CorteConfeccion\Http\Requests\RecibirOrdenConfeccionRequest;
use ByLopez\CorteConfeccion\Http\Requests\ReportarOrdenConfeccionRequest;
use ByLopez\CorteConfeccion\Http\Requests\StoreOrdenConfeccionRequest;
use ByLopez\CorteConfeccion\Models\OrdenConfeccionRetorno;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\Reasignacion;
use ByLopez\CorteConfeccion\Repositories\OrdenConfeccionRepository;
use ByLopez\CorteConfeccion\Repositories\OrdenCorteRepository;
use ByLopez\CorteConfeccion\Repositories\TallerRepository;
use ByLopez\CorteConfeccion\Services\DisponibilidadConfeccionService;
use ByLopez\CorteConfeccion\Services\InventarioMovimientoService;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenTarifaService;
use ByLopez\CorteConfeccion\Services\ReasignacionService;
use ByLopez\CorteConfeccion\Services\SugerenciaStockService;
use ByLopez\CorteConfeccion\Services\VarianteConsultaService;
use ByLopez\CorteConfeccion\Support\ConsolidadoConfeccionGate;
use ByLopez\CorteConfeccion\Support\DistribucionConfeccionGate;
use ByLopez\CorteConfeccion\Support\OrdenConfeccionGate;
use ByLopez\CorteConfeccion\Support\ReasignacionGate;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class OrdenConfeccionController extends Controller
{
    public function __construct(
        protected OrdenConfeccionRepository $ordenConfeccionRepository,
        protected OrdenCorteRepository $ordenCorteRepository,
        protected TallerRepository $tallerRepository,
        protected OrdenConfeccionService $ordenConfeccionService,
        protected DisponibilidadConfeccionService $disponibilidadConfeccionService,
        protected InventarioMovimientoService $inventarioMovimientoService,
        protected VarianteConsultaService $varianteConsultaService,
    ) {}

    public function index()
    {
        abort_if(! OrdenConfeccionGate::puedeVer(), 403);

        if (request()->ajax()) {
            return app(OrdenConfeccionDataGrid::class)->toJson();
        }

        return view('corteconfeccion::admin.ordenes-confeccion.index');
    }

    public function misOrdenes()
    {
        return redirect()->route('admin.corte_confeccion.ordenes_confeccion.index');
    }

    public function misOrdenesShow(int $id)
    {
        return redirect()->route('admin.corte_confeccion.ordenes_confeccion.show', $id);
    }

    public function createIndex()
    {
        return redirect()->route('admin.corte_confeccion.ordenes_confeccion.index');
    }

    public function operarIndex()
    {
        return redirect()->route('admin.corte_confeccion.ordenes_confeccion.index');
    }

    public function create(int $ordenCorteId)
    {
        // Defensa en profundidad (Fase SEC-01): la pantalla de distribucion
        // solo debe alcanzarla Admin/Bodega, sin depender exclusivamente de
        // la ACL configurable -- ver DistribucionConfeccionGate.
        abort_if(! DistribucionConfeccionGate::puedeDistribuir(), 403);

        $ordenCorte = $this->ordenCorteRepository
            ->with(['recepcion.detalles', 'ordenesConfeccion.detalles'])
            ->findOrFail($ordenCorteId);

        if (! $this->disponibilidadConfeccionService->puedeDistribuir($ordenCorte)) {
            return redirect()
                ->route('admin.corte_confeccion.ordenes_corte.show', $ordenCorte->id)
                ->with('warning', $this->mensajeDistribucionNoDisponible($ordenCorte));
        }

        [$filas, $talleres] = $this->construirContextoDistribucion($ordenCorte);

        // Preseleccion opcional cuando se llega desde el detalle de un taller (?taller_id=).
        $tallerPreseleccionado = ($tallerId = (int) request()->query('taller_id'))
            ? $this->tallerRepository->find($tallerId)
            : null;

        return view('corteconfeccion::admin.ordenes-confeccion.create', compact('ordenCorte', 'filas', 'talleres', 'tallerPreseleccionado'));
    }

    /**
     * Endpoint JSON minimo (Fase 03C3) para refrescar disponibilidad por
     * variante y talleres activos de una OrdenCorte sin recargar la
     * pantalla -- usado por la pantalla de distribucion cuando el backend
     * rechaza un submit porque el contexto cambio mientras se preparaba la
     * operacion (disponibilidad distinta, Taller desactivado). Reutiliza
     * exactamente los mismos servicios que create(): no crea un segundo
     * motor de disponibilidad ni duplica consultas a Webkul fuera de
     * VarianteConsultaService.
     */
    public function contexto(int $ordenCorteId)
    {
        // Defensa en profundidad (Fase SEC-01): mismo criterio que create().
        abort_if(! DistribucionConfeccionGate::puedeDistribuir(), 403);

        $ordenCorte = $this->ordenCorteRepository
            ->with(['recepcion.detalles', 'ordenesConfeccion.detalles'])
            ->findOrFail($ordenCorteId);

        if (! $this->disponibilidadConfeccionService->puedeDistribuir($ordenCorte)) {
            return response()->json([
                'message' => $this->mensajeDistribucionNoDisponible($ordenCorte),
                'errors' => [
                    'orden_corte_id' => [$this->mensajeDistribucionNoDisponible($ordenCorte)],
                ],
            ], 422);
        }

        [$filas, $talleres] = $this->construirContextoDistribucion($ordenCorte);

        return response()->json([
            'filas' => $filas,
            'talleres' => $talleres,
        ]);
    }

    /**
     * @return array{0: Collection, 1: Collection}
     */
    protected function construirContextoDistribucion(OrdenCorte $ordenCorte): array
    {
        $disponibilidad = $this->disponibilidadConfeccionService->porOrdenCorte($ordenCorte);

        // Metadata de variante (SKU/nombre/color/talla/stock) resuelta en
        // lote via VarianteConsultaService (Fase 04B) -- nunca se consulta
        // Webkul directamente desde aqui ni desde la vista.
        $variantesPorProducto = $this->varianteConsultaService->porProductIds(
            $disponibilidad->pluck('product_id')
        );

        $hexPorColor = $this->varianteConsultaService->hexPorColor();

        // Sugerencia por stock (Doc. 09 §9): global por variante; si la variante se divide entre talleres se repite.
        $sugerencias = app(SugerenciaStockService::class)->paraProductos($disponibilidad->pluck('product_id'));

        $filas = $disponibilidad->map(function ($fila) use ($variantesPorProducto, $hexPorColor, $sugerencias) {
            $variante = $variantesPorProducto->get($fila['product_id'], []);
            $sugerencia = $sugerencias->get((int) $fila['product_id']);

            return [
                'product_id' => $fila['product_id'],
                'sku' => $variante['sku'] ?? $fila['producto_sku'],
                'nombre' => $variante['nombre'] ?? $fila['producto_nombre'],
                'color' => $variante['color'] ?? null,
                'color_hex' => isset($variante['color']) ? ($hexPorColor[mb_strtolower(trim($variante['color']))] ?? null) : null,
                'talla' => $variante['talla'] ?? ($fila['talla'] ?: null),
                'talla_etiqueta' => $variante['talla_etiqueta'] ?? null,
                'stock_actual' => $variante['stock_actual'] ?? null,
                'cantidad_recibida' => $fila['cantidad_recibida'],
                'cantidad_defectuosa' => $fila['cantidad_defectuosa'],
                'apta_confeccion' => $fila['cantidad_apta_confeccion'],
                'ya_distribuido' => $fila['cantidad_entregada_talleres'],
                'disponible_confeccion' => $fila['cantidad_disponible_confeccion'],
                'sugerencia' => $sugerencia ? array_merge($sugerencia, ['calculada_en' => $sugerencia['calculada_en']->format('H:i:s')]) : null,
            ];
        })->values();

        $talleres = $this->tallerRepository
            ->findWhere(['estado' => 'activo'])
            ->map(fn ($taller) => ['id' => $taller->id, 'nombre' => $taller->nombre])
            ->sortBy('nombre')
            ->values();

        return [$filas, $talleres];
    }

    protected function mensajeDistribucionNoDisponible(OrdenCorte $ordenCorte): string
    {
        return $ordenCorte->recepcion
            ? trans('corteconfeccion::app.errors.orden-sin-pendiente-distribucion')
            : trans('corteconfeccion::app.errors.orden-sin-recepcion');
    }

    public function store(StoreOrdenConfeccionRequest $request)
    {
        $orden = $this->ordenConfeccionService->create($request->validated());

        session()->flash('success', trans('corteconfeccion::app.messages.created'));

        return redirect()->route('admin.corte_confeccion.ordenes_confeccion.show', $orden->id);
    }

    /**
     * Motor de distribucion multi-taller (Fase 03A): una sola operacion
     * puede generar N OrdenConfeccion, una por Taller. Endpoint dedicado
     * y separado de store() a proposito -- store() mantiene su contrato
     * actual (un taller, una OrdenConfeccion) sin cambios, ya que ambos
     * flujos conviven (docs/confeccion/03A_MOTOR_DISTRIBUCION.md "Endpoint").
     * Consumido por la pantalla REVISAR de create.blade.php (Fase 03C3) via
     * submit JSON (fetch/axios); ver §7/§8 de 03C3_REVISION_SUBMIT_RESPONSIVE.md.
     */
    public function distribuir(DistribuirConfeccionRequest $request)
    {
        $ordenes = $this->ordenConfeccionService->distribuir($request->validated());

        session()->flash('success', trans('corteconfeccion::app.messages.created'));

        $redirectUrl = route('admin.corte_confeccion.ordenes_corte.show', $request->input('orden_corte_id'));

        // Cambio minimo de Fase 03C3: el submit JSON (fetch/axios, pantalla
        // REVISAR) necesita conocer las ordenes creadas para su mensaje de
        // exito, algo que un redirect HTML no puede transportar. El camino
        // HTML original (wantsJson() === false) queda exactamente igual que
        // antes de 03C3. OrdenConfeccionService::distribuir() no se toca.
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => trans('corteconfeccion::app.messages.created'),
                'ordenes' => $ordenes->map(fn ($orden) => [
                    'id' => $orden->id,
                    'codigo' => $orden->codigo,
                ])->values(),
                'redirect_url' => $redirectUrl,
            ]);
        }

        return redirect($redirectUrl);
    }

    public function show(int $id)
    {
        $orden = $this->ordenConfeccionRepository
            ->with([
                'taller',
                'ordenCorte',
                'detalles.retornosDetalles.retorno',
                'retornos.detalles.ordenConfeccionDetalle',
                'retornos.detalles.movimientosInventario.inventorySource',
                'movimientosInventario.inventorySource',
                'movimientosInventario.retorno',
                'movimientosInventario.retornoDetalle',
            ])
            ->findOrFail($id);

        abort_if(! OrdenConfeccionGate::puedeVer($orden), 403);

        $previewsInventario = $orden->retornos
            ->where('estado', EstadoOrdenConfeccionRetorno::PendienteBodega->value)
            ->mapWithKeys(fn ($retorno) => [$retorno->id => $this->inventarioMovimientoService->previewRetorno($retorno)])
            ->all();

        // Color/talla reales de cada variante (atributos EAV) en UNA resolucion
        // en lote (VarianteConsultaService), sin consultas por fila.
        $productIds = $orden->detalles->pluck('product_id')
            ->merge($orden->retornos->flatMap(fn ($retorno) => $retorno->detalles->pluck('product_id')))
            ->merge($orden->movimientosInventario->pluck('product_id'))
            ->filter()->unique()->values();

        $variantes = $this->varianteConsultaService->porProductIds($productIds);
        $hexPorColor = $this->varianteConsultaService->hexPorColor();

        // Sugerencia por stock de cada variante. El taller ve nivel y motivo, y solo SUS prendas pendientes de recibir.
        $sugerencias = app(SugerenciaStockService::class)->paraProductos($orden->detalles->pluck('product_id'), ConsolidadoConfeccionGate::alcance());
        $verCifrasStock = ConsolidadoConfeccionGate::puedeVerCifrasStock();

        $tarifasOrden = app(OrdenTarifaService::class)->resumen($orden);

        // Historial enlazado: cada reasignacion donde esta orden cedio o recibio prendas.
        $reasignaciones = Reasignacion::query()
            ->where(fn ($q) => $q->where('orden_confeccion_origen_id', $orden->id)->orWhere('orden_confeccion_destino_id', $orden->id))
            ->with(['lineas', 'tallerOrigen', 'tallerDestino', 'ordenOrigen', 'ordenDestino'])
            ->orderByDesc('id')
            ->get();

        return view('corteconfeccion::admin.ordenes-confeccion.show', compact('orden', 'previewsInventario', 'variantes', 'hexPorColor', 'tarifasOrden', 'reasignaciones', 'sugerencias', 'verCifrasStock'));
    }

    public function reportarTaller(ReportarOrdenConfeccionRequest $request, int $id)
    {
        $orden = $this->ordenConfeccionRepository->with(['taller', 'detalles'])->findOrFail($id);

        abort_if(! OrdenConfeccionGate::puedeReportarTaller($orden), 403);

        $retorno = $this->ordenConfeccionService->reportarTaller($orden, $request->validated());

        return back()
            ->with('success', trans('corteconfeccion::app.messages.reported'))
            ->with('retorno_creado', $retorno->codigo);
    }

    public function recibirBodega(RecibirOrdenConfeccionRequest $request, int $retornoId)
    {
        $retorno = OrdenConfeccionRetorno::query()->with('ordenConfeccion')->findOrFail($retornoId);

        abort_if(! OrdenConfeccionGate::puedeRecibirBodega(), 403);
        abort_if(! OrdenConfeccionGate::puedeVer($retorno->ordenConfeccion), 403);

        $this->ordenConfeccionService->recibirBodega($retorno, $request->validated());

        return back()->with('success', trans('corteconfeccion::app.messages.received'));
    }

    public function cancel(int $id)
    {
        $orden = $this->ordenConfeccionRepository->with('taller')->findOrFail($id);

        abort_if(! OrdenConfeccionGate::puedeOperar($orden), 403);

        $this->ordenConfeccionService->cancel($orden);

        return back()->with('success', trans('corteconfeccion::app.messages.cancelled'));
    }

    /**
     * Contexto del modal «Reasignar prendas»: saldo real (original, cedido, vigente, reportado, en transito, elegible)
     * por variante del taller origen y talleres destino activos. Una llave de idempotencia nueva por apertura.
     */
    public function reasignarContexto(int $id)
    {
        abort_if(! ReasignacionGate::puedeReasignar(), 403);

        $orden = $this->ordenConfeccionRepository->with(['taller', 'ordenCorte'])->findOrFail($id);

        $contexto = app(ReasignacionService::class)->contexto($orden);

        $lineas = collect($contexto['lineas']);
        $variantes = $this->varianteConsultaService->porProductIds($lineas->pluck('product_id')->filter()->unique());
        $hexPorColor = $this->varianteConsultaService->hexPorColor();

        $contexto['lineas'] = $lineas->map(function (array $linea) use ($variantes, $hexPorColor) {
            $variante = $variantes->get($linea['product_id'], []);

            return $linea + [
                'color' => $variante['color'] ?? null,
                'color_hex' => isset($variante['color']) ? ($hexPorColor[mb_strtolower(trim($variante['color']))] ?? null) : null,
                'talla_etiqueta' => $variante['talla_etiqueta'] ?? null,
            ];
        })->values()->all();

        // Una llave de idempotencia nueva por apertura del modal: reintentos del MISMO envio la reutilizan.
        $contexto['idempotency_key'] = (string) Str::uuid();

        return response()->json($contexto);
    }

    public function reasignarStore(ReasignarPrendasRequest $request, int $id)
    {
        $this->ordenConfeccionRepository->findOrFail($id);

        try {
            $reasignacion = app(ReasignacionService::class)->reasignar(array_merge($request->validated(), ['orden_confeccion_origen_id' => $id]));
        } catch (ReasignacionConflictoException $e) {
            return $request->wantsJson()
                ? response()->json(['message' => $e->getMessage(), 'errors' => ['idempotency_key' => [$e->getMessage()]]], 409)
                : back()->withInput()->with('error', $e->getMessage());
        }

        $mensaje = trans('corteconfeccion::app.messages.reasignacion-creada');

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $mensaje,
                'reasignacion' => ['id' => $reasignacion->id, 'codigo' => $reasignacion->codigo, 'estado' => $reasignacion->estado],
                'orden_destino' => ['id' => $reasignacion->ordenDestino->id, 'codigo' => $reasignacion->ordenDestino->codigo],
                'redirect_url' => route('admin.corte_confeccion.ordenes_confeccion.show', $id),
            ]);
        }

        return redirect()->route('admin.corte_confeccion.ordenes_confeccion.show', $id)->with('success', $mensaje);
    }

    public function reasignacionRecibir(int $id)
    {
        abort_if(! ReasignacionGate::puedeReasignar(), 403);

        $reasignacion = Reasignacion::query()->findOrFail($id);

        app(ReasignacionService::class)->confirmarRecepcion($reasignacion);

        return back()->with('success', trans('corteconfeccion::app.messages.reasignacion-recibida'));
    }

    public function reasignacionRevertir(Request $request, int $id)
    {
        abort_if(! ReasignacionGate::puedeReasignar(), 403);

        $datos = $request->validate([
            'motivo' => ['required', 'string', 'max:2000'],
            'constancia_devolucion' => ['nullable', 'string', 'max:2000'],
            'devolucion_fisica_confirmada' => ['nullable', 'boolean'],
        ]);

        $reasignacion = Reasignacion::query()->findOrFail($id);

        app(ReasignacionService::class)->revertir(
            $reasignacion,
            $datos['motivo'],
            $datos['constancia_devolucion'] ?? null,
            (bool) ($datos['devolucion_fisica_confirmada'] ?? false),
        );

        return back()->with('success', trans('corteconfeccion::app.messages.reasignacion-revertida'));
    }
}
