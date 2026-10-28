<?php

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/foto.php';
require_once __DIR__.'/util.php';
require_once __DIR__.'/a.php';

/*
| RECORRIDO F — CANCELACION DEL PENDIENTE.
| Pedido de 10 unidades → despacho de 6 → cancelacion de las 4 pendientes → la reserva de las 4 se libera, el stock FISICO no sube, las 6
| enviadas conservan su trazabilidad y, si regresan 2, su entrada solo existe cuando Bodega las recibe. La cancelacion nativa de Bagisto
| (que reintegraria al fisico lo enviado) no es un camino: el servidor la rechaza.
*/
function recorrido_f(Demo $d): void
{
    $cliente = (int) DB::table('customers')->where('first_name', 'Ana')->value('id');
    $dir = direccion_de($d, $cliente);
    $v = $d->venta($cliente, [[215, [219 => 10]]], ['envio' => ['tipo' => 'guardada', 'address_id' => $dir], 'metodo' => 'flatrate_flatrate'], 'cashondelivery', 9);
    $o = $v['orderId'];
    esperar_igual('Total del pedido (10 × 10.000)', 100000.0, $v['total']);
    $f0 = foto($o);
    mostrar_foto('F1 · 10 unidades confirmadas (reservadas)', $f0);

    $ctx = $d->paso('Bodega abre el despacho', $d->s['bodega']->get("/admin/logistica/despachos/pedidos/{$o}/contexto"))['contexto'];
    $linea = $ctx['unidades']['lineas'][0]['id'];
    $g = despachar($d, $o, ['zona_id' => 1, 'transportadora' => 'BRIAN', 'flete_pagador' => 'cliente_al_recibir', 'valor_flete' => 12000, 'unidades' => [$linea => 6]], 'Bodega despacha 6 de las 10 (parcial)');
    $envio = (int) $g['envio_id'];
    $f1 = foto($o);
    mostrar_foto('F2 · 6 despachadas, 4 pendientes', $f1);
    esperar_igual('Fisico bajo 6 por el despacho', $f0['inventario']['fisico'] - 6, $f1['inventario']['fisico']);
    esperar_igual('Reserva paso de 10 a 4 (lo pendiente)', $f0['inventario']['reservado'] - 6, $f1['inventario']['reservado']);

    // Caminos que NO deben funcionar.
    $url = "/admin/logistica/despachos/pedidos/{$o}/cancelar-pendiente";
    $d->paso('La asesora no puede cancelar (sin la clave ACL: el servidor corta la ruta)', $d->s['asesora']->post($url, ['motivo' => 'Intento de asesora']), [401, 403]);
    $d->paso('Bodega tampoco (la clave no se concede por defecto)', $d->s['bodega']->post($url, ['motivo' => 'Intento de bodega']), [401, 403]);
    $d->rechazo('Sin motivo no se cancela', $d->s['admin']->post($url, ['motivo' => '']), 'motivo_cancelacion_requerido');
    [$estado] = $d->s['admin']->post("/admin/sales/orders/cancel/{$o}");
    $f1b = foto($o);
    echo "  Cancelacion NATIVA de Bagisto sobre el pedido (HTTP {$estado}): fisico {$f1b['inventario']['fisico']}, canceladas {$f1b['unidades']['canceladas']} (nada cambio)\n";
    esperar_igual('La cancelacion nativa no inflo el fisico ni cancelo nada', [$f1['inventario']['fisico'], 0], [$f1b['inventario']['fisico'], $f1b['unidades']['canceladas']]);

    $r = $d->paso('Administrador cancela lo pendiente CON motivo', $d->s['admin']->post($url, ['motivo' => 'DEMO: el cliente solo quiere 6 camisetas']));
    esperar_igual('Se cancelaron 4 unidades', 4, $r['unidades']);
    esperar_igual('Valor cancelado (4 × 10.000)', 40000.0, (float) $r['valor']);
    $d->rechazo('Repetir la cancelacion no libera ni ajusta dos veces', $d->s['admin']->post($url, ['motivo' => 'Doble clic']), 'cancelacion_sin_pendiente');
    $f2 = foto($o);
    mostrar_foto('F3 · pendiente cancelado', $f2);
    esperar_igual('Reserva liberada (4 → 0 de este pedido)', $f0['inventario']['reservado'] - 10, $f2['inventario']['reservado']);
    esperar_igual('Stock fisico NO aumento por cancelar', $f1['inventario']['fisico'], $f2['inventario']['fisico']);
    esperar_igual('Unidades: 6 despachadas + 4 canceladas = 10 pedidas', [6, 4, 10], [$f2['unidades']['despachadas'], $f2['unidades']['canceladas'], $f2['unidades']['pedidas']]);
    esperar_igual('Obligacion exigible = 100.000 − 40.000 cancelado', [100000.0, 40000.0, 60000.0], [$f2['obligacion']['total'], $f2['obligacion']['cancelado'], $f2['obligacion']['exigible']]);

    // De las 6 enviadas, la transportadora entrega 4 y DEVUELVE 2 (no pudo entregarlas). Su entrada al inventario solo existe cuando Bodega las recibe.
    registrar_entrega($d, $envio, pendientes_envio(ctx_envio($d, 'bodega', $envio), 4));
    $ctxE = ctx_envio($d, 'bodega', $envio);
    $d->paso('La transportadora devuelve 2 sin entregar (motivo: talla)', $d->s['bodega']->post("/admin/logistica/entregas/envios/{$envio}/drawer/devolver", [
        'fecha' => now()->toDateString(), 'items' => pendientes_envio($ctxE), 'motivo_codigo' => 'talla', 'idempotency_key' => Demo::clave(),
    ]));
    $f3 = foto($o);
    esperar_igual('Registrar la devolucion NO sube el inventario ni baja la obligacion', [$f2['inventario']['fisico'], $f2['obligacion']['exigible']], [$f3['inventario']['fisico'], $f3['obligacion']['exigible']]);
    $ctxE = ctx_envio($d, 'bodega', $envio);
    $retorno = $ctxE['devoluciones'][0];
    $itemRetorno = (int) ($retorno['lineas'][0]['retorno_item_id'] ?? $retorno['items'][0]['retorno_item_id'] ?? 0);
    $d->paso('Bodega recibe fisicamente las 2 (aptas)', $d->s['bodega']->post("/admin/logistica/entregas/devoluciones/{$retorno['id']}/recibir", [
        'fecha' => now()->toDateString(), 'idempotency_key' => Demo::clave(), 'lineas' => [$itemRetorno => ['recibidas' => 2, 'aptas' => 2, 'novedad' => 0]],
    ]));
    validar_entrega($d, $envio);
    mostrar_comision('4 entregadas + 2 devueltas y recibidas', $o);
    $f4 = foto($o);
    mostrar_foto('F4 · 4 entregadas, 2 devueltas y recibidas', $f4);
    esperar_igual('Entrada de inventario SOLO tras recibir (+2)', $f3['inventario']['fisico'] + 2, $f4['inventario']['fisico']);
    esperar_igual('C5: devuelto 20.000, cancelado 40.000 → exigible 40.000', [20000.0, 40000.0, 40000.0], [$f4['obligacion']['devuelto'], $f4['obligacion']['cancelado'], $f4['obligacion']['exigible']]);
    guardar_evidencia('F', ['pedido' => $o, 'antes' => $f0, 'parcial' => $f1, 'cancelado' => $f2, 'recibido' => $f4]);
}

/** Unidades entregadas de un envio: shipment_item_id => entregadas. */
function entregadas_envio(array $ctx): array
{
    $items = [];

    foreach ($ctx['productos'] as $p) {
        foreach ($p['filas'] as $f) {
            if ((int) $f['entregadas'] > 0) {
                $items[(int) $f['shipment_item_id']] = (int) $f['entregadas'];
            }
        }
    }

    return $items;
}
