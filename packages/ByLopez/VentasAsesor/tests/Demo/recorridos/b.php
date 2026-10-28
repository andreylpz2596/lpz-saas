<?php

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/foto.php';
require_once __DIR__.'/util.php';
require_once __DIR__.'/a.php';

/*
| RECORRIDO B — LOCAL / CONTRAENTREGA PARCIAL.
| Pedido (2 lineas) → DOS guias con el domiciliario Brian (cuenta corriente) → recaudo repartido por la regla C1 → entregas → cobro
| (el recaudo nace del cobro, C6) → dinero en custodia → liquidacion con DIFERENCIA → conciliacion de la diferencia → comision.
*/
function recorrido_b(Demo $d): void
{
    // `recorridos.php B <pedido>` retoma en el cobro de un pedido ya despachado y entregado en dos guias.
    $o = (int) ($GLOBALS['argv'][2] ?? 0) ?: b_venta_y_entregas($d);
    if (($GLOBALS['argv'][3] ?? '') !== 'liq') {
        b_cobro_y_cuenta($d, $o);
    }

    b_liquidacion($d, $o, 27000, 1000);
}

function b_venta_y_entregas(Demo $d): int
{
    $cliente = (int) DB::table('customers')->where('first_name', 'Ana')->value('id');
    $dir = direccion_de($d, $cliente);
    $v = $d->venta($cliente, [[215, [217 => 2]], [425, [426 => 2]]], ['envio' => ['tipo' => 'guardada', 'address_id' => $dir], 'metodo' => 'flatrate_flatrate'], 'cashondelivery', 9);
    $o = $v['orderId'];
    esperar_igual('Total del pedido (4 × 10.000)', 40000.0, $v['total']);
    mostrar_foto('B1 · venta confirmada contra entrega', foto($o));

    $ctx = $d->paso('Bodega abre el despacho', $d->s['bodega']->get("/admin/logistica/despachos/pedidos/{$o}/contexto"))['contexto'];
    $lineas = array_column($ctx['unidades']['lineas'], 'id', 'producto_id');
    esperar_igual('Contra entrega no pide decision de cobro (F6)', false, $ctx['disposicion_cobro']['requiere']);
    echo '  recaudo_guia (contexto): '.json_encode($ctx['recaudo_guia'])."\n";

    $base = ['zona_id' => 1, 'transportadora' => 'BRIAN', 'flete_pagador' => 'cliente_al_recibir', 'valor_flete' => 12000];
    // Guia 1: ByLopez asume el flete (12.000) → servicio por pagar al domiciliario que se COMPENSA contra el recaudo. Guia 2: lo paga el cliente.
    $g1 = despachar($d, $o, ['flete_pagador' => 'bylopez', 'motivo_pagador' => 'DEMO: ByLopez asume el flete de la guia 1'] + $base + ['unidades' => [$lineas[215] => 2]], 'Bodega despacha la GUIA 1 (caballero × 2, flete a cargo de ByLopez)');
    $g2 = despachar($d, $o, $base + ['unidades' => [$lineas[425] => 2]], 'Bodega despacha la GUIA 2 (camisetas dama × 2)');
    $guias = DB::table('bylopez_lg_guias')->where('order_id', $o)->orderBy('id')->get(['id', 'numero_guia', 'recaudo_esperado', 'valor_flete', 'shipment_id']);
    esperar_igual('Recaudo guia 1 (C1: 2 × 10.000)', 20000.0, (float) $guias[0]->recaudo_esperado);
    esperar_igual('Recaudo guia 2 (C1: 2 × 10.000)', 20000.0, (float) $guias[1]->recaudo_esperado);
    esperar_igual('Suma de recaudos = exigible del pedido (nunca la supera)', 40000.0, (float) $guias->sum('recaudo_esperado'));
    mostrar_foto('B2 · dos guias despachadas', foto($o));

    $e1 = (int) $g1['envio_id'];
    $e2 = (int) $g2['envio_id'];
    registrar_entrega($d, $e1);
    registrar_entrega($d, $e2);

    return $o;
}

