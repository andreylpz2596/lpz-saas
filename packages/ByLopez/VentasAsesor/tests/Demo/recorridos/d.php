<?php

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/foto.php';
require_once __DIR__.'/util.php';
require_once __DIR__.'/a.php';
require_once __DIR__.'/c.php';

/*
| RECORRIDO D — NACIONAL / CONTRAENTREGA.
| D1: transportadora SIN cuenta corriente (Servientrega). Se cobra al cliente, pero NO existe un registro que pruebe que el dinero llego a
|     ByLopez: la comision queda bloqueada de forma EXPLICITA («recaudo_sin_cuenta_corriente») y se registra el requisito faltante.
| D2: transportadora CON cuenta corriente (Inter Rapidisimo, capacidad activada por el administrador): guia → flete a cargo de ByLopez →
|     entrega → cobro reportado → dinero en custodia → liquidacion (el flete se compensa) → recaudo conciliado → comision habilitada.
*/
function recorrido_d(Demo $d): void
{
    $cliente = (int) DB::table('customers')->where('first_name', 'Ana')->value('id');
    $nacional = fn () => ['envio' => direccion_medellin('Ana', 'Demo Contraentrega', '3009990101'), 'metodo' => 'flatrate_flatrate'];

    // ---- D1: sin cuenta corriente
    $o1 = $d->venta($cliente, [[215, [216 => 2]]], $nacional(), 'cashondelivery')['orderId'];
    $forma1 = ['transportadora' => '03', 'numero_guia' => 'DEMO-SV-'.random_int(100000, 999999), 'origen_guia' => 'manual', 'flete_pagador' => 'cliente_al_recibir', 'valor_flete' => 16000];
    $e1 = (int) despachar($d, $o1, $forma1, 'D1 · Bodega despacha por Servientrega (sin cuenta corriente), contra entrega')['envio_id'];
    esperar_igual('D1 · recaudo esperado de la guia (C1)', 20000.0, (float) DB::table('bylopez_lg_guias')->where('shipment_id', $e1)->value('recaudo_esperado'));
    registrar_entrega($d, $e1);
    $d->paso('D1 · Se registra el cobro del transportador (20.000)', $d->s['admin']->post("/admin/logistica/entregas/envios/{$e1}/cobrar", [
        'monto' => '20000', 'medio_cobro' => 'contraentrega_recaudo', 'referencia' => 'DEMO-SV-COBRO', 'fecha_pago' => now()->toDateString(), 'idempotency_key' => Demo::clave(),
    ]));
    validar_entrega($d, $e1);
    $c = mostrar_comision('D1 · cobrado al cliente, SIN prueba de ingreso a ByLopez', $o1);
    esperar_igual('D1 · bloqueo EXPLICITO: sin cuenta corriente no hay registro que pruebe que el dinero llego', true, count(array_intersect($c['condiciones_pendientes'], ['recaudo_sin_cuenta_corriente', 'cobro_en_custodia_sin_recaudo'])) > 0);
    mostrar_foto('D1 · nacional contra entrega sin cuenta corriente', foto($o1));

    // ---- D2: con cuenta corriente
    $inter = (int) DB::table('bylopez_lg_transportadoras')->where('codigo', 'INTER_RAPIDISIMO')->value('id');

    if (! DB::table('bylopez_lg_transportadoras')->where('id', $inter)->value('liquida_cuenta_corriente')) {
        $d->form('D2 · El administrador activa «liquida cuenta corriente» en Inter Rapidisimo (configuracion de la demo)', 'admin', "/admin/logistica/transportadoras/{$inter}/capacidades", ['liquida_cuenta_corriente' => 1, 'genera_guia_interna' => 0], '/admin/logistica/transportadoras', [], fn () => (bool) DB::table('bylopez_lg_transportadoras')->where('id', $inter)->value('liquida_cuenta_corriente'));
    }

    $o2 = $d->venta($cliente, [[215, [216 => 2]], [425, [426 => 1]]], $nacional(), 'cashondelivery')['orderId'];
    $forma2 = ['transportadora' => 'INTER_RAPIDISIMO', 'numero_guia' => 'DEMO-IR-'.random_int(100000, 999999), 'origen_guia' => 'manual', 'flete_pagador' => 'bylopez', 'motivo_pagador' => 'DEMO: ByLopez asume el flete', 'valor_flete' => 15000];
    $e2 = (int) despachar($d, $o2, $forma2, 'D2 · Bodega despacha por Inter Rapidisimo (cuenta corriente), flete 15.000 a cargo de ByLopez')['envio_id'];
    $guia = DB::table('bylopez_lg_guias')->where('shipment_id', $e2)->first(['id', 'recaudo_esperado', 'liquida_cuenta_corriente']);
    esperar_igual('D2 · guia de cuenta corriente con recaudo esperado 30.000', [true, 30000.0], [(bool) $guia->liquida_cuenta_corriente, (float) $guia->recaudo_esperado]);
    registrar_entrega($d, $e2);
    $d->paso('D2 · El transportador reporta el cobro de 30.000 (cobro reportado)', $d->s['admin']->post("/admin/logistica/cuentas/{$inter}/cobro-reportado", [
        'guia_id' => $guia->id, 'valor' => '30000', 'fecha' => now()->toDateString(), 'referencia' => 'DEMO-IR-COBRO', 'idempotency_key' => Demo::clave(),
    ]));
    validar_entrega($d, $e2);
    $f = foto($o2);
    mostrar_foto('D2-1 · cobrado por Inter, aun en custodia del transportador', $f);
    esperar_igual('D2 · cobrado 30.000, conciliado 0, en custodia 30.000', [30000.0, 0.0, 30000.0], [$f['custodia']['cobrado_por_transportador'], $f['custodia']['conciliado_con_bylopez'], $f['custodia']['en_custodia']]);
    $c = mostrar_comision('D2 · en custodia', $o2);
    esperar_igual('D2 · comision bloqueada por recaudo en custodia', true, in_array('recaudo_en_custodia', $c['condiciones_pendientes'], true));

    $cuenta = fn () => $d->paso('Cuenta de Inter', $d->s['admin']->get("/admin/logistica/cuentas/{$inter}/contexto"))['contexto'];
    $c1 = $cuenta();
    echo '  Cuenta Inter: '.$c1['saldo']['texto'].' · resumen '.json_encode($c1['resumen'])."\n";
    $ids = $c1['liquidable']['ids'];
    $p = $d->paso('D2 · Vista previa: recaudo 30.000 − flete 15.000 = 15.000 en efectivo', $d->s['admin']->post("/admin/logistica/cuentas/{$inter}/propuesta", ['valor' => 15000, 'movimientos' => $ids]));
    echo '  propuesta: '.json_encode($p['propuesta']['totales'] ?? $p['propuesta'], JSON_UNESCAPED_UNICODE)."\n";
    $d->paso('D2 · Liquidacion: Inter entrega 15.000 en efectivo y el flete se compensa', $d->s['admin']->post("/admin/logistica/cuentas/{$inter}/liquidar", [
        'movimientos' => $ids, 'valor' => 15000, 'observacion' => 'DEMO: recaudo menos flete', 'referencia' => 'DEMO-LIQ-IR', 'idempotency_key' => Demo::clave(),
    ]));
    $f = foto($o2);
    mostrar_foto('D2-2 · liquidado y conciliado', $f);
    esperar_igual('D2 · conciliado 30.000 (15.000 efectivo + 15.000 compensado)', 30000.0, $f['custodia']['conciliado_con_bylopez']);
    $c = mostrar_comision('D2 · recaudo conciliado', $o2);
    esperar_igual('D2 · sin pendientes de dinero', [], array_values(array_intersect($c['condiciones_pendientes'], ['recaudo_en_custodia', 'recaudo_pendiente_de_conciliar', 'recaudo_sin_cuenta_corriente'])));
    guardar_evidencia('D', ['d1' => $o1, 'd2' => $o2]);
}
