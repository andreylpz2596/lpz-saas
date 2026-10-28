<?php

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/foto.php';
require_once __DIR__.'/util.php';
require_once __DIR__.'/a.php';

/*
| PREPARACION DE LA VERIFICACION VISUAL: deja dos pedidos reales (por la API de Nueva venta) para las capturas de Playwright:
|   PEDIDO_CANCELAR   contra entrega local de 6 unidades sin despachar (cancelar lo pendiente desde el drawer de Despachos).
|   PEDIDO_RECOGIDA   recogida por transferencia SIN pago (el drawer exige la instruccion de cobro).
*/
function recorrido_v(Demo $d): void
{
    $ana = (int) DB::table('customers')->where('first_name', 'Ana')->value('id');
    $bruno = (int) DB::table('customers')->where('first_name', 'Bruno')->value('id');
    $a = $d->venta($ana, [[215, [217 => 6]]], ['envio' => ['tipo' => 'guardada', 'address_id' => direccion_de($d, $ana)], 'metodo' => 'flatrate_flatrate'], 'cashondelivery', 9);
    $r = $d->venta($bruno, [[215, [216 => 2]]], ['envio' => ['tipo' => 'punto_recogida'], 'facturacion' => ['tipo' => 'guardada', 'address_id' => direccion_de($d, $bruno)], 'metodo' => 'pickup'], 'moneytransfer');
    echo "\nPEDIDO_CANCELAR={$a['orderId']}\nPEDIDO_RECOGIDA={$r['orderId']}\n";
}
