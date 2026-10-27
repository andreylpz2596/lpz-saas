<?php

use ByLopez\VentasAsesor\Services\Venta\ConfirmarVentaService;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;
use Webkul\Sales\Models\Order;

require_once __DIR__.'/helpersFase03f.php';

/*
| Helpers COM (solo lpz_saas_testing). Datos de fixture, no reglas de negocio.
*/

if (! function_exists('com_costo')) {
    /** Fija el atributo `cost` (global) de un producto como lo guarda Bagisto; null lo elimina. */
    function com_costo(Product $producto, ?float $valor): void
    {
        $atributo = (int) DB::table('attributes')->where('code', 'cost')->value('id');
        $clave = ['product_id' => $producto->id, 'attribute_id' => $atributo, 'channel' => null, 'locale' => null];

        if ($valor === null) {
            DB::table('product_attribute_values')->where($clave)->delete();

            return;
        }

        DB::table('product_attribute_values')->updateOrInsert($clave, [
            'float_value' => $valor, 'unique_id' => $producto->id.'|'.$atributo,
        ]);
    }
}

if (! function_exists('com_vender')) {
    /** Venta REAL por Nueva venta → ConfirmarVentaService → OrderRepository (con sus listeners). */
    function com_vender(int $customerId, $asesor, Product $configurable, Product $variante, int $cantidad = 3): Order
    {
        $borrador = f03f_borradorListo($customerId, $asesor, [[$configurable, $variante, $cantidad]]);

        $r = f03f_sinCommitPrematuro(fn () => app(ConfirmarVentaService::class)
            ->confirmar($borrador->id, $asesor, f03f_total($borrador)));

        return Order::query()->findOrFail($r->orderId);
    }
}
