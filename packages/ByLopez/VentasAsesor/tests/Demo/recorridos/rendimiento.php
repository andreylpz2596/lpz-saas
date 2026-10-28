<?php

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/util.php';
require_once __DIR__.'/a.php';

/*
| RENDIMIENTO en la instancia aislada (sin Debugbar: APP_DEBUG=false en el servidor de la demo). Mide TIEMPO (HTTP real, ida y vuelta) y
| CONSULTAS SQL (las mismas llamadas que hace cada pantalla, contadas en este proceso) de:
|   · agregar a un pedido varias referencias y colores,
|   · confirmar la venta,
|   · abrir Comisiones (resumen del mes + lista de pedidos),
|   · abrir el detalle de una cuenta de transportadora.
| El volumen usado se imprime junto a cada medicion. No se hace una prueba de 10.000 pedidos.
*/
function recorrido_rendimiento(Demo $d): void
{
    $cliente = (int) DB::table('customers')->where('first_name', 'Ana')->value('id');
    $dir = direccion_de($d, $cliente);
    $a = $d->s['asesora'];
    $medir = function (string $texto, callable $f) {
        $t = microtime(true);
        $r = $f();
        $ms = round((microtime(true) - $t) * 1000);
        echo sprintf("  %-70s %6d ms\n", $texto, $ms);

        return [$r, $ms];
    };

    echo '  Volumen de la base: pedidos='.DB::table('orders')->count().' lineas de comision='.DB::table('bylopez_va_comision_lineas')->count()
        .' movimientos de cuenta='.DB::table('bylopez_lg_cuenta_movimientos')->count().' pagos='.DB::table('bylopez_va_pagos')->count()."\n";

    [$b] = $medir('Iniciar la venta', fn () => $a->post('/admin/ventas-asesor/nueva-venta/iniciar', ['customer_id' => $cliente, 'canal' => 'whatsapp']));
    $id = (int) ($b[1]['borrador']['id'] ?? 0);
    $total = 0;

    // Varias referencias y colores: 2 referencias × 4 tallas (8 variantes) en 2 llamadas.
    [, $ms1] = $medir('Agregar referencia 1 (4 variantes)', fn () => $a->post("/admin/ventas-asesor/nueva-venta/{$id}/tallas", ['producto_id' => 215, 'cantidades' => [216 => 1, 217 => 1, 218 => 1, 219 => 1]]));
    [, $ms2] = $medir('Agregar referencia 2 (4 variantes)', fn () => $a->post("/admin/ventas-asesor/nueva-venta/{$id}/tallas", ['producto_id' => 425, 'cantidades' => [426 => 1, 427 => 1, 428 => 1, 429 => 1]]));
    $medir('Definir entrega', fn () => $a->post("/admin/ventas-asesor/nueva-venta/{$id}/entrega", ['envio' => ['tipo' => 'guardada', 'address_id' => $dir], 'metodo' => 'flatrate_flatrate']));
    $medir('Elegir domiciliario', fn () => $a->post("/admin/ventas-asesor/nueva-venta/{$id}/domiciliario", ['transportadora_id' => 9]));
    $medir('Elegir pago', fn () => $a->post("/admin/ventas-asesor/nueva-venta/{$id}/pago", ['metodo' => 'cashondelivery']));
    [$est] = $medir('Leer el estado del borrador (8 lineas)', fn () => $a->get("/admin/ventas-asesor/nueva-venta/{$id}"));
    $total = (float) ($est[1]['borrador']['totales']['grand_total'] ?? 0);
    [$conf, $msConf] = $medir("Confirmar la venta (total {$total})", fn () => $a->post("/admin/ventas-asesor/nueva-venta/{$id}/confirmar", ['total_esperado' => $total]));
    echo '    pedido #'.($conf[1]['orderId'] ?? '?')." (HTTP {$conf[0]})\n";

    $consultas = function (callable $f): array {
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        $t = microtime(true);
        $f();
        $ms = round((microtime(true) - $t) * 1000);
        $n = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        return [$n, $ms];
    };

    $admin = Webkul\User\Models\Admin::query()->where('email', 'demo.admin@bylopez.test')->firstOrFail();
    $consulta = app(ByLopez\VentasAsesor\Services\Comisiones\ConsultaComisionesService::class);
    $mes = now()->format('Y-m');
    [$n, $ms] = $consultas(function () use ($consulta, $admin, $mes) {
        $consulta->resumenMes($admin, $mes);
        $consulta->pedidosDelMes($admin, ['mes' => $mes, 'asesor' => null, 'estado' => null]);
    });
    echo sprintf("  %-70s %6d ms  %d consultas SQL\n", 'Comisiones: resumen del mes + pedidos del mes (servicio, admin)', $ms, $n);

    $medir('Comisiones (HTTP completo, vista del administrador)', fn () => $d->s['admin']->http->get('/admin/ventas-asesor/comisiones', ['headers' => ['Accept' => 'text/html']]));
    $brian = (int) DB::table('bylopez_lg_transportadoras')->where('codigo', 'BRIAN')->value('id');
    $cuentas = app(ByLopez\Logistica\Services\Cuentas\ConsultaCuentasService::class);
    [$n, $ms] = $consultas(fn () => $cuentas->contexto($admin, $brian));
    echo sprintf("  %-70s %6d ms  %d consultas SQL\n", 'Detalle de la cuenta de Brian (servicio)', $ms, $n);
    $medir('Detalle de la cuenta (HTTP completo)', fn () => $d->s['admin']->get("/admin/logistica/cuentas/{$brian}/contexto"));
    $medir('Pantalla Cuentas (HTTP, vista HTML)', fn () => $d->s['admin']->http->get('/admin/logistica/cuentas', ['headers' => ['Accept' => 'text/html']]));
    guardar_evidencia('RENDIMIENTO', ['agregar_ref1_ms' => $ms1, 'agregar_ref2_ms' => $ms2, 'confirmar_ms' => $msConf]);
}
