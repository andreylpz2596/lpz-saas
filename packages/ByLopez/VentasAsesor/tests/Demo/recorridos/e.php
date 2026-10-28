<?php

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/foto.php';
require_once __DIR__.'/util.php';
require_once __DIR__.'/a.php';

/*
| RECORRIDO E — DEVOLUCION.
| E1: pedido de 4 camisetas pagado por transferencia → entrega 1 → la transportadora devuelve 3 (el cliente las rechazo) → Bodega las recibe:
|     2 APTAS + 1 DEFECTUOSA (mancha, origen transporte) → las aptas vuelven al vendible, la defectuosa queda en el circuito NO vendible →
|     C5 descuenta lo recibido y deja saldo a favor identificado (sin simular reembolso) → la comision queda en revision.
| E2: devolucion con FALTANTE (se esperaban 2, llega 1): el faltante no ingresa ni se descuenta.
| El MOTIVO COMERCIAL de la devolucion (catalogo «devolucion») es independiente del TIPO DE DEFECTO (catalogo «motivo»).
*/
function recorrido_e(Demo $d): void
{
    $cliente = (int) DB::table('customers')->where('first_name', 'Carla')->value('id');
    $dir = direccion_de($d, $cliente);
    $zona = ['zona_id' => 1, 'transportadora' => 'BRIAN', 'flete_pagador' => 'cliente_al_recibir', 'valor_flete' => 12000];

    // ---- E1
    $v = $d->venta($cliente, [[215, [216 => 4]]], ['envio' => ['tipo' => 'guardada', 'address_id' => $dir], 'metodo' => 'flatrate_flatrate'], 'moneytransfer', 9);
    $o = $v['orderId'];
    $pago = reportar_pago($d, $o, '40000', 'DEMO-CARLA-0001');
    confirmar_pago($d, $pago);
    $g = despachar($d, $o, $zona);
    $envio = (int) $g['envio_id'];
    $f0 = foto($o);
    mostrar_foto('E1-1 · pagado (40.000) y despachado (4)', $f0);

    registrar_entrega($d, $envio, pendientes_envio(ctx_envio($d, 'bodega', $envio), 1));
    $d->paso('La transportadora devuelve 3 (motivo comercial: el cliente rechazo el pedido)', $d->s['bodega']->post("/admin/logistica/entregas/envios/{$envio}/drawer/devolver", [
        'fecha' => now()->toDateString(), 'items' => pendientes_envio(ctx_envio($d, 'bodega', $envio)), 'motivo_codigo' => 'rechazado', 'idempotency_key' => Demo::clave(),
    ]));
    $f1 = foto($o);
    esperar_igual('Registrar la devolucion no toca inventario ni obligacion (hasta que Bodega recibe)', [$f0['inventario']['fisico'], $f0['obligacion']['exigible']], [$f1['inventario']['fisico'], $f1['obligacion']['exigible']]);

    $retorno = ctx_envio($d, 'bodega', $envio)['devoluciones'][0];
    $itemRetorno = (int) ($retorno['lineas'][0]['retorno_item_id'] ?? $retorno['items'][0]['retorno_item_id'] ?? 0);
    $d->paso('Bodega recibe 3: 2 aptas + 1 defectuosa (mancha, origen transporte)', $d->s['bodega']->post("/admin/logistica/entregas/devoluciones/{$retorno['id']}/recibir", [
        'fecha' => now()->toDateString(), 'idempotency_key' => Demo::clave(),
        'lineas' => [$itemRetorno => ['recibidas' => 3, 'aptas' => 2, 'novedad' => 1, 'defectos' => [['cantidad' => 1, 'motivo' => 'mancha', 'origen' => 'transporte', 'detalle' => 'DEMO: mancha de grasa', 'observacion' => null]]]],
    ]));
    validar_entrega($d, $envio);
    $f2 = foto($o);
    mostrar_foto('E1-2 · recibido: 2 aptas + 1 defectuosa', $f2);
    esperar_igual('Inventario fisico: +2 aptas (la defectuosa NO entra al vendible)', $f0['inventario']['fisico'] + 2, $f2['inventario']['fisico']);
    esperar_igual('Inventario NO vendible: 1 defectuosa con motivo y trazabilidad', 1, $f2['inventario']['no_vendible']);
    esperar_igual('C5: devuelto 30.000 (3 × 10.000) → exigible 10.000; pagado 40.000 → saldo a favor 30.000 (sin reembolso)', [30000.0, 10000.0, 30000.0], [$f2['obligacion']['devuelto'], $f2['obligacion']['exigible'], $f2['obligacion']['a_favor']]);
    esperar_igual('Sin Refund en Bagisto (no se simula devolucion de dinero)', 0, DB::table('refunds')->where('order_id', $o)->count());
    $def = DB::table('bylopez_lg_unidades_defectuosas')->where('order_id', $o)->first(['motivo_codigo', 'origen_codigo', 'cantidad']);
    esperar_igual('Defecto clasificado aparte del motivo comercial', ['mancha', 'transporte'], [$def->motivo_codigo, $def->origen_codigo]);
    esperar_igual('Motivo comercial de la devolucion (catalogo «devolucion»)', 'rechazado', DB::table('bylopez_lg_retornos')->where('order_id', $o)->value('motivo_codigo'));
    $c = mostrar_comision('devolucion recibida', $o);
    esperar_igual('La devolucion recibida deja la comision en revision (sin politica aprobada)', true, in_array('devolucion_sin_politica', $c['condiciones_pendientes'], true));

    // ---- E2: faltante
    $v2 = $d->venta($cliente, [[215, [216 => 2]]], ['envio' => ['tipo' => 'guardada', 'address_id' => $dir], 'metodo' => 'flatrate_flatrate'], 'cashondelivery', 9);
    $o2 = $v2['orderId'];
    $g2 = despachar($d, $o2, $zona);
    $e2 = (int) $g2['envio_id'];
    $fa = foto($o2);
    $d->paso('La transportadora devuelve las 2 (nadie recibio)', $d->s['bodega']->post("/admin/logistica/entregas/envios/{$e2}/drawer/devolver", [
        'fecha' => now()->toDateString(), 'items' => pendientes_envio(ctx_envio($d, 'bodega', $e2)), 'motivo_codigo' => 'cliente_ausente', 'idempotency_key' => Demo::clave(),
    ]));
    $r2 = ctx_envio($d, 'bodega', $e2)['devoluciones'][0];
    $it2 = (int) ($r2['lineas'][0]['retorno_item_id'] ?? $r2['items'][0]['retorno_item_id'] ?? 0);
    $d->paso('Bodega recibe 1 de 2 (1 apta, 1 faltante con motivo)', $d->s['bodega']->post("/admin/logistica/entregas/devoluciones/{$r2['id']}/recibir", [
        'fecha' => now()->toDateString(), 'idempotency_key' => Demo::clave(),
        'lineas' => [$it2 => ['recibidas' => 1, 'aptas' => 1, 'novedad' => 0, 'motivo' => 'DEMO: faltante, la transportadora reporta una unidad extraviada']],
    ]));
    $fb = foto($o2);
    mostrar_foto('E2 · recepcion con faltante', $fb);
    esperar_igual('El faltante no ingresa: fisico +1 (no +2)', $fa['inventario']['fisico'] + 1, $fb['inventario']['fisico']);
    esperar_igual('C5 descuenta solo lo recibido (1 × 10.000)', 10000.0, $fb['obligacion']['devuelto']);
    guardar_evidencia('E', ['e1' => ['pedido' => $o, 'antes' => $f0, 'despues' => $f2], 'e2' => ['pedido' => $o2, 'antes' => $fa, 'despues' => $fb]]);
}
