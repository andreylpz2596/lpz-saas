<?php

use ByLopez\Logistica\Services\Despachos\DisposicionCobroService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/foto.php';
require_once __DIR__.'/util.php';
require_once __DIR__.'/a.php';

/*
| RECORRIDO J — F6 DESPUES DE UNA DEVOLUCION (transferencia sin pagos, decision «cobrar al entregar»).
| J1: 10 unidades en una guia (recaudo = saldo) → se entregan 8 → el transportador devuelve 2 (REGISTRADO: sin efecto) → Bodega las RECIBE (C5:
|     saldo 100.000 → 80.000) → la decision anterior queda INVALIDADA y el importe anterior (100.000) ya no se puede cobrar; el nuevo saldo si.
| J2: despacho PARCIAL (6 de 10) → devolucion de 2 recibida → las 4 pendientes NO salen con la decision anterior: hay que decidir de nuevo, y la
|     guia nueva lleva el valor de SUS unidades. La pantalla del despacho (drawer) muestra la decision anterior y pide decidir otra vez.
*/
function recorrido_j(Demo $d): void
{
    $cliente = (int) DB::table('customers')->where('first_name', 'Bruno')->where('last_name', 'Demo Transferencia')->value('id');
    $dir = direccion_de($d, $cliente);
    $zona = ['zona_id' => 1, 'transportadora' => 'BRIAN', 'flete_pagador' => 'bylopez', 'motivo_pagador' => 'DEMO: ByLopez asume el flete', 'valor_flete' => 12000];
    $situacion = fn (int $o) => app(DisposicionCobroService::class)->situacion($o);

    // ---------------- J1
    $v = $d->venta($cliente, [[215, [217 => 10]]], ['envio' => ['tipo' => 'guardada', 'address_id' => $dir], 'metodo' => 'flatrate_flatrate'], 'moneytransfer', 9);
    $o = $v['orderId'];
    esperar_igual('J1 · total del pedido (10 × 10.000)', 100000.0, $v['total']);
    $d->rechazo('J1 · sin decision de cobro el despacho se bloquea (F6)', $d->s['bodega']->post("/admin/logistica/despachos/pedidos/{$o}/despachar", $zona + ['idempotency_key' => Demo::clave()]), 'disposicion_cobro_requerida');
    $dec = $d->paso('J1 · Bodega decide «cobrar al entregar»', $d->s['bodega']->post("/admin/logistica/despachos/pedidos/{$o}/disposicion-cobro", ['tipo' => 'cobrar_al_entregar']));
    esperar_igual('J1 · decision vigente con 100.000 por confirmar', [true, 100000.0], [(bool) $situacion($o)['resuelta'], $situacion($o)['por_confirmar']]);
    $envio = (int) despachar($d, $o, $zona, 'J1 · Bodega despacha las 10 (la guia lleva el saldo como recaudo)')['envio_id'];
    $recaudo = (float) DB::table('bylopez_lg_guias')->where('shipment_id', $envio)->value('recaudo_esperado');
    echo "  Recaudo esperado de la guia: {$recaudo}\n";
    $f0 = foto($o);
    mostrar_foto('J1-1 · despachado, decision vigente', $f0);

    registrar_entrega($d, $envio, pendientes_envio(ctx_envio($d, 'bodega', $envio), 8));
    $d->paso('J1 · El transportador devuelve 2 (REGISTRADO, aun sin recepcion fisica)', $d->s['bodega']->post("/admin/logistica/entregas/envios/{$envio}/drawer/devolver", [
        'fecha' => now()->toDateString(), 'items' => pendientes_envio(ctx_envio($d, 'bodega', $envio)), 'motivo_codigo' => 'rechazado', 'idempotency_key' => Demo::clave(),
    ]));
    $f1 = foto($o);
    esperar_igual('J1 · devolucion REGISTRADA: sin efecto financiero (exigible, saldo y decision intactos)', [$f0['obligacion']['exigible'], $f0['obligacion']['saldo'], true], [$f1['obligacion']['exigible'], $f1['obligacion']['saldo'], (bool) $situacion($o)['resuelta']]);

    $retorno = ctx_envio($d, 'bodega', $envio)['devoluciones'][0];
    $itemRetorno = (int) ($retorno['lineas'][0]['retorno_item_id'] ?? $retorno['items'][0]['retorno_item_id'] ?? 0);
    $d->paso('J1 · Bodega RECIBE las 2 (aptas)', $d->s['bodega']->post("/admin/logistica/entregas/devoluciones/{$retorno['id']}/recibir", [
        'fecha' => now()->toDateString(), 'idempotency_key' => Demo::clave(),
        'lineas' => [$itemRetorno => ['recibidas' => 2, 'aptas' => 2, 'novedad' => 0]],
    ]));
    $f2 = foto($o);
    mostrar_foto('J1-2 · devolucion RECIBIDA (C5)', $f2);
    $s2 = $situacion($o);
    esperar_igual('J1 · C5: devuelto 20.000 → exigible 80.000 (saldo 80.000)', [20000.0, 80000.0, 80000.0], [$f2['obligacion']['devuelto'], $f2['obligacion']['exigible'], $f2['obligacion']['saldo']]);
    esperar_igual('J1 · la decision de 100.000 ya NO vale: queda como «anterior» y hay que decidir de nuevo', [false, null, 100000.0], [(bool) $s2['resuelta'], $s2['vigente'], (float) ($s2['anterior']['saldo_referencia'] ?? -1)]);

    $cobro = fn (string $monto, string $ref) => $d->s['admin']->post("/admin/logistica/entregas/envios/{$envio}/cobrar", [
        'monto' => $monto, 'medio_cobro' => 'efectivo', 'referencia' => $ref, 'fecha_pago' => now()->toDateString(), 'idempotency_key' => Demo::clave(),
    ]);
    $d->rechazo('J1 · cobrar el importe ANTERIOR (100.000) es rechazado por Cartera', $cobro('100000', 'DEMO-J1-ANTERIOR'), 'cobro_rechazado');
    esperar_igual('J1 · el rechazo no dejo ningun pago', 0, DB::table('bylopez_va_aplicaciones_pago')->where('order_id', $o)->count());
    $d->paso('J1 · se cobra el saldo vigente (80.000 = las 8 entregadas)', $cobro('80000', 'DEMO-J1-VIGENTE'));
    $f3 = foto($o);
    mostrar_foto('J1-3 · cobrado el saldo vigente', $f3);
    esperar_igual('J1 · saldo 0 y una sola decision en el historial (nada se reescribio)', [0.0, 1], [$f3['obligacion']['saldo'], DB::table('bylopez_lg_disposiciones_cobro')->where('order_id', $o)->count()]);

    // ---------------- J2 (parcial)
    $v2 = $d->venta($cliente, [[215, [219 => 10]]], ['envio' => ['tipo' => 'guardada', 'address_id' => $dir], 'metodo' => 'flatrate_flatrate'], 'moneytransfer', 9);
    $o2 = $v2['orderId'];
    $d->paso('J2 · Bodega decide «cobrar al entregar»', $d->s['bodega']->post("/admin/logistica/despachos/pedidos/{$o2}/disposicion-cobro", ['tipo' => 'cobrar_al_entregar']));
    $ctx = $d->paso('J2 · Bodega abre el despacho', $d->s['bodega']->get("/admin/logistica/despachos/pedidos/{$o2}/contexto"))['contexto'];
    $linea = $ctx['unidades']['lineas'][0]['id'];
    $e2 = (int) despachar($d, $o2, $zona + ['unidades' => [$linea => 6]], 'J2 · Bodega despacha 6 de las 10')['envio_id'];
    $d->paso('J2 · El transportador devuelve 2', $d->s['bodega']->post("/admin/logistica/entregas/envios/{$e2}/drawer/devolver", [
        'fecha' => now()->toDateString(), 'items' => pendientes_envio(ctx_envio($d, 'bodega', $e2), 2), 'motivo_codigo' => 'rechazado', 'idempotency_key' => Demo::clave(),
    ]));
    $retorno2 = ctx_envio($d, 'bodega', $e2)['devoluciones'][0];
    $itemRetorno2 = (int) ($retorno2['lineas'][0]['retorno_item_id'] ?? $retorno2['items'][0]['retorno_item_id'] ?? 0);
    $d->paso('J2 · Bodega recibe las 2 (aptas)', $d->s['bodega']->post("/admin/logistica/entregas/devoluciones/{$retorno2['id']}/recibir", [
        'fecha' => now()->toDateString(), 'idempotency_key' => Demo::clave(), 'lineas' => [$itemRetorno2 => ['recibidas' => 2, 'aptas' => 2, 'novedad' => 0]],
    ]));
    $d->rechazo('J2 · las 4 pendientes NO salen con la decision anterior', $d->s['bodega']->post("/admin/logistica/despachos/pedidos/{$o2}/despachar", $zona + ['unidades' => [$linea => 4], 'idempotency_key' => Demo::clave()]), 'disposicion_cobro_requerida');
    $ctx2 = $d->paso('J2 · el panel pide decidir otra vez y muestra la decision anterior', $d->s['bodega']->get("/admin/logistica/despachos/pedidos/{$o2}/contexto"))['contexto'];
    esperar_igual('J2 · contexto del drawer: sin decision vigente, anterior de 100.000', [false, 100000.0], [(bool) $ctx2['disposicion_cobro']['resuelta'], (float) ($ctx2['disposicion_cobro']['anterior']['saldo_referencia'] ?? -1)]);
    $d->paso('J2 · Bodega decide de nuevo (saldo vigente 80.000)', $d->s['bodega']->post("/admin/logistica/despachos/pedidos/{$o2}/disposicion-cobro", ['tipo' => 'cobrar_al_entregar']));
    $e3 = (int) despachar($d, $o2, $zona + ['unidades' => [$linea => 4]], 'J2 · ahora si salen las 4')['envio_id'];
    $g2 = (float) DB::table('bylopez_lg_guias')->where('shipment_id', $e3)->value('recaudo_esperado');
    esperar_igual('J2 · la guia nueva lleva el valor de SUS 4 unidades (40.000), no el saldo anterior', 40000.0, $g2);
    mostrar_foto('J2 · final', foto($o2));
    guardar_evidencia('J', ['pedido_j1' => $o, 'pedido_j2' => $o2, 'j1_recibido' => $f2, 'j1_final' => $f3]);
}
