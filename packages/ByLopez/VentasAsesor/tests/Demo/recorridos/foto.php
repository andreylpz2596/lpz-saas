<?php

use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use Illuminate\Support\Facades\DB;

/*
| Conciliacion de un pedido, LEIDA de la base de la demo (nunca escrita): unidades, inventario fisico, reservas, inventario no vendible,
| obligacion del cliente, pagos, custodia del transportador, ingreso a ByLopez y comision. Es la evidencia D de la entrega.
*/

/** @return array<string, mixed> */
function foto(int $orderId): array
{
    $lineas = DB::table('order_items')->where('order_id', $orderId)->whereNull('parent_id')->get();
    $variantes = [];

    foreach ($lineas as $l) {
        $hijas = DB::table('order_items')->where('parent_id', $l->id)->pluck('product_id')->all();
        $variantes[$l->id] = $hijas ?: [$l->product_id];
    }

    $ids = array_merge(...array_values($variantes ?: [[]]));
    $canal = (int) DB::table('orders')->where('id', $orderId)->value('channel_id');

    $entregadas = DB::table('bylopez_lg_entregas as e')->join('bylopez_lg_entrega_items as i', 'i.entrega_id', '=', 'e.id')->where('e.order_id', $orderId)->where('e.estado', 'confirmado')->sum('i.cantidad');
    $devueltas = DB::table('bylopez_lg_retornos as r')->join('bylopez_lg_retorno_items as i', 'i.retorno_id', '=', 'r.id')->where('r.order_id', $orderId)->where('r.estado', 'confirmado')->sum('i.cantidad');
    $recibidas = DB::table('bylopez_lg_recepciones_devolucion as r')->join('bylopez_lg_recepcion_devolucion_items as i', 'i.recepcion_id', '=', 'r.id')->where('r.order_id', $orderId)->where('r.estado', 'confirmado')
        ->selectRaw('COALESCE(SUM(i.recibidas),0) recibidas, COALESCE(SUM(i.aptas),0) aptas')->first();

    $s = app(CarteraConsultaService::class)->saldosDePedidos([$orderId])[$orderId] ?? null;

    $guias = DB::table('bylopez_lg_guias')->where('order_id', $orderId)->get(['id', 'numero_guia', 'transportadora_codigo', 'recaudo_esperado']);
    $movs = DB::table('bylopez_lg_cuenta_movimientos')->where('order_id', $orderId)->get(['id', 'concepto', 'importe', 'estado', 'origen_tipo', 'liquidacion_id']);
    $recaudoCobrado = round((float) $movs->where('concepto', 'recaudo')->where('origen_tipo', 'cobro')->sum('importe'), 2);
    $movCobro = $movs->where('concepto', 'recaudo')->where('origen_tipo', 'cobro')->pluck('id')->all();
    $conciliado = $movCobro === [] ? 0.0 : round((float) DB::table('bylopez_lg_conciliaciones_movimiento')->whereIn('movimiento_id', $movCobro)->sum(DB::raw('efectivo + compensado')), 2);

    $pagos = DB::table('bylopez_va_aplicaciones_pago as a')->join('bylopez_va_pagos as p', 'p.id', '=', 'a.pago_id')->where('a.order_id', $orderId)->whereNull('a.anulada_en')
        ->get(['p.id', 'p.monto', 'p.medio_cobro', 'p.estado', 'a.monto as aplicado'])
        ->map(fn ($p) => "pago #{$p->id} {$p->medio_cobro} {$p->estado} aplicado ".number_format((float) $p->aplicado, 0, ',', '.'))->all();

    $comisiones = DB::table('bylopez_va_comision_lineas')->where('order_id', $orderId)->get(['id', 'estado', 'cantidad_vendida', 'unidades_comisionables', 'monto', 'liquidacion_id'])
        ->map(fn ($c) => "linea #{$c->id} {$c->estado} uds {$c->cantidad_vendida} comisionables {$c->unidades_comisionables} monto ".number_format((float) $c->monto, 0, ',', '.'))->all();

    return [
        'pedido' => $orderId,
        'incremento' => DB::table('orders')->where('id', $orderId)->value('increment_id'),
        'estado_pedido' => DB::table('orders')->where('id', $orderId)->value('status'),
        'unidades' => [
            'pedidas' => (int) $lineas->sum('qty_ordered'), 'despachadas' => (int) $lineas->sum('qty_shipped'), 'canceladas' => (int) $lineas->sum('qty_canceled'),
            'entregadas' => (int) $entregadas, 'devueltas_transportadora' => (int) $devueltas, 'recibidas_en_bodega' => (int) $recibidas->recibidas, 'aptas' => (int) $recibidas->aptas,
        ],
        'inventario' => [
            'fisico' => $ids === [] ? 0 : (int) DB::table('product_inventories')->whereIn('product_id', $ids)->sum('qty'),
            'reservado' => $ids === [] ? 0 : (int) DB::table('product_ordered_inventories')->whereIn('product_id', $ids)->where('channel_id', $canal)->sum('qty'),
            'no_vendible' => $ids === [] ? 0 : (int) DB::table('bylopez_lg_unidades_defectuosas')->whereIn('product_id', $ids)->where('estado', '!=', 'anulada')->sum('cantidad'),
        ],
        'obligacion' => $s === null ? null : [
            'total' => $s->total, 'cancelado' => $s->cancelado, 'devuelto' => $s->devuelto, 'exigible' => $s->totalOperativo, 'verificado' => $s->verificado,
            'sin_revisar' => $s->sinRevisar, 'saldo' => $s->saldo, 'a_favor' => $s->saldoAFavor,
        ],
        'pagos' => $pagos,
        'guias' => $guias->map(fn ($g) => "{$g->numero_guia} ({$g->transportadora_codigo}) recaudo esperado ".number_format((float) $g->recaudo_esperado, 0, ',', '.'))->all(),
        'custodia' => ['cobrado_por_transportador' => $recaudoCobrado, 'conciliado_con_bylopez' => $conciliado, 'en_custodia' => round($recaudoCobrado - $conciliado, 2)],
        'comision' => $comisiones,
    ];
}

function mostrar_foto(string $titulo, array $f): void
{
    echo "\n  --- Conciliacion: {$titulo} ---\n";

    foreach ($f as $k => $v) {
        echo '  '.str_pad($k, 12).' '.(is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $v)."\n";
    }
}

function guardar_evidencia(string $recorrido, array $datos): void
{
    $dir = dirname(__DIR__, 6).'/storage/logs/demo';
    @mkdir($dir, 0775, true);
    file_put_contents("{$dir}/RECORRIDO-{$recorrido}-".date('Ymd-His').'-'.substr(bin2hex(random_bytes(3)), 0, 6).'.json', json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
