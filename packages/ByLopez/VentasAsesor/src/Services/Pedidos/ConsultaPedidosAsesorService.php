<?php

namespace ByLopez\VentasAsesor\Services\Pedidos;

use ByLopez\Logistica\Services\Guias\ConsultaGuiaService;
use ByLopez\VentasAsesor\DataObjects\ConfirmacionVenta;
use ByLopez\VentasAsesor\DataObjects\SaldoPedido;
use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Support\Pedidos\EstadoPagoPedido;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Sales\Models\Order;
use Webkul\User\Contracts\Admin;

/**
 * "Mis ventas / Pedidos" (Fase 05, listado): vista comercial de LOS PEDIDOS DE BAGISTO.
 * No crea ni copia pedidos; solo lee `orders` y la atribucion comercial 1:1
 * (`bylopez_va_order_attributions`, UNIQUE(order_id)).
 *
 * Visibilidad (RA-3 / RA-6, AutorizacionVentasAsesor):
 *  - PROPIOS (view_own): SOLO pedidos cuya atribucion tiene advisor_id = el asesor. Los de
 *    empresa (B), sin asignar (C) o sin fila de atribucion (historico, D-12) no aparecen.
 *  - TODOS (view_all): todos los pedidos, incluidos B, C y sin atribucion.
 *  - NINGUNO: nada (el controlador ya responde 403 antes de llegar aqui).
 * El alcance se aplica en SQL y ningun filtro del request puede ampliarlo.
 *
 * Rendimiento: una consulta paginada con joins (atribucion, direcciones del pedido), mas
 * agregados en lote para la pagina (facturas, transacciones, despacho). Sin N+1.
 */
class ConsultaPedidosAsesorService
{
    public const POR_PAGINA = 20;

    /** Valores especiales del filtro `asesor` (solo con view_all). */
    public const FILTRO_EMPRESA = 'empresa';

    public const FILTRO_SIN_ASIGNAR = 'sin_asignar';

    public const FILTRO_SIN_ATRIBUCION = 'sin_atribucion';

    /** Tarjetas KPI clicables: valores del parametro `?resumen=` (ver resumen()). */
    public const RESUMEN_HOY = 'hoy';

    public const RESUMEN_ABIERTOS = 'abiertos';

    public const RESUMEN_POR_DESPACHAR = 'por_despachar';

    public const RESUMEN_PENDIENTES_PAGO = 'pendientes_pago';

    public const RESUMENES = [self::RESUMEN_HOY, self::RESUMEN_ABIERTOS, self::RESUMEN_POR_DESPACHAR, self::RESUMEN_PENDIENTES_PAGO];

    public const FILTROS_PROPIEDAD = [self::FILTRO_EMPRESA, self::FILTRO_SIN_ASIGNAR, self::FILTRO_SIN_ATRIBUCION];

    /** Estados de Bagisto en los que el pedido sigue abierto. */
    public const ESTADOS_ABIERTOS = [Order::STATUS_PENDING, Order::STATUS_PENDING_PAYMENT, Order::STATUS_PROCESSING];

    /** Estados que no cuentan como venta en «Ventas de hoy» (decision de Camilo, 2026-10-05). */
    private const ESTADOS_FUERA_DE_VENTAS = [Order::STATUS_CANCELED, Order::STATUS_FRAUD, Order::STATUS_CLOSED];

    private const ESTADOS_SIN_DESPACHO = [Order::STATUS_CANCELED, Order::STATUS_CLOSED, Order::STATUS_FRAUD];

    public function __construct(
        protected CarteraConsultaService $cartera,
        protected ConsultaGuiaService $guias,
    ) {}

    /**
     * Estado de pago y saldo de un pedido. Fuente UNICA segun el pedido (PAG-C, sin dos formulas
     * contradictorias): dentro de Cartera manda su dominio (aplicaciones + Transactions ajenas);
     * fuera de Cartera (historico, Cartera inactiva, cancelado...) rige la derivacion D-56 por facturas.
     *
     * @param  list<array{state: string, base_grand_total: float|string, transacciones: float|string}>  $facturas
     * @return array{0: string, 1: float, 2: ?SaldoPedido}
     */
    private function estadoYSaldo(string $estadoPedido, float $total, array $facturas, ?SaldoPedido $cartera): array
    {
        if ($cartera !== null && $cartera->enCartera) {
            return [...EstadoPagoPedido::desdeCartera($cartera), $cartera];
        }

        return [...EstadoPagoPedido::derivar($estadoPedido, $total, $facturas), null];
    }

