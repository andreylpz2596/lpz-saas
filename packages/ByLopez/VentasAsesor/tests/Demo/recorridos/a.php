<?php

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/foto.php';
require_once __DIR__.'/util.php';

/*
| RECORRIDO A — LOCAL / TRANSFERENCIA.
| Venta mixta (caballero + dama) → confirmacion y reserva → pago reportado por la asesora → administrador confirma → bodega despacha
| (domiciliario local) → entrega registrada → entrega validada → evaluacion de comision (automatica).
*/

function direccion_de(Demo $d, int $clienteId, string $rol = 'asesora'): int
{
    $r = $d->paso("Direcciones del cliente #{$clienteId}", $d->s[$rol]->get("/admin/ventas-asesor/nueva-venta/clientes/{$clienteId}/direcciones"));
    $lista = $r['direcciones'] ?? $r['data'] ?? $r;

    return (int) ($lista[0]['id'] ?? 0);
}

function recorrido_a(Demo $d): void
{
    $cliente = (int) DB::table('customers')->where('first_name', 'Bruno')->where('last_name', 'Demo Transferencia')->value('id');
    $dir = direccion_de($d, $cliente);

    // Venta mixta: caballero (producto 215, variante 217 × 2) + dama (producto 425, variante 426 × 1). Local, transferencia.
    $v = $d->venta($cliente, [[215, [217 => 2]], [425, [426 => 1]]], ['envio' => ['tipo' => 'guardada', 'address_id' => $dir], 'metodo' => 'flatrate_flatrate'], 'moneytransfer', 9);
    $o = $v['orderId'];
    echo "  Pedido #{$o} (total {$v['total']})\n";
    $f1 = foto($o);
    mostrar_foto('A1 · tras confirmar la venta (reserva creada, nada despachado)', $f1);

    // La transferencia no esta confirmada: F6 impide que salga «sin cobrar nada» en silencio.
    $zona = ['zona_id' => 1, 'transportadora' => 'BRIAN', 'flete_pagador' => 'cliente_al_recibir', 'valor_flete' => 12000];
    $d->rechazo('Bodega intenta despachar con el saldo sin decidir (F6)', $d->s['bodega']->post("/admin/logistica/despachos/pedidos/{$o}/despachar", $zona + ['idempotency_key' => Demo::clave()]), 'disposicion_cobro_requerida');

    $pago = reportar_pago($d, $o, '30000', 'DEMO-NEQUI-0001');
    $d->rechazo('Con el pago solo REPORTADO sigue pidiendo decision (reportado ≠ verificado)', $d->s['bodega']->post("/admin/logistica/despachos/pedidos/{$o}/despachar", $zona + ['idempotency_key' => Demo::clave()]), 'disposicion_cobro_requerida');
    $f2 = foto($o);
    mostrar_foto('A2 · pago reportado, sin confirmar', $f2);

    confirmar_pago($d, $pago);
    echo '  Comision tras confirmar el pago (aun sin entrega): '.json_encode(comision_de($o), JSON_UNESCAPED_UNICODE)."\n";

    $r = despachar($d, $o, $zona);
    $envio = (int) $r['envio_id'];
    $f3 = foto($o);
    mostrar_foto('A3 · despachado (fisico baja, reserva se consume)', $f3);

    registrar_entrega($d, $envio);
    echo '  Comision con entrega registrada pero NO validada: '.json_encode(array_column(comision_de($o), 'estado'))."\n";
    validar_entrega($d, $envio);

    $f4 = foto($o);
    mostrar_foto('A4 · entregado y validado — comision evaluada AUTOMATICAMENTE', $f4);
    guardar_evidencia('A', ['pedido' => $o, 'antes' => $f1, 'pago_reportado' => $f2, 'despachado' => $f3, 'final' => $f4]);
}
