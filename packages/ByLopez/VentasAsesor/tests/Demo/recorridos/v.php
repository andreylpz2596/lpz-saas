<?php

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/foto.php';
require_once __DIR__.'/util.php';
require_once __DIR__.'/a.php';
require_once __DIR__.'/j.php';

/*
| PREPARACION DE LA VERIFICACION VISUAL: deja dos pedidos reales (por la API de Nueva venta) para las capturas de Playwright:
|   PEDIDO_CANCELAR   contra entrega local de 6 unidades sin despachar (cancelar lo pendiente desde el drawer de Despachos).
|   PEDIDO_CANCELAR_MOVIL  igual que PEDIDO_CANCELAR, para la captura en movil.
|   PEDIDO_RECOGIDA   recogida por transferencia SIN pago (el drawer exige la instruccion de cobro).
|   PEDIDO_F6         transferencia de 10: decision B, 6 despachadas, 2 devueltas y recibidas (la decision anterior ya no vale; faltan 4 por despachar).
*/
function recorrido_v(Demo $d): void
{
    $ana = (int) DB::table('customers')->where('first_name', 'Ana')->value('id');
    $bruno = (int) DB::table('customers')->where('first_name', 'Bruno')->value('id');
    $a = $d->venta($ana, [[215, [217 => 6]]], ['envio' => ['tipo' => 'guardada', 'address_id' => direccion_de($d, $ana)], 'metodo' => 'flatrate_flatrate'], 'cashondelivery', 9);
    $a2 = $d->venta($ana, [[215, [217 => 6]]], ['envio' => ['tipo' => 'guardada', 'address_id' => direccion_de($d, $ana)], 'metodo' => 'flatrate_flatrate'], 'cashondelivery', 9);
    $r = $d->venta($bruno, [[215, [216 => 2]]], ['envio' => ['tipo' => 'punto_recogida'], 'facturacion' => ['tipo' => 'guardada', 'address_id' => direccion_de($d, $bruno)], 'metodo' => 'pickup'], 'moneytransfer');
    [$pf6] = f6_parcial_devuelto($d, $bruno, direccion_de($d, $bruno), ['zona_id' => 1, 'transportadora' => 'BRIAN', 'flete_pagador' => 'bylopez', 'motivo_pagador' => 'DEMO: ByLopez asume el flete', 'valor_flete' => 12000]);
    echo "\nPEDIDO_CANCELAR={$a['orderId']}\nPEDIDO_RECOGIDA={$r['orderId']}\nPEDIDO_F6={$pf6}\nPEDIDO_CANCELAR_MOVIL={$a2['orderId']}\n";
}