    /** Pedidos visibles para el actor: el UNICO punto que aplica el alcance. */
    public function visibles(AlcanceVisibilidad $alcance, Admin $actor): Builder
    {
        $consulta = DB::table('orders as o')
            ->leftJoin('bylopez_va_order_attributions as atr', 'atr.order_id', '=', 'o.id');

        return match ($alcance) {
            AlcanceVisibilidad::TODOS => $consulta,
            AlcanceVisibilidad::PROPIOS => $consulta->where('atr.advisor_id', (int) $actor->id),
            AlcanceVisibilidad::NINGUNO => $consulta->whereRaw('1 = 0'),
        };
    }

    /**
     * @param  array{q: ?string, desde: ?string, hasta: ?string, estado: ?string, entrega: ?string, asesor: ?string}  $filtros
     */
    public function listar(AlcanceVisibilidad $alcance, Admin $actor, array $filtros, int $pagina = 1): LengthAwarePaginator
    {
        $consulta = $this->visibles($alcance, $actor)
            ->leftJoin('addresses as envio', fn ($j) => $j->on('envio.order_id', '=', 'o.id')->where('envio.address_type', 'order_shipping'))
            ->leftJoin('addresses as fact', fn ($j) => $j->on('fact.order_id', '=', 'o.id')->where('fact.address_type', 'order_billing'))
            ->select([
                'o.id', 'o.increment_id', 'o.created_at', 'o.status',
                'o.customer_id', 'o.customer_first_name', 'o.customer_last_name', 'o.customer_email',
                'o.total_qty_ordered', 'o.grand_total', 'o.base_grand_total', 'o.order_currency_code',
                'o.shipping_method', 'o.shipping_title',
                DB::raw('COALESCE(envio.phone, fact.phone) as telefono'),
                'atr.advisor_id', 'atr.advisor_name_snapshot', 'atr.commercial_owner_type',
            ]);

        // La tarjeta KPI activa (`resumen`) es un filtro mas: se combina (AND) con los demas.
        if (in_array($filtros['resumen'] ?? null, self::RESUMENES, true)) {
            $this->resumen($consulta, $filtros['resumen'], $alcance, $actor);
        }

        $this->aplicarFiltros($consulta, $alcance, $filtros);

        $paginador = $consulta->orderByDesc('o.created_at')->orderByDesc('o.id')
            ->paginate(self::POR_PAGINA, ['*'], 'page', max(1, $pagina));

        $ids = $paginador->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $pagos = $this->pagosEnLote($ids);
        $despachos = $this->despachosEnLote($ids);
        $carteras = $this->cartera->saldosDePedidos($ids);
        // Despacho V1: transportadora, numero de guia, tracking y si hay PDF. Sin flete ni recaudo (no son del asesor).
        $guias = $this->guias->deOrdenes($ids);

        $paginador->setCollection($paginador->getCollection()->map(function ($fila) use ($pagos, $despachos, $carteras, $guias) {
            $fila->guias = $guias[(int) $fila->id] ?? [];
            [$estadoPago, $saldoPedido, $cartera] = $this->estadoYSaldo((string) $fila->status, (float) $fila->base_grand_total, $pagos[(int) $fila->id] ?? [], $carteras[(int) $fila->id] ?? null);

            $fila->estado_pago = $estadoPago;
            $fila->saldo = $saldoPedido;
            $fila->pago_sin_revisar = (float) ($cartera?->sinRevisar ?? 0);
            $fila->pago_verificado = (float) ($cartera?->verificado ?? 0);
            $fila->estado_despacho = $this->estadoDespacho((string) $fila->status, $despachos[(int) $fila->id] ?? null);
            $fila->propietario = $this->propietario($fila);

            return $fila;
        }));

        return $paginador;
    }

