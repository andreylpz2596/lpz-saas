<?php

use ByLopez\Logistica\Services\Despachos\DespachoService;
use Tests\TestCase;

require_once __DIR__.'/../../../Logistica/tests/helpersDespacho.php';

uses(TestCase::class);

/*
| Detalle del pedido (Mis ventas): tras despachar desde el drawer, la pantalla se actualiza con el HTML fresco del propio
| detalle (estados, tabla de productos con «Enviadas» y guias). Aqui se prueba lo que el servidor entrega: las zonas
| marcadas `data-refrescable` y que su contenido cambia con el despacho. El reemplazo en el navegador lo hace el drawer.
*/

test('el detalle marca las zonas refrescables y, tras un despacho parcial, el HTML fresco trae las cantidades nuevas', function () {
    $e = log2_pedido(3, ['pago' => 'moneytransfer']);
    $admin = f03b_admin('all');
    $url = route('admin.ventas_asesor.pedidos.index', ['pedido' => $e['pedido']->id]);

    $antes = $this->actingAs($admin, 'admin')->get($url)->assertOk();

    foreach (['cabecera', 'productos', 'envio'] as $zona) {
        $antes->assertSee('data-refrescable="'.$zona.'"', false);
    }

    $antes->assertSee('data-pedido="'.$e['pedido']->id.'" data-accion="despachar"', false);

    // Despacho PARCIAL: 2 de 3 unidades.
    app(DespachoService::class)->despachar(log2_bodega(), log2_s($e, ['unidades' => [$e['item']->id => 2]]));

    // Estado en la cabecera ANTES del despacho.
    $cabecera = fn (string $html) => substr($html, strpos($html, 'data-refrescable="cabecera"'), 900);
    expect($cabecera($antes->getContent()))->toContain('Sin despachar');

    $despues = $this->actingAs($admin, 'admin')->get($url)->assertOk();

    $html = $despues->getContent();
    $productos = substr($html, strpos($html, 'data-refrescable="productos"'));
    $productos = substr($productos, 0, strpos($productos, 'data-refrescable="envio"') ?: 4000);

    expect($productos)->toMatch('/<td[^>]*>\s*3\s*<\/td>\s*<td[^>]*>\s*2\s*<\/td>/')   // pedidas 3 · enviadas 2
        ->and($cabecera($html))->not->toContain('Sin despachar')
        ->and($cabecera($html))->toContain(trans('ventasasesor::app.pedidos.despacho.parcial'))
        ->and($html)->toContain('data-accion="completar"');
});