function b_cobro_y_cuenta(Demo $d, int $o): void
{
    $envios = envios_de($o);
    $brian = (int) DB::table('bylopez_lg_transportadoras')->where('codigo', 'BRIAN')->value('id');

    foreach ($envios as $i => $envio) {
        $d->paso('Cobro contra entrega de la guia '.($i + 1).' (20.000, recaudo del domiciliario)', $d->s['admin']->post("/admin/logistica/entregas/envios/{$envio}/cobrar", [
            'monto' => '20000', 'medio_cobro' => 'contraentrega_recaudo', 'referencia' => 'DEMO-COBRO-'.($i + 1), 'fecha_pago' => now()->toDateString(), 'idempotency_key' => Demo::clave(),
        ]));
    }

    $f = foto($o);
    mostrar_foto('B3 · cobrado por el domiciliario (en custodia, aun no conciliado con ByLopez)', $f);
    esperar_igual('Cobrado por el transportador', 40000.0, $f['custodia']['cobrado_por_transportador']);
    esperar_igual('Conciliado con ByLopez (todavia nada)', 0.0, $f['custodia']['conciliado_con_bylopez']);
    esperar_igual('Cartera: saldo 0 (el cliente pago) — pero el dinero NO ha llegado a ByLopez', 0.0, $f['obligacion']['saldo']);

    foreach ($envios as $envio) {
        validar_entrega($d, $envio);
    }

    $c = mostrar_comision('entregas validadas, cobro confirmado, dinero en custodia del domiciliario', $o);
    esperar_igual('Comision NO habilitada: el recaudo sigue en custodia', true, in_array('recaudo_en_custodia', $c['condiciones_pendientes'], true));

    $ctx = $d->paso('Administrador abre la cuenta de Brian', $d->s['admin']->get("/admin/logistica/cuentas/{$brian}/contexto"))['contexto'];
    file_put_contents(sys_get_temp_dir().'/ctx_cuenta.json', json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo '  claves del contexto de cuenta: '.implode(',', array_keys($ctx))."\n";
}

function b_liquidacion(Demo $d, int $o, int $efectivo1, int $efectivo2): void
{
    $brian = (int) DB::table('bylopez_lg_transportadoras')->where('codigo', 'BRIAN')->value('id');
    $cuenta = fn () => $d->paso('Cuenta de Brian', $d->s['admin']->get("/admin/logistica/cuentas/{$brian}/contexto"))['contexto'];
    $c = $cuenta();
    $ids = $c['liquidable']['ids'];
    echo '  Saldo antes de liquidar: '.$c['saldo']['texto'].' · movimientos abiertos '.json_encode($ids)."\n";

    $p = $d->paso("Vista previa de la conciliacion con entrega de {$efectivo1}", $d->s['admin']->post("/admin/logistica/cuentas/{$brian}/propuesta", ['valor' => $efectivo1]));
    echo '  propuesta: '.json_encode($p['propuesta'], JSON_UNESCAPED_UNICODE)."\n";

    $d->paso("Administrador liquida: Brian entrega {$efectivo1} (hay diferencia)", $d->s['admin']->post("/admin/logistica/cuentas/{$brian}/liquidar", [
        'movimientos' => $ids, 'valor' => $efectivo1, 'observacion' => 'DEMO: entrega incompleta, la diferencia queda abierta', 'referencia' => 'DEMO-LIQ-1', 'idempotency_key' => Demo::clave(),
    ]));
    $f = foto($o);
    mostrar_foto('B4 · liquidacion con diferencia', $f);
    $c2 = mostrar_comision('liquidacion con diferencia SIN conciliar', $o);
    esperar_igual('Con diferencia abierta la comision no se habilita', true, count($c2['condiciones_pendientes']) > 0);

    $c = $cuenta();
    echo '  Saldo tras la 1a liquidacion: '.$c['saldo']['texto'].' · liquidable '.json_encode($c['liquidable']['ids'])."\n";
    $d->paso("Administrador liquida la diferencia: Brian entrega {$efectivo2}", $d->s['admin']->post("/admin/logistica/cuentas/{$brian}/liquidar", [
        'movimientos' => $c['liquidable']['ids'], 'valor' => $efectivo2, 'observacion' => 'DEMO: Brian entrega la diferencia', 'referencia' => 'DEMO-LIQ-2', 'idempotency_key' => Demo::clave(),
    ]));
    mostrar_foto('B5 · diferencia resuelta en otra liquidacion', foto($o));
    mostrar_comision('conciliado todo el recaudo', $o);
}
