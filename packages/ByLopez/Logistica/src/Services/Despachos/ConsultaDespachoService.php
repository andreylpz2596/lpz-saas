<?php

namespace ByLopez\Logistica\Services\Despachos;

use ByLopez\Logistica\Services\Entregas\ConsultaEntregaService;
use ByLopez\Logistica\Services\Guias\ConsultaGuiaService;
use ByLopez\Logistica\Support\PuntosRecogida;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\Sales\Models\Order;

/**
 * Bandeja «Logistica › Despachos»: cola de trabajo de la operaria. Solo lo necesario para ejecutar el envio (pedido,
 * cliente, destino, tipo de entrega, estado, transportadora/guia, recaudo). Nada de margen, costos, comision ni del valor
 * comercial del pedido.
 *
 * El estado de despacho usa la MISMA definicion que «Mis ventas» (D-56): unidades objetivo (pedidas − canceladas) frente
 * a unidades enviadas (`qty_shipped`, que Bagisto solo escribe desde Shipments), sobre lineas padre.
 */
class ConsultaDespachoService
{
    public const POR_PAGINA = 25;

    public const ESTADOS = ['por_despachar', 'sin_despachar', 'parcial', 'despachado', 'todos'];

    public const TIPOS = ['todos', 'recogida', 'domicilio', 'nacional'];

    private const ESTADOS_PEDIDO_EXCLUIDOS = [Order::STATUS_CANCELED, Order::STATUS_CLOSED, Order::STATUS_FRAUD];

    public function __construct(
        protected ConsultaGuiaService $guias,
        protected ConsultaEntregaService $entregas,
    ) {}

    /**
     * @param  array{q: ?string, estado: string, tipo: string}  $filtros
     */
    public function listar(array $filtros, int $pagina = 1): LengthAwarePaginator
    {
        $consulta = $this->base();

        match ($filtros['estado'] ?? 'por_despachar') {
            'sin_despachar' => $consulta->whereRaw('i.despachadas <= 0'),
            'parcial' => $consulta->whereRaw('i.despachadas > 0 AND i.despachadas < i.objetivo'),
            'despachado' => $consulta->whereRaw('i.despachadas >= i.objetivo'),
            'todos' => null,
            default => $consulta->whereRaw('i.despachadas < i.objetivo'),
        };

        match ($filtros['tipo'] ?? 'todos') {
            'recogida' => $consulta->where('o.shipping_method', PuntosRecogida::METODO),
            'domicilio' => $consulta->where('o.shipping_method', 'domicilio_local'),
            'nacional' => $consulta->where('o.shipping_method', 'envio_nacional'),
            default => null,
        };

        if (($texto = $filtros['q'] ?? null) !== null) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texto).'%';
            $consulta->where(fn (Builder $w) => $w
                ->where('o.increment_id', 'like', $like)
                ->orWhere('o.customer_first_name', 'like', $like)
                ->orWhere('o.customer_last_name', 'like', $like)
                ->orWhere('dir.phone', 'like', $like)
                ->orWhere('dir.city', 'like', $like)
                ->orWhereExists(fn (Builder $g) => $g->selectRaw('1')->from('bylopez_lg_guias as g')
                    ->whereColumn('g.order_id', 'o.id')->where('g.numero_guia', 'like', $like)));
        }

        // Cola de trabajo: lo mas antiguo primero cuando se busca lo pendiente; el historial, lo mas reciente primero.
        $porHacer = in_array($filtros['estado'] ?? 'por_despachar', ['por_despachar', 'sin_despachar', 'parcial'], true);
        $porHacer ? $consulta->orderBy('o.created_at')->orderBy('o.id') : $consulta->orderByDesc('o.created_at')->orderByDesc('o.id');

        $paginador = $consulta->paginate(self::POR_PAGINA, ['*'], 'page', max(1, $pagina));
        $ids = $paginador->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $guias = $this->guias->deOrdenes($ids, true);
        $entregas = $this->entregas->resumenPedidos($ids);

        $paginador->setCollection($paginador->getCollection()->map(function ($f) use ($guias, $entregas) {
            $f->estado_despacho = match (true) {
                (float) $f->despachadas <= 0 => 'sin_despachar',
                (float) $f->despachadas < (float) $f->objetivo => 'parcial',
                default => 'despachado',
            };
            $f->es_recogida = $f->shipping_method === PuntosRecogida::METODO;
            $f->es_contraentrega = $f->metodo_pago === 'cashondelivery';
            $f->guias = $guias[(int) $f->id] ?? [];
            $f->entrega = $entregas[(int) $f->id] ?? ['despachadas' => 0, 'entregadas' => 0, 'retornadas' => 0, 'pendientes' => 0];

            return $f;
        }));

        return $paginador;
    }

    /** @return array{sin_despachar: int, parcial: int, despachados_hoy: int} */
    public function indicadores(): array
    {
        $conteo = DB::query()->fromSub($this->base(), 'x')
            ->selectRaw('COALESCE(SUM(x.despachadas <= 0), 0) as sin, COALESCE(SUM(x.despachadas > 0 AND x.despachadas < x.objetivo), 0) as parcial')
            ->first();

        return [
            'sin_despachar' => (int) ($conteo->sin ?? 0),
            'parcial' => (int) ($conteo->parcial ?? 0),
            'despachados_hoy' => (int) DB::table('shipments')->whereDate('created_at', now()->toDateString())->count(),
        ];
    }

    /** Un renglon por pedido con unidades que despachar o ya despachadas; pedidos cerrados/cancelados/fraude fuera. */
    private function base(): Builder
    {
        $unidades = DB::table('order_items')->whereNull('parent_id')->groupBy('order_id')
            ->selectRaw('order_id, SUM(qty_ordered - qty_canceled) as objetivo, SUM(qty_shipped) as despachadas');

        return DB::table('orders as o')
            ->joinSub($unidades, 'i', 'i.order_id', '=', 'o.id')
            ->join('addresses as dir', fn ($j) => $j->on('dir.order_id', '=', 'o.id')->where('dir.address_type', 'order_shipping'))
            ->leftJoin('order_payment as pay', 'pay.order_id', '=', 'o.id')
            ->whereNotIn('o.status', self::ESTADOS_PEDIDO_EXCLUIDOS)
            ->where('i.objetivo', '>', 0)
            ->select([
                'o.id', 'o.increment_id', 'o.created_at', 'o.status', 'o.customer_first_name', 'o.customer_last_name',
                'o.shipping_method', 'o.shipping_title', 'pay.method as metodo_pago',
                'dir.phone as telefono', 'dir.city as ciudad', 'dir.address as direccion',
                'i.objetivo', 'i.despachadas',
            ]);
    }
}