    /**
     * Lineas del pedido agrupadas por PRODUCTO (el configurable = linea x color) con una fila por talla, para leer
     * «Azul Rey · Hombre: L × 2, XL × 1» en vez de una fila por variante. La talla (o cualquier opcion que no sea el
     * color) sale de los atributos que Bagisto guarda en la linea; sin ella se muestra el SKU. Cada fila conserva su
     * propio precio: la misma prenda puede venderse a precios distintos. Solo lectura, no recalcula nada.
     *
     * @param  Collection<int, object>  $items
     * @return list<array{producto_id: int, nombre: string, sku: string, filas: list<array{talla: string, qty_ordered: int, qty_shipped: int, price: mixed, total: mixed}>}>
     */
    protected function productosAgrupados(Collection $items): array
    {
        return $items->groupBy('product_id')->map(function (Collection $lineas, $productoId) {
            $primera = $lineas->first();

            return [
                'producto_id' => (int) $productoId,
                'nombre' => (string) $primera->name,
                'sku' => (string) $primera->sku,
                'filas' => $lineas->map(function ($l) {
                    $atributos = (array) (json_decode((string) $l->additional, true)['attributes'] ?? []);
                    $tallas = collect($atributos)->except('color')->pluck('option_label')->filter()->implode(' · ');

                    return [
                        'talla' => $tallas !== '' ? $tallas : (string) $l->sku,
                        'qty_ordered' => (int) $l->qty_ordered,
                        'qty_shipped' => (int) $l->qty_shipped,
                        'price' => $l->price,
                        'total' => $l->total,
                    ];
                })->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * Un pedido CONCRETO, sin alcance (el controlador decide con puedeVerRegistroDe()).
     *
     * @return array{pedido: object, metodo_pago: ?string, saldo: float, advisor_id: ?int, items: Collection, envio: ?object, estado_pago: string, estado_despacho: string}|null
     */
    public function detalle(int $orderId): ?array
    {
        $pedido = DB::table('orders as o')
            ->leftJoin('bylopez_va_order_attributions as atr', 'atr.order_id', '=', 'o.id')
            ->where('o.id', $orderId)
            ->select(['o.*', 'atr.advisor_id', 'atr.advisor_name_snapshot', 'atr.commercial_owner_type'])
            ->first();

        if (! $pedido) {
            return null;
        }

        $envio = DB::table('addresses')->where('order_id', $orderId)
            ->whereIn('address_type', ['order_shipping', 'order_billing'])
            ->orderByRaw("address_type = 'order_shipping' DESC")->first();

        [$estadoPago, $saldo, $cartera] = $this->estadoYSaldo(
            (string) $pedido->status,
            (float) $pedido->base_grand_total,
            $this->pagosEnLote([$orderId])[$orderId] ?? [],
            $this->cartera->saldosDePedidos([$orderId])[$orderId] ?? null,
        );

        $pago = DB::table('order_payment')->where('order_id', $orderId)->first(['method', 'method_title']);

        $pedido->propietario = $this->propietario($pedido);

        return [
            'pedido' => $pedido,
            'metodo_pago' => $pago ? (string) ($pago->method_title ?: core()->getConfigData('sales.payment_methods.'.$pago->method.'.title') ?: $pago->method) : null,
            'saldo' => $saldo,
            'cartera' => $cartera,
            'advisor_id' => $pedido->advisor_id === null ? null : (int) $pedido->advisor_id,
            'items' => $items = DB::table('order_items')->where('order_id', $orderId)->whereNull('parent_id')
                ->orderBy('id')->get(['id', 'product_id', 'sku', 'name', 'qty_ordered', 'qty_shipped', 'qty_canceled', 'price', 'total', 'additional']),
            'productos' => $this->productosAgrupados($items),
            'envio' => $envio,
            'guias' => $this->guias->deOrdenes([$orderId])[$orderId] ?? [],
            'estado_pago' => $estadoPago,
            'estado_despacho' => $this->estadoDespacho((string) $pedido->status, $this->despachosEnLote([$orderId])[$orderId] ?? null),
        ];
    }

    /**
     * KPIs SOLO con semantica real de Bagisto, sobre el alcance (no sobre los filtros):
     * ventas de hoy (sin cancelados, fraude ni cerrados), pedidos abiertos y pedidos con unidades
     * sin despachar, y pendientes de pago (misma regla que la columna Pago, ver
     * pendientesDePago()). Abonos, aplicaciones y cartera NO se calculan aqui.
     *
     * @return array{hoy_cantidad: int, hoy_total: float, abiertos: int, por_despachar: int, pendientes_pago: int}
     */
    public function indicadores(AlcanceVisibilidad $alcance, Admin $actor): array
    {
        // Cada tarjeta y su filtro (`?resumen=`) usan EXACTAMENTE el mismo criterio: resumen(). Asi el KPI y la
        // tabla nunca muestran conceptos distintos. Ventas de hoy: total bruto en moneda base.
        $hoy = $this->resumen($this->visibles($alcance, $actor), self::RESUMEN_HOY, $alcance, $actor)
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(o.base_grand_total), 0) as total')->first();

        return [
            'hoy_cantidad' => (int) ($hoy->cantidad ?? 0),
            'hoy_total' => (float) ($hoy->total ?? 0),
            'abiertos' => $this->resumen($this->visibles($alcance, $actor), self::RESUMEN_ABIERTOS, $alcance, $actor)->count(),
            'por_despachar' => $this->resumen($this->visibles($alcance, $actor), self::RESUMEN_POR_DESPACHAR, $alcance, $actor)->count(),
            'pendientes_pago' => $this->pendientesDePago($alcance, $actor),
        ];
    }

    /**
     * Criterio UNICO de cada tarjeta KPI de «Mis ventas» (D-56), aplicado sobre una consulta de pedidos visibles.
     *  - hoy: creados desde las 00:00 y fuera de cancelados, fraude y cerrados (en Bagisto 2.4.9 «closed» = todas
     *    las unidades reembolsadas o reembolsadas + canceladas, OrderRepository::isInClosedState; los reembolsos
     *    parciales NO se descuentan).
     *  - abiertos: pendiente, pago pendiente o procesando.
     *  - por_despachar: unidades FISICAS pendientes segun la misma fuente que la columna Despacho
     *    (agregadoDespacho): «sin despachar» o «despacho parcial». No se excluye `completed`: Bagisto puede
     *    marcarlo con un Shipment parcial (TransactionController al pagar completo con cualquier envio).
     *  - pendientes_pago: pedidos cuyo estado de pago es EXACTAMENTE «pendiente» (idsPendientesDePago).
     */
    private function resumen(Builder $consulta, string $resumen, AlcanceVisibilidad $alcance, Admin $actor): Builder
    {
        return match ($resumen) {
            self::RESUMEN_HOY => $this->soloVentasDeHoy($consulta),
            self::RESUMEN_ABIERTOS => $consulta->whereIn('o.status', self::ESTADOS_ABIERTOS),
            self::RESUMEN_POR_DESPACHAR => $consulta
                ->joinSub($this->agregadoDespacho(), 'desp', 'desp.order_id', '=', 'o.id')
                ->whereNotIn('o.status', self::ESTADOS_SIN_DESPACHO)
                ->where('desp.objetivo', '>', 0)
                ->whereColumn('desp.despachadas', '<', 'desp.objetivo'),
            self::RESUMEN_PENDIENTES_PAGO => ($ids = $this->idsPendientesDePago($alcance, $actor)) === []
                ? $consulta->whereRaw('1 = 0')
                : $consulta->whereIn('o.id', $ids),
            default => $consulta,
        };
    }

    /**
     * Criterio UNICO de «ventas de hoy» sobre una consulta de `orders as o` (KPI de Mis ventas y de Asesores): creados
     * desde las 00:00 y fuera de cancelados, fraude y cerrados.
     */
    public function soloVentasDeHoy(Builder $consulta): Builder
    {
        return $consulta->where('o.created_at', '>=', now()->startOfDay())->whereNotIn('o.status', self::ESTADOS_FUERA_DE_VENTAS);
    }

    /**
     * Pedidos visibles cuyo estado de pago es EXACTAMENTE «pendiente», con la misma regla que la columna Pago
     * (estadoYSaldo: Cartera para pedidos en Cartera, D-56 para el resto), por lotes. Solo lectura.
     *
     * @return list<int>
     */
    public function idsPendientesDePago(AlcanceVisibilidad $alcance, Admin $actor): array
    {
        $pendientes = [];

        $this->visibles($alcance, $actor)
            ->select(['o.id', 'o.status', 'o.base_grand_total'])
            ->orderBy('o.id')
            ->chunk(500, function ($filas) use (&$pendientes) {
                $ids = $filas->pluck('id')->map(fn ($id) => (int) $id)->all();
                $pagos = $this->pagosEnLote($ids);
                $carteras = $this->cartera->saldosDePedidos($ids);

                foreach ($filas as $fila) {
                    [$estado] = $this->estadoYSaldo((string) $fila->status, (float) $fila->base_grand_total, $pagos[(int) $fila->id] ?? [], $carteras[(int) $fila->id] ?? null);

                    if ($estado === ConfirmacionVenta::PAGO_PENDIENTE) {
                        $pendientes[] = (int) $fila->id;
                    }
                }
            });

        return $pendientes;
    }

    public function pendientesDePago(AlcanceVisibilidad $alcance, Admin $actor): int
    {
        return count($this->idsPendientesDePago($alcance, $actor));
    }

    /** Metodos de entrega presentes en los pedidos visibles (para el filtro). */
    public function metodosEntrega(AlcanceVisibilidad $alcance, Admin $actor): array
    {
        return $this->visibles($alcance, $actor)->whereNotNull('o.shipping_method')
            ->groupBy('o.shipping_method')
            ->selectRaw('o.shipping_method as codigo, MAX(o.shipping_title) as titulo')
            ->orderBy('titulo')->get()
            ->map(fn ($m) => ['codigo' => (string) $m->codigo, 'titulo' => (string) ($m->titulo ?: $m->codigo)])
            ->all();
    }

    /** Asesores con pedidos atribuidos (solo para el filtro de view_all). */
    public function asesoresConPedidos(): array
    {
        return DB::table('bylopez_va_order_attributions')->whereNotNull('advisor_id')
            ->groupBy('advisor_id')
            ->selectRaw('advisor_id as id, MAX(advisor_name_snapshot) as nombre')
            ->orderBy('nombre')->get()
            ->map(fn ($a) => ['id' => (int) $a->id, 'nombre' => (string) ($a->nombre ?: '#'.$a->id)])
            ->all();
    }

    private function aplicarFiltros(Builder $consulta, AlcanceVisibilidad $alcance, array $filtros): void
    {
        if ($filtros['q'] !== null) {
            $texto = '%'.addcslashes($filtros['q'], '%_\\').'%';
            $digitos = preg_replace('/\D+/', '', $filtros['q']);

            $consulta->where(function ($w) use ($texto, $digitos) {
                $w->where('o.increment_id', 'like', $texto)
                    ->orWhereRaw("CONCAT(COALESCE(o.customer_first_name, ''), ' ', COALESCE(o.customer_last_name, '')) LIKE ?", [$texto]);

                if (strlen($digitos) >= 4) {
                    $w->orWhere('envio.phone', 'like', '%'.$digitos.'%')
                        ->orWhere('fact.phone', 'like', '%'.$digitos.'%');
                }
            });
        }

        if ($filtros['desde'] !== null) {
            $consulta->where('o.created_at', '>=', $filtros['desde'].' 00:00:00');
        }

        if ($filtros['hasta'] !== null) {
            $consulta->where('o.created_at', '<=', $filtros['hasta'].' 23:59:59');
        }

        if ($filtros['estado'] !== null) {
            $consulta->where('o.status', $filtros['estado']);
        }

        if ($filtros['entrega'] !== null) {
            $consulta->where('o.shipping_method', $filtros['entrega']);
        }

        // El filtro por asesor/propiedad SOLO existe con view_all: con view_own se ignora
        // (nunca amplia ni cambia el alcance ya aplicado en visibles()).
        if ($alcance === AlcanceVisibilidad::TODOS && $filtros['asesor'] !== null) {
            match ($filtros['asesor']) {
                self::FILTRO_EMPRESA => $consulta->where('atr.commercial_owner_type', TipoPropiedadComercial::EMPRESA->value),
                self::FILTRO_SIN_ASIGNAR => $consulta->where('atr.commercial_owner_type', TipoPropiedadComercial::SIN_ASIGNAR->value),
                self::FILTRO_SIN_ATRIBUCION => $consulta->whereNull('atr.id'),
                default => $consulta->where('atr.advisor_id', (int) $filtros['asesor']),
            };
        }
    }

    /**
     * Facturas y transacciones de varios pedidos en DOS consultas.
     *
     * @param  list<int>  $ids
     * @return array<int, list<array{state: string, base_grand_total: float, transacciones: float}>>
     */
    private function pagosEnLote(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $facturas = DB::table('invoices')->whereIn('order_id', $ids)->get(['id', 'order_id', 'state', 'base_grand_total']);
        $transacciones = $facturas->isEmpty() ? collect() : DB::table('order_transactions')
            ->whereIn('invoice_id', $facturas->pluck('id'))->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(amount) as total')->pluck('total', 'invoice_id');

        $porPedido = [];

        foreach ($facturas as $f) {
            $porPedido[(int) $f->order_id][] = [
                'state' => (string) $f->state,
                'base_grand_total' => (float) $f->base_grand_total,
                'transacciones' => (float) ($transacciones[$f->id] ?? 0),
            ];
        }

        return $porPedido;
    }

    /**
     * Unidades objetivo y despachadas por pedido (lineas padre), en UNA consulta.
     *
     * @param  list<int>  $ids
     * @return array<int, array{objetivo: float, despachadas: float}>
     */
    private function despachosEnLote(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->agregadoDespacho()->whereIn('order_id', $ids)
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->order_id => ['objetivo' => (float) $r->objetivo, 'despachadas' => (float) $r->despachadas]])
            ->all();
    }

    /**
     * UNICA fuente de la señal de despacho (D-56), usada por la columna y por el KPI: por pedido,
     * sobre lineas padre, unidades objetivo (pedidas − canceladas) y enviadas (`qty_shipped`, que
     * Bagisto solo escribe desde Shipments).
     */
    private function agregadoDespacho(): Builder
    {
        return DB::table('order_items')->whereNull('parent_id')
            ->groupBy('order_id')
            ->selectRaw('order_id, SUM(qty_ordered - qty_canceled) as objetivo, SUM(qty_shipped) as despachadas');
    }

    /** Derivado de los envios registrados en Bagisto. No existe aun «entregado» (Logistica, D-14). */
    private function estadoDespacho(string $estadoPedido, ?array $despacho): string
    {
        if (in_array($estadoPedido, self::ESTADOS_SIN_DESPACHO, true) || $despacho === null || $despacho['objetivo'] <= 0) {
            return 'no_aplica';
        }

        return match (true) {
            $despacho['despachadas'] <= 0 => 'pendiente',
            $despacho['despachadas'] < $despacho['objetivo'] => 'parcial',
            default => 'despachado',
        };
    }

    /** Propietario comercial legible (solo se muestra con view_all). */
    private function propietario(object $fila): string
    {
        return match ($fila->commercial_owner_type ?? null) {
            TipoPropiedadComercial::ASESOR->value => (string) ($fila->advisor_name_snapshot ?: 'Asesor #'.$fila->advisor_id),
            TipoPropiedadComercial::EMPRESA->value => trans('ventasasesor::app.pedidos.propietario.empresa'),
            TipoPropiedadComercial::SIN_ASIGNAR->value => trans('ventasasesor::app.pedidos.propietario.sin-asignar'),
            default => trans('ventasasesor::app.pedidos.propietario.sin-atribucion'),
        };
    }
}
