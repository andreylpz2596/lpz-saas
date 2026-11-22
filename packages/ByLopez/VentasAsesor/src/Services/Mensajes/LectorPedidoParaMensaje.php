<?php

namespace ByLopez\VentasAsesor\Services\Mensajes;

use ByLopez\Logistica\Enums\TipoTransportadora;
use ByLopez\Logistica\Support\PuntosRecogida;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lee, SOLO LECTURA y con lo VIGENTE, lo unico que un mensaje al cliente puede decir de un pedido. Es una lista cerrada de campos:
 * no se reutilizan DTO anchos para no arrastrar por accidente nada interno (costos, utilidad, comision, notas, deuda de
 * transportadora, conciliacion, C6, referencias de 99envios, ids tecnicos). Fuentes:
 *
 *   cliente ......... orders.customer_first_name (telefono: addresses.phone del envio)
 *   lineas .......... order_items (padres) + additional.attributes (color / talla), objetivo = qty_ordered - qty_canceled
 *   envios .......... shipments + shipment_items (lo REAL despachado; nunca las cantidades originales)
 *   guia ............ bylopez_lg_guias VIGENTE por envio (una guia corregida sale corregida) + bylopez_lg_transportadoras
 *   entrega validada  bylopez_lg_validaciones_entrega (estado confirmado)
 *   destino ......... addresses (order_shipping) del propio pedido; recogida: la bodega elegida en la venta + pickup_orders si existe
 *   dinero .......... orders.base_grand_total / Cartera (SaldoPedido) solo si se pide y es inequivoco (ver dinero())
 */
class LectorPedidoParaMensaje
{
    public function __construct(protected CarteraConsultaService $cartera) {}

    /**
     * @return array<string, mixed>|null null = el pedido no existe
     */
    public function leer(int $orderId, bool $conDinero, bool $conAbonos): ?array
    {
        $o = DB::table('orders')->where('id', $orderId)->first(['id', 'increment_id', 'status', 'shipping_method', 'customer_first_name', 'base_grand_total']);

        if (! $o) {
            return null;
        }

        $dir = DB::table('addresses')->where('order_id', $orderId)->where('address_type', 'order_shipping')->first();
        $lineas = $this->lineas($orderId);
        [$envios, $despachadas] = $this->envios($orderId, $lineas);
        $objetivo = array_sum(array_column($lineas, 'objetivo'));
        $esRecogida = $o->shipping_method === PuntosRecogida::METODO;

        return [
            'pedido' => [
                'id' => (int) $o->id,
                'numero' => (string) $o->increment_id,
                'estado' => (string) $o->status,
                'metodo_envio' => (string) $o->shipping_method,
                'es_recogida' => $esRecogida,
                'es_domicilio' => $o->shipping_method === 'domicilio_local',
            ],
            'cliente' => [
                'nombre' => $this->primerNombre((string) ($o->customer_first_name ?: ($dir->first_name ?? ''))),
                'telefono' => $dir && trim((string) $dir->phone) !== '' ? trim((string) $dir->phone) : null,
            ],
            'lineas' => $lineas,
            'objetivo' => $objetivo,
            'despachadas' => $despachadas,
            'envios' => $envios,
            'destino' => $dir ? [
                'empresa' => $dir->company_name ? trim((string) $dir->company_name) : null,
                'direccion' => trim(str_replace(["\r\n", "\n", "\r"], ', ', (string) $dir->address)),
                'ciudad' => trim((string) $dir->city),
                'departamento' => $this->departamento((string) $dir->country, (string) $dir->state),
            ] : null,
            'recogida' => $esRecogida ? $this->horarioDeRecogida($orderId) : null,
            'dinero' => $conDinero ? $this->dinero($o, $conAbonos) : null,
        ];
    }

    /** @return list<array{id: int, producto_id: int, nombre: string, color: ?string, talla: string, objetivo: int}> */
    private function lineas(int $orderId): array
    {
        $codigoColor = (string) config('ventasasesor.nueva_venta.atributos.color', 'color');

        return DB::table('order_items')->where('order_id', $orderId)->whereNull('parent_id')->orderBy('id')
            ->get(['id', 'product_id', 'name', 'qty_ordered', 'qty_canceled', 'additional'])
            ->map(function ($i) use ($codigoColor) {
                $atributos = (array) (json_decode((string) $i->additional, true)['attributes'] ?? []);
                $color = trim((string) ($atributos[$codigoColor]['option_label'] ?? ''));
                $talla = collect($atributos)->except($codigoColor)->map(fn ($a) => trim((string) ($a['option_label'] ?? '')))->filter()->implode(' · ');

                return [
                    'id' => (int) $i->id,
                    'producto_id' => (int) $i->product_id,
                    'nombre' => trim((string) $i->name),
                    'color' => $color !== '' ? $color : null,
                    'talla' => $talla,
                    'objetivo' => max(0, (int) round((float) $i->qty_ordered - (float) $i->qty_canceled)),
                ];
            })->all();
    }

