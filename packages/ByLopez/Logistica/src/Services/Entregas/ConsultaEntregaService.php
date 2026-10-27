<?php

namespace ByLopez\Logistica\Services\Entregas;

use ByLopez\Logistica\Enums\EstadoEventoLogistico as Estado;
use ByLopez\Logistica\Models\Entrega;
use ByLopez\Logistica\Models\RecepcionDevolucion;
use ByLopez\Logistica\Models\Retorno;
use ByLopez\Logistica\Models\ValidacionEntrega;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lectura del estado de entrega (bloque 16). TODO es DERIVADO de los eventos vigentes; no existe un
 * `orders.delivery_status` mutable que pueda desincronizarse:
 *
 *   despachadas = Σ shipment_items.qty                     (Bagisto)
 *   entregadas  = Σ entrega_items.cantidad (entrega confirmada)
 *   retornadas  = Σ retorno_items.cantidad (retorno confirmado)
 *   pendientes  = despachadas − entregadas − retornadas
 *
 * `pickup_orders` (cita de recogida) NUNCA cuenta como entrega (bloque 12). Contrato de solo lectura para
 * VentasAsesor / COM-04: unidadesEntregadas().
 */
class ConsultaEntregaService
{
    public const POR_PAGINA = 25;

    /** Devoluciones vigentes de un envio que aun no tienen una recepcion vigente en bodega. */
    /** Entrega cerrada operativamente (nada pendiente, algo entregado, sin devolucion por recibir) y todavia sin validacion vigente. */
    private const SQL_LISTA_PARA_VALIDAR = 'x.despachadas - x.entregadas - x.retornadas <= 0 AND x.entregadas > 0 AND x.devoluciones_por_recibir = 0 AND x.validada = 0';

    private const SQL_POR_RECIBIR = '(SELECT COUNT(*) FROM bylopez_lg_retornos r WHERE r.shipment_id = s.id AND r.estado = ? AND NOT EXISTS (SELECT 1 FROM bylopez_lg_recepciones_devolucion d WHERE d.retorno_id = r.id AND d.estado = ?))';

    public function __construct(protected TipoDeProductoEnvio $tipos, protected ReporteProveedorService $reportes) {}

    /** @return array<int, array{shipment_item_id: int, order_item_id: ?int, sku: ?string, nombre: ?string, despachadas: int, entregadas: int, retornadas: int, pendientes: int}> */
    public function saldosDeEnvio(int $shipmentId): array
    {
        $items = DB::table('shipment_items')->where('shipment_id', $shipmentId)->orderBy('id')
            ->get(['id', 'order_item_id', 'sku', 'name', 'qty']);

        $ids = $items->pluck('id')->map(fn ($id) => (int) $id)->all();
        $entregadas = $this->sumasPorItem('bylopez_lg_entrega_items', 'bylopez_lg_entregas', 'entrega_id', $ids);
        $retornadas = $this->sumasPorItem('bylopez_lg_retorno_items', 'bylopez_lg_retornos', 'retorno_id', $ids);

        $saldos = [];

        foreach ($items as $i) {
            $id = (int) $i->id;
            $e = $entregadas[$id] ?? 0;
            $r = $retornadas[$id] ?? 0;

            $saldos[$id] = [
                'shipment_item_id' => $id,
                'order_item_id' => $i->order_item_id === null ? null : (int) $i->order_item_id,
                'sku' => $i->sku,
                'nombre' => $i->name,
                'despachadas' => (int) $i->qty,
                'entregadas' => $e,
                'retornadas' => $r,
                'pendientes' => max(0, (int) $i->qty - $e - $r),
            ];
        }

        return $saldos;
    }

    /** @return array{despachadas: int, entregadas: int, retornadas: int, pendientes: int} */
    public function resumenPedido(int $orderId): array
    {
        return $this->resumenPedidos([$orderId])[$orderId];
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, array{despachadas: int, entregadas: int, retornadas: int, pendientes: int}>
     */
    public function resumenPedidos(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));

