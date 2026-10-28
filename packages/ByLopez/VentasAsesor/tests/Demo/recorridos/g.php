<?php

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/foto.php';
require_once __DIR__.'/util.php';
require_once __DIR__.'/a.php';

/*
| RECORRIDO G — RECOGIDA EN BODEGA (sin transportadora, sin guia, sin movimiento de cuenta de transportadora).
| G1 pago completo confirmado: sale sin mas decisiones.
| G2 pago pendiente: F6 exige una instruccion explicita. «Cobrar al retirar»: Bodega entrega y el cobro en mostrador entra por Cartera.
| G3 pago pendiente con CREDITO autorizado por el administrador (motivo + historial): sale sin cobro y la deuda sigue en Cartera.
*/
function recorrido_g(Demo $d): void
{
    $cliente = (int) DB::table('customers')->where('first_name', 'Bruno')->value('id');
    $dir = direccion_de($d, $cliente);
    $recogida = ['envio' => ['tipo' => 'punto_recogida'], 'facturacion' => ['tipo' => 'guardada', 'address_id' => $dir], 'metodo' => 'pickup'];
    $sinTransportadora = function (int $o): void {
        esperar_igual('Recogida: sin guia ni movimientos de cuenta de transportadora', [0, 0], [DB::table('bylopez_lg_guias')->where('order_id', $o)->count(), DB::table('bylopez_lg_cuenta_movimientos')->where('order_id', $o)->count()]);
    };

    // ---- G1: pago completo
    $o1 = $d->venta($cliente, [[215, [216 => 1]]], $recogida, 'moneytransfer')['orderId'];
    confirmar_pago($d, reportar_pago($d, $o1, '10000', 'DEMO-G1-0001'));
    $r1 = $d->paso('G1 · Bodega entrega en el mostrador (pago completo confirmado)', $d->s['bodega']->post("/admin/logistica/despachos/pedidos/{$o1}/entregar-en-bodega", ['idempotency_key' => Demo::clave(), 'quien_retira' => 'DEMO retira el cliente']));
    validar_entrega($d, (int) $r1['envio_id']);
    $sinTransportadora($o1);
    mostrar_foto('G1 · recogida con pago completo', foto($o1));
    mostrar_comision('G1', $o1);

    // ---- G2: pago pendiente → «cobrar al retirar»
    $o2 = $d->venta($cliente, [[215, [216 => 2]]], $recogida, 'moneytransfer')['orderId'];
    $ruta = "/admin/logistica/despachos/pedidos/{$o2}/entregar-en-bodega";
    $d->rechazo('G2 · sin pagar y sin instruccion, la recogida NO sale (F6)', $d->s['bodega']->post($ruta, ['idempotency_key' => Demo::clave()]), 'disposicion_cobro_requerida');
    $d->paso('G2 · Bodega registra la instruccion: cobrar al retirar', $d->s['bodega']->post("/admin/logistica/despachos/pedidos/{$o2}/disposicion-cobro", ['tipo' => 'cobrar_al_entregar']));
    $r2 = $d->paso('G2 · Bodega entrega en el mostrador', $d->s['bodega']->post($ruta, ['idempotency_key' => Demo::clave(), 'quien_retira' => 'DEMO retira el cliente']));
    $e2 = (int) $r2['envio_id'];
    $f = foto($o2);
    esperar_igual('Entregado pero la deuda sigue en Cartera (20.000)', 20000.0, $f['obligacion']['saldo']);
    $d->paso('G2 · Se cobra en el mostrador (efectivo, por Cartera)', $d->s['admin']->post("/admin/logistica/entregas/envios/{$e2}/cobrar", [
        'monto' => '20000', 'medio_cobro' => 'efectivo', 'referencia' => 'DEMO-MOSTRADOR', 'fecha_pago' => now()->toDateString(), 'idempotency_key' => Demo::clave(),
    ]));
    validar_entrega($d, $e2);
    $sinTransportadora($o2);
    mostrar_foto('G2 · recogida cobrada al retirar', foto($o2));
    $c = mostrar_comision('G2 · cobro confirmado en Cartera + entrega validada (sin transportadora que custodie dinero)', $o2);

    // ---- G3: pago pendiente → credito autorizado
    $o3 = $d->venta($cliente, [[215, [216 => 1]]], $recogida, 'moneytransfer')['orderId'];
    $d->paso('G3 · El administrador autoriza credito (motivo obligatorio, queda historial)', $d->s['admin']->post("/admin/logistica/despachos/pedidos/{$o3}/disposicion-cobro", ['tipo' => 'credito_autorizado', 'motivo' => 'DEMO: cliente frecuente, paga el viernes']));
    $r3 = $d->paso('G3 · Bodega entrega a credito', $d->s['bodega']->post("/admin/logistica/despachos/pedidos/{$o3}/entregar-en-bodega", ['idempotency_key' => Demo::clave(), 'quien_retira' => 'DEMO retira el cliente']));
    validar_entrega($d, (int) $r3['envio_id']);
    $f3 = foto($o3);
    mostrar_foto('G3 · recogida a credito', $f3);
    esperar_igual('El credito NO es un pago: el saldo sigue en Cartera', 10000.0, $f3['obligacion']['saldo']);
    $c3 = mostrar_comision('G3 · credito: la comision no se habilita mientras haya saldo', $o3);
    esperar_igual('Comision bloqueada por saldo pendiente', true, in_array('saldo_pendiente', $c3['condiciones_pendientes'], true));
    $sinTransportadora($o3);
    echo '  Decision registrada: '.json_encode(DB::table('bylopez_lg_disposiciones_cobro')->where('order_id', $o3)->first(['tipo', 'motivo', 'actor_name_snapshot']), JSON_UNESCAPED_UNICODE)."\n";
    guardar_evidencia('G', ['g1' => $o1, 'g2' => $o2, 'g3' => $o3]);
}