    /**
     * @param  list<array<string, mixed>>  $lineas
     * @return array{0: list<array<string, mixed>>, 1: int} [envios en orden de creacion, unidades despachadas]
     */
    private function envios(int $orderId, array $lineas): array
    {
        $envios = DB::table('shipments')->where('order_id', $orderId)->orderBy('id')->get(['id', 'created_at', 'carrier_code', 'carrier_title', 'track_number']);

        if ($envios->isEmpty()) {
            return [[], 0];
        }

        $ids = $envios->pluck('id')->all();
        $items = DB::table('shipment_items')->whereIn('shipment_id', $ids)->orderBy('id')->get(['shipment_id', 'order_item_id', 'name', 'qty'])->groupBy('shipment_id');
        $guias = DB::table('bylopez_lg_guias as g')->leftJoin('bylopez_lg_transportadoras as t', 't.codigo', '=', 'g.transportadora_codigo')
            ->whereIn('g.shipment_id', $ids)
            ->get(['g.shipment_id', 'g.numero_guia', 'g.origen_guia', 'g.tracking', 't.nombre as transportadora', 't.tipo as tipo'])->keyBy('shipment_id');
        $validados = DB::table('bylopez_lg_validaciones_entrega')->whereIn('shipment_id', $ids)->where('estado', 'confirmado')->pluck('shipment_id')->flip();

        $pendiente = array_column($lineas, 'objetivo', 'id');
        $despachadas = 0;
        $resultado = [];

        foreach ($envios->values() as $k => $e) {
            $unidades = [];

            foreach ($items->get($e->id, collect()) as $si) {
                $clave = $si->order_item_id === null ? 'n:'.trim((string) $si->name) : (int) $si->order_item_id;
                $unidades[$clave] = ($unidades[$clave] ?? ['cantidad' => 0, 'nombre' => trim((string) $si->name)]);
                $unidades[$clave]['cantidad'] += (int) round((float) $si->qty);
                $despachadas += (int) round((float) $si->qty);

                if (is_int($clave) && isset($pendiente[$clave])) {
                    $pendiente[$clave] = max(0, $pendiente[$clave] - (int) round((float) $si->qty));
                }
            }

            $g = $guias->get($e->id);
            $numero = $g->numero_guia ?? ($e->track_number !== null && trim((string) $e->track_number) !== '' ? trim((string) $e->track_number) : null);
            $transportadora = $g->transportadora ?? ($e->carrier_title ?: null);

            $resultado[] = [
                'id' => (int) $e->id,
                'orden' => $k + 1,
                'fecha' => $e->created_at,
                'unidades' => $unidades,
                'pendientes_despues' => array_filter($pendiente),
                'pendientes_total_despues' => array_sum($pendiente),
                'guia' => $numero === null && $transportadora === null ? null : [
                    'transportadora' => $transportadora,
                    'tipo' => $g?->tipo,
                    'numero' => $numero,
                    'interna' => $g ? $g->origen_guia === 'interna' : ($numero !== null && str_starts_with($numero, 'GI-')),
                    'tracking' => $g && $g->tracking !== null && preg_match('#^https?://\S+$#i', trim((string) $g->tracking)) ? trim((string) $g->tracking) : null,
                ],
                'es_recogida' => (($g?->tipo) === TipoTransportadora::RECOGIDA->value) || $e->carrier_code === 'RECOGIDA_BODEGA',
                'validado' => $validados->has($e->id),
            ];
        }

        return [$resultado, $despachadas];
    }

    /** Fecha y horario SOLO si existen en pickup_orders (extension de recogida); nada se inventa. */
    private function horarioDeRecogida(int $orderId): ?array
    {
        if (! Schema::hasTable('pickup_orders')) {
            return null;
        }

        $p = DB::table('pickup_orders')->where('order_id', $orderId)->orderByDesc('id')->first(['pickup_date', 'start_time', 'end_time']);

        return $p && $p->pickup_date ? [
            'fecha' => (string) $p->pickup_date,
            'desde' => $p->start_time ? substr((string) $p->start_time, 0, 5) : null,
            'hasta' => $p->end_time ? substr((string) $p->end_time, 0, 5) : null,
        ] : null;
    }

    /**
     * DINERO. Siempre el total del pedido; abonos y saldo SOLO si Cartera es la fuente y es inequivoca (pedido dentro de Cartera,
     * sin motivos de revision y con algun abono aplicado). Nunca el recaudo de una guia ni el flete: no son lo que el cliente debe.
     *
     * @return array{total: float, abonos: ?float, saldo: ?float}
     */
    private function dinero(object $o, bool $conAbonos): array
    {
        $s = $this->cartera->saldosDePedidos([(int) $o->id])[(int) $o->id] ?? null;
        $limpio = $s !== null && $s->enCartera && ! $s->requiereRevision;
        $total = $limpio ? (float) ($s->totalOperativo ?? $s->total) : (float) $o->base_grand_total;

        $conAbonoClaro = $conAbonos && $limpio && (float) ($s->aplicadoTotal ?? 0) > 0;

        return [
            'total' => round($total, 2),
            'abonos' => $conAbonoClaro ? round((float) $s->aplicadoTotal, 2) : null,
            'saldo' => $conAbonoClaro ? round((float) ($s->saldo ?? 0), 2) : null,
        ];
    }

    private function departamento(string $pais, string $estado): ?string
    {
        if ($pais === '' || $estado === '') {
            return null;
        }

        $nombre = DB::table('country_states')->where('country_code', $pais)->where('code', $estado)->value('default_name');

        return $nombre !== null ? trim((string) $nombre) : null;
    }

    private function primerNombre(string $nombre): string
    {
        $primero = preg_split('/\s+/u', trim($nombre))[0] ?? '';

        return $primero === '' ? '' : mb_convert_case(mb_strtolower($primero), MB_CASE_TITLE);
    }
}