        $despachadas = DB::table('shipment_items as si')->join('shipments as s', 's.id', '=', 'si.shipment_id')
            ->whereIn('s.order_id', $orderIds)->groupBy('s.order_id')
            ->pluck(DB::raw('COALESCE(SUM(si.qty), 0) as total'), 's.order_id');
        $entregadas = $this->sumasPorPedido('bylopez_lg_entrega_items', 'bylopez_lg_entregas', 'entrega_id', $orderIds);
        $retornadas = $this->sumasPorPedido('bylopez_lg_retorno_items', 'bylopez_lg_retornos', 'retorno_id', $orderIds);

        $resumen = [];

        foreach ($orderIds as $id) {
            $d = (int) ($despachadas[$id] ?? 0);
            $e = $entregadas[$id] ?? 0;
            $r = $retornadas[$id] ?? 0;
            $resumen[$id] = ['despachadas' => $d, 'entregadas' => $e, 'retornadas' => $r, 'pendientes' => max(0, $d - $e - $r)];
        }

        return $resumen;
    }

    /**
     * CONTRATO COM-04 (solo lectura): unidades ENTREGADAS por linea de pedido, con su fecha real. Solo entregas
     * vigentes; una anulada no cuenta y un retorno nunca es entrega.
     *
     * @param  list<int>  $orderIds
     * @return Collection<int, object{order_id: int, order_item_id: int, shipment_item_id: int, entrega_id: int, cantidad: int, fecha_entrega: string}>
     */
    public function unidadesEntregadas(array $orderIds): Collection
    {
        return DB::table('bylopez_lg_entrega_items as ei')
            ->join('bylopez_lg_entregas as e', 'e.id', '=', 'ei.entrega_id')
            ->whereIn('e.order_id', array_map('intval', $orderIds))
            ->where('e.estado', Estado::CONFIRMADO->value)
            ->orderBy('e.fecha_entrega')->orderBy('ei.id')
            ->get(['e.order_id', 'ei.order_item_id', 'ei.shipment_item_id', 'e.id as entrega_id', 'ei.cantidad', 'e.fecha_entrega'])
            ->map(function ($f) {
                foreach (['order_id', 'order_item_id', 'shipment_item_id', 'entrega_id', 'cantidad'] as $c) {
                    $f->{$c} = (int) $f->{$c};
                }

                return $f;
            });
    }

    /**
     * Bandeja: un renglon por envio con sus saldos.
     *
     * @param  array{q: ?string, estado: string, pedido: ?int}  $filtros  estado = pendientes | sin_entrega | entregados | retornados | completos | todos
     */
    public function listar(array $filtros, int $pagina = 1): LengthAwarePaginator
    {
        $q = DB::query()->fromSub($this->basePorEnvio(), 'x');

        if ($filtros['pedido'] ?? null) {
            $q->where('x.order_id', $filtros['pedido']);
        }

        if (($texto = $filtros['q'] ?? null) !== null) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texto).'%';
            $q->where(fn (Builder $w) => $w->where('x.increment_id', 'like', $like)
                ->orWhere('x.track_number', 'like', $like)
                ->orWhere('x.cliente', 'like', $like)
                ->orWhere('x.carrier_title', 'like', $like));
        }

        match ($filtros['estado'] ?? 'pendientes') {
            'completos' => $q->whereRaw('x.despachadas - x.entregadas - x.retornadas <= 0'),
            // Nada registrado todavia (ni entrega ni retorno): lo que sigue en camino o espera que se registre.
            'sin_entrega' => $q->whereRaw('x.despachadas > 0 AND x.entregadas + x.retornadas = 0'),
            'entregados' => $q->whereRaw('x.despachadas - x.entregadas - x.retornadas <= 0 AND x.entregadas > 0'),
            // Entrega completa que el administrador aun no ratifico (y sin devoluciones esperando a Bodega).
            'por_validar' => $q->whereRaw(self::SQL_LISTA_PARA_VALIDAR),
            'retornados' => $q->whereRaw('x.retornadas > 0'),
            'todos' => null,
            default => $q->whereRaw('x.despachadas - x.entregadas - x.retornadas > 0'),
        };

        $pagina = $q->orderByDesc('x.shipment_id')->paginate(self::POR_PAGINA, ['*'], 'page', max(1, $pagina));
        $ids = $pagina->getCollection()->pluck('shipment_id')->map(fn ($id) => (int) $id)->all();
        $tipos = $this->tipos->porEnvios($ids);
        // Solo LECTURA de lo que reporto el proveedor: no altera ningun estado ni cifra de entrega.
        $reportes = $this->reportes->resumenPorEnvios($ids);

        return $pagina->through(function ($f) use ($tipos, $reportes) {
            $f->reporte_proveedor = $reportes[(int) $f->shipment_id] ?? null;
            $f->pendientes = max(0, (int) $f->despachadas - (int) $f->entregadas - (int) $f->retornadas);
            $f->estado_entrega = self::estadoEntrega((int) $f->despachadas, (int) $f->entregadas, (int) $f->retornadas);
            $lista = $f->pendientes === 0 && (int) $f->entregadas > 0 && (int) $f->devoluciones_por_recibir === 0;
            $f->validacion = self::validacion((int) $f->eventos_vigentes, (int) $f->eventos_anulados, (int) $f->validada > 0, $lista);
            $f->validada = (int) $f->validada > 0;
            $f->lista_para_validar = $lista && ! $f->validada;
            $f->tipo_producto = $tipos[(int) $f->shipment_id] ?? ['principal' => '', 'otros' => 0];
            // Solo lo que requiere accion de Bodega: devoluciones vigentes por recibir (o sin bodega determinable → revision).
            $f->devolucion = (int) $f->devoluciones_por_recibir > 0 ? ($f->bodega_id ? 'pendiente_recibir' : 'requiere_revision') : null;

            return $f;
        });
    }

    /**
     * Estado de la ENTREGA, derivado de lo registrado (no es lo que reporta la transportadora: hoy no hay integracion).
     * pendiente = nada registrado · parcial · entregado · retornado · entregado_retorno (entregado con unidades devueltas).
     */
    public static function estadoEntrega(int $despachadas, int $entregadas, int $retornadas): string
    {
        $pendientes = max(0, $despachadas - $entregadas - $retornadas);

        return match (true) {
            $despachadas <= 0, $pendientes === $despachadas => 'pendiente',
            $pendientes > 0 => 'parcial',
            $retornadas >= $despachadas => 'retornado',
            $entregadas > 0 && $retornadas > 0 => 'entregado_retorno',
            default => 'entregado',
        };
    }

    /**
     * Validacion ByLopez segun lo que EXISTE hoy: una entrega/retorno registrado por el panel (bodega o supervision) o ninguno.
     * Los estados pendiente_confirmar / confirmado / requiere_revision estan previstos en la interfaz pero aun no los produce
     * ningun dato: requieren la arquitectura descrita en el reporte.
     */
    public static function validacion(int $eventosVigentes, int $eventosAnulados, bool $validada = false, bool $listaParaValidar = false): string
    {
        return match (true) {
            $validada => 'confirmado',
            $listaParaValidar => 'pendiente_confirmar',
            $eventosVigentes > 0 => 'registrada',
            $eventosAnulados > 0 => 'anulada',
            default => 'sin_registro',
        };
    }

    /** @return array{envios_pendientes: int, unidades_pendientes: int, entregas_30d: int, retornos_30d: int, devoluciones_por_recibir: int, por_validar: int} */
    public function indicadores(): array
    {
        $saldo = DB::query()->fromSub($this->basePorEnvio(), 'x')
            ->selectRaw('SUM(CASE WHEN x.despachadas - x.entregadas - x.retornadas > 0 THEN 1 ELSE 0 END) AS envios')
            ->selectRaw('COALESCE(SUM(GREATEST(x.despachadas - x.entregadas - x.retornadas, 0)), 0) AS unidades')
            ->first();
        $desde = now()->subDays(30);

        return [
            'envios_pendientes' => (int) ($saldo->envios ?? 0),
            'unidades_pendientes' => (int) ($saldo->unidades ?? 0),
            'entregas_30d' => Entrega::query()->where('estado', Estado::CONFIRMADO->value)->where('created_at', '>=', $desde)->count(),
            'retornos_30d' => Retorno::query()->where('estado', Estado::CONFIRMADO->value)->where('created_at', '>=', $desde)->count(),
            'por_validar' => (int) DB::query()->fromSub($this->basePorEnvio(), 'x')->whereRaw(self::SQL_LISTA_PARA_VALIDAR)->count(),
            'devoluciones_por_recibir' => (int) DB::table('bylopez_lg_retornos as r')->where('r.estado', Estado::CONFIRMADO->value)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('bylopez_lg_recepciones_devolucion as d')->whereColumn('d.retorno_id', 'r.id')->where('d.estado', Estado::CONFIRMADO->value))
                ->count(),
        ];
    }

    /**
     * Detalle de un envio para la vista operativa.
     *
     * @return array{envio: object, items: array, entregas: Collection, retornos: Collection, cita_recogida: ?object}|null
     */
    public function envio(int $shipmentId): ?array
    {
        $envio = DB::table('shipments as s')->join('orders as o', 'o.id', '=', 's.order_id')
            ->where('s.id', $shipmentId)
            ->first([
                's.id', 's.order_id', 's.carrier_code', 's.carrier_title', 's.track_number', 's.total_qty', 's.created_at',
                's.inventory_source_id', 's.inventory_source_name', 'o.increment_id', 'o.shipping_method', 'o.shipping_title', 'o.status as estado_pedido',
                DB::raw("TRIM(CONCAT(COALESCE(o.customer_first_name, ''), ' ', COALESCE(o.customer_last_name, ''))) as cliente"),
            ]);

        if (! $envio) {
            return null;
        }

        // Solo informativo: la CITA de recogida (bagisto-eu/pickup) no es un retiro efectivo (bloque 12).
        $cita = Schema::hasTable('pickup_orders')
            ? DB::table('pickup_orders')->where('order_id', $envio->order_id)->orderByDesc('id')->first(['pickup_date', 'start_time', 'end_time'])
            : null;

        $items = $this->saldosDeEnvio($shipmentId);
        $entregas = Entrega::query()->with('items')->where('shipment_id', $shipmentId)->orderByDesc('id')->get();
        $retornos = Retorno::query()->with('items')->where('shipment_id', $shipmentId)->orderByDesc('id')->get();
        $total = fn (string $k) => array_sum(array_column($items, $k));
        $resumen = ['despachadas' => $total('despachadas'), 'entregadas' => $total('entregadas'), 'retornadas' => $total('retornadas'), 'pendientes' => $total('pendientes')];
        $productos = $this->productosDeEnvio($items);
        $etiquetas = $this->etiquetasDeItems($productos);
        $recepciones = RecepcionDevolucion::query()->with('items')->where('shipment_id', $shipmentId)->orderBy('id')->get();
        $devoluciones = $this->devoluciones($envio, $retornos, $recepciones, $etiquetas);
        $guiaFila = DB::table('bylopez_lg_guias')->where('shipment_id', $shipmentId)->orderByDesc('id')->first(['numero_guia', 'tracking', 'actor_name_snapshot', 'created_at']);
        $validaciones = ValidacionEntrega::query()->where('shipment_id', $shipmentId)->orderBy('id')->get();
        $validacion = $validaciones->first->vigente();
        $vigentes = $entregas->filter->vigente()->count() + $retornos->filter->vigente()->count();
        $anulados = $entregas->count() + $retornos->count() - $vigentes;

        return [
            'envio' => $envio,
            'items' => $items,
            'entregas' => $entregas,
            'retornos' => $retornos,
            'cita_recogida' => $cita,
            'resumen' => $resumen,
            'estado_entrega' => self::estadoEntrega($resumen['despachadas'], $resumen['entregadas'], $resumen['retornadas']),
            'validacion' => self::validacion($vigentes, $anulados, $validacion !== null, $this->listaParaValidar($resumen, $devoluciones)),
            'validacion_registro' => $validacion,
            'lista_para_validar' => $validacion === null && $this->listaParaValidar($resumen, $devoluciones),
            'contacto' => $this->contacto((int) $envio->order_id),
            'guia' => $guiaFila,
            'productos' => $productos,
            'tipo_producto' => $this->tipos->porEnvios([$shipmentId])[$shipmentId] ?? ['principal' => '', 'otros' => 0],
            'devoluciones' => $devoluciones,
            'estado_devolucion' => self::estadoDevolucionDelEnvio($devoluciones),
            'historial' => $this->historial($envio, $guiaFila, $entregas, $retornos, $recepciones, $validaciones),
        ];
    }

    /**
     * Estado de una devolucion segun lo registrado: pendiente_recibir (aun no llego a bodega) · requiere_revision (sin bodega de
     * origen determinable: no se puede recibir ni reintegrar) · recibida · recibida_novedades · reintegrada · reintegrada_parcial.
     * Registrada ≠ recibida ≠ reintegrada: solo una recepcion confirmada con unidades APTAS produce inventario.
     *
     * @param  array{esperadas: int, reintegradas: int, novedad: int, faltantes: int}|null  $recepcion
     */
    public static function estadoDevolucion(bool $bodegaDeterminada, ?array $recepcion): string
    {
        if ($recepcion === null) {
            return $bodegaDeterminada ? 'pendiente_recibir' : 'requiere_revision';
        }

        return match (true) {
            $recepcion['esperadas'] > 0 && $recepcion['reintegradas'] >= $recepcion['esperadas'] => 'reintegrada',
            $recepcion['reintegradas'] > 0 => 'reintegrada_parcial',
            $recepcion['novedad'] > 0 || $recepcion['faltantes'] > 0 => 'recibida_novedades',
            default => 'recibida',
        };
    }

    /** Entrega cerrada operativamente: nada pendiente, algo entregado y ninguna devolucion vigente por recibir. */
    private function listaParaValidar(array $resumen, array $devoluciones): bool
    {
        $sinRecibir = array_filter($devoluciones, fn ($d) => $d['vigente'] && in_array($d['estado'], ['pendiente_recibir', 'requiere_revision'], true));

        return $resumen['pendientes'] === 0 && $resumen['entregadas'] > 0 && $sinRecibir === [];
    }

    /** Estado que se muestra del envio: el que mas atencion requiere entre sus devoluciones vigentes (null si no hay). */
    public static function estadoDevolucionDelEnvio(array $devoluciones): ?string
    {
        $orden = ['requiere_revision', 'pendiente_recibir', 'recibida_novedades', 'reintegrada_parcial', 'recibida', 'reintegrada'];
        $estados = array_column(array_filter($devoluciones, fn ($d) => $d['vigente']), 'estado');

        foreach ($orden as $e) {
            if (in_array($e, $estados, true)) {
                return $e;
            }
        }

        return null;
    }

    /**
     * Devoluciones del envio con su recepcion (si la hay) y las lineas por recibir. Solo lectura.
     *
     * @param  Collection<int, Retorno>  $retornos
     * @param  Collection<int, RecepcionDevolucion>  $recepciones
     * @param  array<int, array{producto: ?string, color: ?string, talla: string, sku: ?string}>  $etiquetas
     * @return list<array<string, mixed>>
     */
    private function devoluciones(object $envio, Collection $retornos, Collection $recepciones, array $etiquetas): array
    {
        $bodega = (int) ($envio->inventory_source_id ?? 0) > 0 && DB::table('inventory_sources')->where('id', $envio->inventory_source_id)->exists();
        $salida = [];

        foreach ($retornos->sortBy('id') as $r) {
            $recepcion = $recepciones->first(fn (RecepcionDevolucion $x) => (int) $x->retorno_id === (int) $r->id && $x->vigente());
            $lineas = $r->items->map(function ($i) use ($recepcion, $etiquetas) {
                $linea = $recepcion?->items->firstWhere('retorno_item_id', $i->id);

                return [
                    'retorno_item_id' => (int) $i->id,
                    'shipment_item_id' => (int) $i->shipment_item_id,
                    'esperadas' => (int) $i->cantidad,
                    'etiqueta' => $etiquetas[$i->shipment_item_id] ?? ['producto' => null, 'color' => null, 'talla' => '', 'sku' => null],
                    'recibidas' => $linea?->recibidas,
                    'aptas' => $linea?->aptas,
                    'novedad' => $linea?->novedad,
                    'faltantes' => $linea?->faltantes,
                    'motivo' => $linea?->motivo_novedad,
                    'valor_devuelto' => $linea?->valor_devuelto === null ? null : (float) $linea->valor_devuelto,
                    'reingreso' => $linea?->reingreso_estado,
                ];
            })->values()->all();

            $resumen = $recepcion ? [
                'esperadas' => (int) $recepcion->items->sum('esperadas'),
                'reintegradas' => (int) $recepcion->items->where('reingreso_estado', 'reingresado')->sum('aptas'),
                'novedad' => (int) $recepcion->items->sum('novedad'),
                'faltantes' => (int) $recepcion->items->sum('faltantes'),
            ] : null;

            $salida[] = [
                'id' => (int) $r->id,
                'vigente' => $r->vigente(),
                'fecha' => $r->fecha_retorno->toDateString(),
                'motivo' => $r->motivo,
                'esperadas' => (int) $r->items->sum('cantidad'),
                'estado' => $r->vigente() ? self::estadoDevolucion($bodega, $resumen) : 'anulada',
                'bodega_determinada' => $bodega,
                'lineas' => $lineas,
                'recepcion' => $recepcion ? [
                    'id' => (int) $recepcion->id,
                    'fecha' => $recepcion->fecha_recepcion->toDateString(),
                    'actor' => $recepcion->actor_name_snapshot,
                    'observacion' => $recepcion->observacion,
                    // C5: lo recibido deja de ser exigible al cliente (valor historico; NULL = recepcion anterior a C5).
                    'valor_devuelto' => $recepcion->items->contains(fn ($i) => $i->valor_devuelto !== null) ? round((float) $recepcion->items->sum('valor_devuelto'), 2) : null,
                    'resumen' => $resumen,
                    // Solo se puede anular si ninguna unidad volvio al inventario.
                    'anulable' => $resumen !== null && $resumen['reintegradas'] === 0,
                ] : null,
            ];
        }

        return $salida;
    }

    /**
     * Eventos del envio en orden cronologico. Solo lo que existe: despacho (Shipment + guia), entregas, devoluciones,
     * recepciones y las anulaciones de cada uno.
     *
     * @return list<array<string, mixed>>
     */
    private function historial(object $envio, ?object $guia, Collection $entregas, Collection $retornos, Collection $recepciones, Collection $validaciones): array
    {
        $fuentes = fn ($r) => trans('logistica::app.fuentes.'.$r->fuente->value);
        $eventos = [[
            'tipo' => 'despacho', 'id' => (int) $envio->id, 'cuando' => (string) $envio->created_at, 'actor' => $guia->actor_name_snapshot ?? null,
            'fuente' => null, 'detalle' => $guia->numero_guia ?? $envio->track_number, 'cantidad' => (int) $envio->total_qty, 'vigente' => true,
        ]];

        foreach ($entregas as $e) {
            $eventos[] = ['tipo' => 'entrega', 'id' => (int) $e->id, 'cuando' => $e->created_at->toDateTimeString(), 'fecha_real' => $e->fecha_entrega->toDateString(), 'actor' => $e->actor_name_snapshot, 'fuente' => $fuentes($e), 'detalle' => $e->evidencia_referencia, 'cantidad' => (int) $e->items->sum('cantidad'), 'vigente' => $e->vigente()];
        }

        foreach ($retornos as $r) {
            $eventos[] = ['tipo' => 'devolucion', 'id' => (int) $r->id, 'cuando' => $r->created_at->toDateTimeString(), 'fecha_real' => $r->fecha_retorno->toDateString(), 'actor' => $r->actor_name_snapshot, 'fuente' => $fuentes($r), 'detalle' => $r->motivo, 'cantidad' => (int) $r->items->sum('cantidad'), 'vigente' => $r->vigente()];
        }

        foreach ($recepciones as $x) {
            $eventos[] = [
                'tipo' => 'recepcion', 'id' => (int) $x->id, 'cuando' => $x->created_at->toDateTimeString(), 'fecha_real' => $x->fecha_recepcion->toDateString(), 'actor' => $x->actor_name_snapshot, 'fuente' => null,
                'detalle' => $x->observacion, 'cantidad' => (int) $x->items->sum('recibidas'), 'aptas' => (int) $x->items->sum('aptas'), 'novedad' => (int) $x->items->sum('novedad'),
                'faltantes' => (int) $x->items->sum('faltantes'), 'reintegradas' => (int) $x->items->where('reingreso_estado', 'reingresado')->sum('aptas'), 'vigente' => $x->vigente(),
            ];
        }

        foreach ($validaciones as $v) {
            $eventos[] = ['tipo' => 'validacion', 'id' => (int) $v->id, 'cuando' => $v->created_at->toDateTimeString(), 'actor' => $v->actor_name_snapshot, 'fuente' => null, 'detalle' => $v->observacion, 'cantidad' => (int) $v->entregadas, 'vigente' => $v->vigente()];
        }

        // Cada anulacion es un evento propio (quien, cuando, motivo) que se muestra a continuacion del registro anulado.
        foreach ([['entrega', $entregas], ['devolucion', $retornos], ['recepcion', $recepciones], ['validacion', $validaciones]] as [$tipo, $coleccion]) {
            foreach ($coleccion as $x) {
                if (! $x->vigente()) {
                    $eventos[] = ['tipo' => 'anulacion', 'de' => $tipo, 'id' => (int) $x->id, 'cuando' => optional($x->anulada_en)->toDateTimeString(), 'actor' => $x->anulada_por_name_snapshot, 'fuente' => null, 'detalle' => $x->motivo_anulacion, 'vigente' => false];
                }
            }
        }

        usort($eventos, fn ($a, $b) => strcmp((string) $a['cuando'], (string) $b['cuando']) ?: ($a['id'] <=> $b['id']));

        return $eventos;
    }

    /**
     * shipment_item_id => lo necesario para nombrar una linea (producto, color, talla, sku).
     *
     * @return array<int, array{producto: ?string, color: ?string, talla: string, sku: ?string}>
     */
    private function etiquetasDeItems(array $productos): array
    {
        $mapa = [];

        foreach ($productos as $p) {
            foreach ($p['filas'] as $f) {
                $mapa[$f['shipment_item_id']] = ['producto' => $p['nombre'], 'color' => $p['color'], 'talla' => $f['talla'], 'sku' => $f['sku'] ?? $p['sku']];
            }
        }

        return $mapa;
    }

    /**
     * Cliente, telefono, destino y direccion de envio del pedido (solo lectura, para el detalle del envio).
     *
     * @return array{telefono: ?string, destino: ?string, direccion: ?string}
     */
    private function contacto(int $orderId): array
    {
        $d = DB::table('addresses')->where('order_id', $orderId)->where('address_type', 'order_shipping')->first();

        if (! $d) {
            return ['telefono' => null, 'destino' => null, 'direccion' => null];
        }

        $departamento = DB::table('country_states')->where('country_code', $d->country)->where('code', $d->state)->value('default_name');

        return [
            'telefono' => $d->phone,
            'destino' => trim($d->city.', '.($departamento ?? $d->state), ', '),
            'direccion' => trim(str_replace(["\r\n", "\n"], ', ', (string) $d->address)),
        ];
    }

    /**
     * Unidades del envio agrupadas por PRODUCTO (linea × color) con una fila por talla: es lo que el detalle muestra y el listado no.
     *
     * @param  array<int, array{shipment_item_id: int, order_item_id: ?int, sku: ?string, nombre: ?string, despachadas: int, entregadas: int, retornadas: int, pendientes: int}>  $items
     * @return list<array{clave: string, nombre: ?string, sku: ?string, color: ?string, filas: list<array{shipment_item_id: int, talla: string, despachadas: int, entregadas: int, retornadas: int, pendientes: int}>}>
     */
    private function productosDeEnvio(array $items): array
    {
        $ordenItems = DB::table('order_items')->whereIn('id', array_filter(array_column($items, 'order_item_id')))->get(['id', 'product_id', 'name', 'sku', 'additional'])->keyBy('id');
        $grupos = [];

        foreach ($items as $i) {
            $linea = $ordenItems[$i['order_item_id']] ?? null;
            $atributos = (array) (json_decode((string) ($linea->additional ?? ''), true)['attributes'] ?? []);
            $clave = $linea ? 'p'.$linea->product_id : 's'.$i['shipment_item_id'];

            $grupos[$clave] ??= [
                'clave' => $clave,
                'nombre' => $linea->name ?? $i['nombre'],
                'sku' => $linea->sku ?? $i['sku'],
                'color' => ($atributos['color']['option_label'] ?? null) ?: null,
                'filas' => [],
            ];

            $talla = collect($atributos)->except('color')->pluck('option_label')->filter()->implode(' · ');

            $grupos[$clave]['filas'][] = [
                'shipment_item_id' => $i['shipment_item_id'],
                'sku' => $i['sku'],
                'talla' => $talla !== '' ? $talla : (string) ($i['sku'] ?? '—'),
                'despachadas' => $i['despachadas'],
                'entregadas' => $i['entregadas'],
                'retornadas' => $i['retornadas'],
                'pendientes' => $i['pendientes'],
            ];
        }

        return array_values($grupos);
    }

    /** Un renglon por envio con despachadas / entregadas / retornadas (eventos vigentes). */
    private function basePorEnvio(): Builder
    {
        $vigente = Estado::CONFIRMADO->value;

        return DB::table('shipments as s')->join('orders as o', 'o.id', '=', 's.order_id')
            ->select([
                's.id as shipment_id', 's.order_id', 'o.increment_id', 's.carrier_code', 's.carrier_title', 's.track_number',
                's.created_at as despachado_en', 'o.shipping_method', 's.inventory_source_id as bodega_id',
            ])
            ->selectRaw("TRIM(CONCAT(COALESCE(o.customer_first_name, ''), ' ', COALESCE(o.customer_last_name, ''))) AS cliente")
            ->selectRaw('(SELECT COALESCE(SUM(si.qty), 0) FROM shipment_items si WHERE si.shipment_id = s.id) AS despachadas')
            ->selectRaw('(SELECT COALESCE(SUM(ei.cantidad), 0) FROM bylopez_lg_entrega_items ei JOIN bylopez_lg_entregas e ON e.id = ei.entrega_id WHERE e.shipment_id = s.id AND e.estado = ?) AS entregadas', [$vigente])
            ->selectRaw('(SELECT COALESCE(SUM(ri.cantidad), 0) FROM bylopez_lg_retorno_items ri JOIN bylopez_lg_retornos r ON r.id = ri.retorno_id WHERE r.shipment_id = s.id AND r.estado = ?) AS retornadas', [$vigente])
            ->selectRaw('(SELECT COUNT(*) FROM bylopez_lg_entregas e WHERE e.shipment_id = s.id AND e.estado = ?) + (SELECT COUNT(*) FROM bylopez_lg_retornos r WHERE r.shipment_id = s.id AND r.estado = ?) AS eventos_vigentes', [$vigente, $vigente])
            ->selectRaw('(SELECT COUNT(*) FROM bylopez_lg_entregas e WHERE e.shipment_id = s.id AND e.estado <> ?) + (SELECT COUNT(*) FROM bylopez_lg_retornos r WHERE r.shipment_id = s.id AND r.estado <> ?) AS eventos_anulados', [$vigente, $vigente])
            ->selectRaw(self::SQL_POR_RECIBIR.' AS devoluciones_por_recibir', [$vigente, $vigente])
            ->selectRaw('(SELECT COUNT(*) FROM bylopez_lg_validaciones_entrega v WHERE v.shipment_id = s.id AND v.estado = ?) AS validada', [$vigente]);
    }

    /**
     * @param  list<int>  $shipmentItemIds
     * @return array<int, int>
     */
    private function sumasPorItem(string $items, string $eventos, string $fk, array $shipmentItemIds): array
    {
        if ($shipmentItemIds === []) {
            return [];
        }

        return DB::table($items.' as i')->join($eventos.' as e', 'e.id', '=', 'i.'.$fk)
            ->whereIn('i.shipment_item_id', $shipmentItemIds)
            ->where('e.estado', Estado::CONFIRMADO->value)
            ->groupBy('i.shipment_item_id')
            ->pluck(DB::raw('SUM(i.cantidad) as total'), 'i.shipment_item_id')
            ->map(fn ($v) => (int) $v)->all();
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, int>
     */
    private function sumasPorPedido(string $items, string $eventos, string $fk, array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return DB::table($items.' as i')->join($eventos.' as e', 'e.id', '=', 'i.'.$fk)
            ->whereIn('e.order_id', $orderIds)
            ->where('e.estado', Estado::CONFIRMADO->value)
            ->groupBy('e.order_id')
            ->pluck(DB::raw('SUM(i.cantidad) as total'), 'e.order_id')
            ->map(fn ($v) => (int) $v)->all();
    }
}
