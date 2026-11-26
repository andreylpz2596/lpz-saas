<?php

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

uses(TestCase::class);

/*
| Ajustes moviles compartidos (iPhone/Safari) de las pantallas ByLopez (VentasAsesor, Doc. 36). Se inyectan con el evento de
| vista `bagisto.admin.layout.head.after` SOLO en rutas ByLopez: las pantallas nativas de Bagisto no cambian. Pest no ejecuta
| CSS: aqui se verifica donde se inyecta y que no use los atajos prohibidos (quitar el zoom u ocultar con overflow hidden).
| El efecto real se midio con WebKit y Chromium en pruebas. Sin datos.
*/

function log19_cabeceraEnRuta(?string $nombreRuta): string
{
    $request = Request::create('/admin/prueba-ajustes-moviles');

    if ($nombreRuta) {
        $ruta = (new Route('GET', '/admin/prueba-ajustes-moviles', fn () => null))->name($nombreRuta);
        $request->setRouteResolver(fn () => $ruta);
    }

    app()->instance('request', $request);

    return (string) view_render_event('bagisto.admin.layout.head.after');
}

test('RESP-1 los ajustes moviles se inyectan en las rutas de los cinco modulos ByLopez', function (string $nombreRuta) {
    expect(log19_cabeceraEnRuta($nombreRuta))->toContain('<style data-bylopez="responsive-movil">');
})->with([
    'Nueva venta' => 'admin.ventas_asesor.nueva_venta.pantalla',
    'Despachos' => 'admin.logistica.despachos.index',
    'Corte y Confeccion' => 'admin.corte_confeccion.ordenes_corte.index',
    'Cargues' => 'admin.cargues.index',
    'Autoservicio' => 'admin.autoservicio.pantalla',
]);

test('RESP-2 las pantallas nativas de Bagisto no reciben los ajustes moviles', function (?string $nombreRuta) {
    expect(log19_cabeceraEnRuta($nombreRuta))->not->toContain('data-bylopez="responsive-movil"');
})->with([
    'Pedidos nativos' => 'admin.sales.orders.index',
    'Clientes nativos' => 'admin.customers.customers.index',
    'Tablero' => 'admin.dashboard.index',
    'Sin ruta' => null,
]);

test('RESP-3 los ajustes no quitan el zoom ni esconden contenido: campos de 16 px solo en pantallas tactiles y recorte sin desplazador', function () {
    // Solo el bloque de este ajuste (el evento tambien trae otras plantillas, como la guarda de scroll).
    preg_match('#<style data-bylopez="responsive-movil">.*?</style>#s', log19_cabeceraEnRuta('admin.ventas_asesor.nueva_venta.pantalla'), $bloque);
    $css = $bloque[0] ?? '';

    expect($css)
        ->not->toBe('')
        ->not->toContain('<meta')
        ->not->toContain('maximum-scale')
        ->not->toContain('user-scalable')
        ->not->toMatch('/overflow(-[xy])?\s*:\s*hidden/')
        ->not->toMatch('/touch-action\s*:\s*none/')
        ->toMatch('/@media \(pointer: coarse\)\s*\{\s*input:not\(\[type="checkbox"\]\)/')
        ->toContain('font-size: 16px;')
        ->toContain('overflow-x: clip;')
        ->toContain('overscroll-behavior: contain;')
        ->toContain('min-height: 100dvh;');
});
