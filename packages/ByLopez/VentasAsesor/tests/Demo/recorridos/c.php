<?php

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/foto.php';
require_once __DIR__.'/util.php';
require_once __DIR__.'/a.php';

/** Direccion puntual en Medellin (fuera de la cobertura local de Bogota). */
function direccion_medellin(string $nombre, string $apellido, string $telefono): array
{
    return ['tipo' => 'puntual', 'direccion' => [
        'nombre' => $nombre, 'apellido' => $apellido, 'lineas' => ['Carrera 43A # 1-50 (DEMO)'], 'pais' => 'co', 'telefono' => $telefono,
        'municipio_codigo' => '05001', 'ciudad' => 'Medellin', 'departamento' => 'ANT',
    ]];
}

/*
| RECORRIDO C — NACIONAL / TRANSFERENCIA.
| Destino fuera de cobertura local (Medellin) → transportadora nacional + guia FICTICIA → el pago confirmado antecede al despacho (F6) →
| despacho → entrega registrada → entrega validada → comision. La guia ficticia no se envia a ninguna transportadora real.
*/
function recorrido_c(Demo $d): void
{
    $cliente = (int) DB::table('customers')->where('first_name', 'Bruno')->value('id');
    $v = $d->venta($cliente, [[215, [218 => 2]], [425, [427 => 1]]], ['envio' => direccion_medellin('Bruno', 'Demo Transferencia', '3009990102'), 'metodo' => 'free_free'], 'moneytransfer');
    $o = $v['orderId'];
    $ctx = $d->paso('Bodega abre el despacho', $d->s['bodega']->get("/admin/logistica/despachos/pedidos/{$o}/contexto"))['contexto'];
    esperar_igual('Destino fuera de cobertura local (nacional)', 'fuera_cobertura', $ctx['envio']['destino_cobertura'] ?? null);
    $forma = ['transportadora' => 'INTER_RAPIDISIMO', 'numero_guia' => 'DEMO-IR-'.random_int(100000, 999999), 'origen_guia' => 'manual', 'flete_pagador' => $ctx['envio']['pagador_deducido'] ?? 'bylopez', 'valor_flete' => 18000];
    mostrar_foto('C1 · venta nacional por transferencia', foto($o));

    $d->rechazo('Sin pago confirmado el despacho pide decision (F6)', $d->s['bodega']->post("/admin/logistica/despachos/pedidos/{$o}/despachar", $forma + ['idempotency_key' => Demo::clave()]), 'disposicion_cobro_requerida');
    confirmar_pago($d, reportar_pago($d, $o, '30000', 'DEMO-C-0001'));
    $g = despachar($d, $o, $forma, 'Bodega despacha por Inter Rapidisimo (guia ficticia '.$forma['numero_guia'].')');
    $envio = (int) $g['envio_id'];
    $guia = DB::table('bylopez_lg_guias')->where('shipment_id', $envio)->first(['numero_guia', 'transportadora_codigo', 'recaudo_esperado', 'liquida_cuenta_corriente']);
    esperar_igual('Guia registrada; transferencia pagada → recaudo 0', [$forma['numero_guia'], 0.0], [$guia->numero_guia, (float) $guia->recaudo_esperado]);
    mostrar_foto('C2 · despachado', foto($o));

    registrar_entrega($d, $envio);
    $c = mostrar_comision('entrega registrada, sin validar', $o);
    esperar_igual('Entregado ≠ validado: la comision sigue estimada', 'estimada', $c['lineas'][0]['estado'] ?? null);
    validar_entrega($d, $envio);
    $c = mostrar_comision('entrega validada + pago confirmado', $o);
    esperar_igual('Comision habilitada (transferencia confirmada es la prueba del dinero)', 'habilitada', $c['lineas'][0]['estado'] ?? null);
    mostrar_foto('C3 · final', foto($o));
    guardar_evidencia('C', ['pedido' => $o, 'guia' => $forma['numero_guia']]);
}
