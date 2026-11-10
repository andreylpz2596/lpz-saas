<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\Exceptions\ReasignacionConflictoException;
use ByLopez\CorteConfeccion\Models\Notificacion;
use ByLopez\CorteConfeccion\Models\OrdenConfeccionDetalle;
use ByLopez\CorteConfeccion\Models\PrioridadOperacion;
use ByLopez\CorteConfeccion\Services\ConsolidadoConfeccionService;
use ByLopez\CorteConfeccion\Services\PrioridadConfeccionService;
use ByLopez\CorteConfeccion\Services\SugerenciaStockService;
use ByLopez\CorteConfeccion\Support\ConsolidadoConfeccionGate;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;

class ConsolidadoConfeccionController extends Controller
{
    public function __construct(
        protected ConsolidadoConfeccionService $consolidadoService,
        protected PrioridadConfeccionService $prioridadService,
    ) {}

    public function index(Request $request)
    {
        abort_if(! ConsolidadoConfeccionGate::puedeVer(), 403);

        $alcance = ConsolidadoConfeccionGate::alcance();

        $tallas = $request->query('tallas', []);
        $tallas = is_array($tallas) ? $tallas : preg_split('/\s*,\s*/', (string) $tallas);

        $filtros = [
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
            'taller' => (int) $request->query('taller') ?: null,
            'referencia' => (int) $request->query('referencia') ?: null,
            'color' => mb_substr(trim((string) $request->query('color', '')), 0, 60) ?: null,
            'tallas' => array_values(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 20), $tallas), fn ($t) => $t !== '')),
            'prioridad' => in_array($request->query('prioridad'), ['urgente', 'alta', 'prioritaria', 'normal'], true) ? $request->query('prioridad') : null,
            'sugerencia' => array_key_exists((string) $request->query('sugerencia'), SugerenciaStockService::opciones()) ? $request->query('sugerencia') : null,
            'producto' => (int) $request->query('producto') ?: null,
        ];

        $resultado = $this->consolidadoService->consultar($alcance, $filtros);
        $grupos = $resultado['grupos'];

        // Llegada desde una alerta: la variante y la orden se ubican en su pagina y se despliegan.
        $foco = (int) $request->query('foco') ?: null;
        $pagina = max((int) $request->query('page', 1), 1);

        if ($foco && ! $request->has('page')) {
            $indice = $grupos->search(fn ($g) => $g['ordenes']->contains('detalle_id', $foco));
            $pagina = $indice === false ? 1 : intdiv($indice, ConsolidadoConfeccionService::POR_PAGINA) + 1;
        }

        $paginador = new LengthAwarePaginator(
            $grupos->forPage($pagina, ConsolidadoConfeccionService::POR_PAGINA)->values(),
            $grupos->count(),
            ConsolidadoConfeccionService::POR_PAGINA,
            $pagina,
            ['path' => $request->url(), 'query' => $request->except('page')]
        );

        $historial = $this->consolidadoService->historial($paginador->getCollection()->flatMap(fn ($g) => $g['ordenes']->pluck('detalle_id')));

        return view('corteconfeccion::admin.ordenes-confeccion.consolidado', [
            'paginador' => $paginador,
            'totales' => $resultado['totales'],
            'opciones' => $resultado['opciones'],
            'filtros' => $filtros,
            'foco' => $foco,
            'aviso' => $this->aviso($request, $foco),
            'historial' => $historial,
            'verTalleres' => $alcance === null || count($alcance) > 1,
            'puedeCambiar' => ConsolidadoConfeccionGate::puedeCambiarPrioridad(),
            // Sugerencia por stock: cifras de inventario y enlaces globales solo para personal (decidido en servidor).
            'verCifras' => ConsolidadoConfeccionGate::puedeVerCifrasStock(),
            'puedeVerMinimos' => ConsolidadoConfeccionGate::puedeVerMinimos(),
            'calculadaEn' => $resultado['calculada_en'],
        ]);
    }

    public function cambiarPrioridad(Request $request)
    {
        abort_if(! ConsolidadoConfeccionGate::puedeCambiarPrioridad(), 403);

        try {
            $resultado = $this->prioridadService->cambiar($request->only(['idempotency_key', 'nota', 'cambios']));
        } catch (ReasignacionConflictoException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['idempotency_key' => [$e->getMessage()]]], 409);
        }

        /** @var PrioridadOperacion $operacion */
        $operacion = $resultado['operacion'];

        $mensaje = $operacion->notificacion_estado === PrioridadOperacion::NOTIFICADO
            ? trans('corteconfeccion::app.messages.prioridad-notificada', ['usuario' => $operacion->destinatario?->name, 'taller' => $operacion->taller?->nombre])
            : trans('corteconfeccion::app.messages.prioridad-sin-destinatario', ['taller' => $operacion->taller?->nombre]);

        return response()->json([
            'success' => true,
            'message' => $mensaje,
            'notificado' => $operacion->notificacion_estado === PrioridadOperacion::NOTIFICADO,
            'repetida' => $resultado['repetida'],
            'operacion_id' => $operacion->id,
        ]);
    }

    /**
     * Contexto de la alerta abierta (solo la del propio usuario): lo que cambio entonces y lo que hay ahora.
     *
     * @return array<string, mixed>|null
     */
    protected function aviso(Request $request, ?int $foco): ?array
    {
        $id = (int) $request->query('aviso');

        if (! $id) {
            return null;
        }

        $notificacion = Notificacion::query()->whereKey($id)->where('admin_id', auth()->guard('admin')->id())->first();

        if (! $notificacion) {
            return null;
        }

        $cambio = collect($notificacion->datos['cambios'] ?? [])->firstWhere('detalle_id', $foco) ?? ($notificacion->datos['cambios'][0] ?? null);
        $detalle = $cambio ? OrdenConfeccionDetalle::query()->find($cambio['detalle_id']) : null;

        return [
            'datos' => $notificacion->datos,
            'cambio' => $cambio,
            'actual' => $detalle && ConsolidadoConfeccionGate::puedeVerTaller((int) ($notificacion->datos['taller_id'] ?? 0))
                ? $this->consolidadoService->estadoActual($detalle)
                : null,
        ];
    }
}
