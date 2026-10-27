<?php

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Services\Despachos\ContextoDespachoService;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\Sales\Models\OrderItem;

require_once __DIR__.'/../helpersDespacho.php';
require_once __DIR__.'/../../../VentasAsesor/tests/helpersCom.php';

uses(TestCase::class);

/*
| Despacho V1, ajustes de UX: cantidad inicial = lo realmente despachable (nunca 0 si hay unidades) y «Cliente, al recibir»
| como pagador inicial del flete. Una sola regla de cantidades (la de Bagisto 2.4.9) para la interfaz y el servidor.
| Solo lpz_saas_testing.
*/

beforeEach(fn () => Storage::fake('private'));

/** Linea del contexto del drawer (la unica del pedido de prueba). */
function log4_linea(array $e, $actor): array
{
    return app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, $actor)['unidades']['lineas'][0];
}

function log4_stock(array $e): int
{
    return (int) DB::table('product_inventories')->where('product_id', $e['item']->product_id)->where('inventory_source_id', 1)->sum('qty');
}

test('QTY-1 la cantidad inicial es la PENDIENTE real de Bagisto (qty_to_ship), nunca 0 si hay unidades y stock', function () {
    $bodega = log2_bodega();
    $servicio = app(DespachoService::class);

    // Pedido 2, despachado 0, stock 20 → 2.
    $a = log2_pedido(2, ['stock' => 20]);
    $l = log4_linea($a, $bodega);

    expect($l['pendientes'])->toBe(2)
        ->and($l['disponibles'])->toBe(20)
        ->and($l['maximo'])->toBe(2)
        // Es la semantica de Bagisto, no una regla propia: pedido − enviado − reembolsado − cancelado.
        ->and($l['pendientes'])->toBe((int) OrderItem::query()->find($a['item']->id)->qty_to_ship);

    // Pedido 2, despachado 1 → 1.
    $servicio->despachar($bodega, log2_s($a, ['unidades' => [$a['item']->id => 1]]));
    $l = log4_linea($a, $bodega);

    expect($l['pendientes'])->toBe(1)->and($l['maximo'])->toBe(1)
        ->and($l['pendientes'])->toBe((int) OrderItem::query()->find($a['item']->id)->qty_to_ship);

    // Pedido 10, despachado 4 → 6.
    $b = log2_pedido(10, ['stock' => 50]);
    $servicio->despachar($bodega, log2_s($b, ['unidades' => [$b['item']->id => 4]]));
    $l = log4_linea($b, $bodega);

    expect($l['pendientes'])->toBe(6)->and($l['maximo'])->toBe(6)->and($l['disponibles'])->toBe(46);

    // Reembolsadas y canceladas tampoco son pendientes (misma formula que Bagisto).
    $c = log2_pedido(10, ['stock' => 50]);
    DB::table('order_items')->where('id', $c['item']->id)->update(['qty_canceled' => 2, 'qty_refunded' => 1]);

    expect(log4_linea($c, $bodega)['pendientes'])->toBe(7);
});

test('QTY-2 el maximo es el PENDIENTE aunque haya mas stock; superarlo se rechaza en el backend (servicio y HTTP)', function () {
    $e = log2_pedido(2, ['stock' => 20]);
    $bodega = log2_bodega();
    $servicio = app(DespachoService::class);
    $item = $e['item']->id;

    expect(log4_linea($e, $bodega)['maximo'])->toBe(2);

    // Hay 20 en bodega, pero el pedido solo tiene 2 pendientes: 3 es sobre-despacho.
    expect(log2_error(fn () => $servicio->despachar($bodega, log2_s($e, ['unidades' => [$item => 3]]))))->toBe(Invalida::CANTIDAD_EXCEDE_PENDIENTE)
        ->and(log2_error(fn () => $servicio->despachar($bodega, log2_s($e, ['unidades' => [$item => '2.5']]))))->toBe(Invalida::CANTIDAD_INVALIDA)
        ->and(log2_error(fn () => $servicio->despachar($bodega, log2_s($e, ['unidades' => [$item => '-1']]))))->toBe(Invalida::CANTIDAD_INVALIDA)
        ->and(log2_error(fn () => $servicio->despachar($bodega, log2_s($e, ['unidades' => [$item => 'abc']]))))->toBe(Invalida::CANTIDAD_INVALIDA);

    // Manipulacion del request por HTTP: el HTML no es la regla.
    $this->actingAs($bodega, 'admin')->post(route('admin.logistica.despachos.despachar', $e['pedido']->id), [
        'idempotency_key' => 'qty-'.Str::uuid(), 'transportadora' => 'BYLOPEZ', 'numero_guia' => 'MAN-1', 'origen_guia' => 'manual',
        'flete_pagador' => 'cliente_al_recibir', 'unidades' => [$item => 999],
    ], ['Accept' => 'application/json'])->assertStatus(409)->assertJson(['ok' => false, 'codigo' => 'cantidad_excede_pendiente']);

    expect(DB::table('shipments')->where('order_id', $e['pedido']->id)->count())->toBe(0)
        ->and(log4_stock($e))->toBe(20)
        ->and((int) DB::table('order_items')->where('id', $item)->value('qty_shipped'))->toBe(0);

    // Lo precargado (2) sí se despacha y deja el pedido completo; el stock baja solo en 2.
    $servicio->despachar($bodega, log2_s($e, ['unidades' => [$item => 2]]));

    expect((int) DB::table('order_items')->where('id', $item)->value('qty_shipped'))->toBe(2)
        ->and(log4_stock($e))->toBe(18);
});

test('QTY-3 stock insuficiente: se precarga lo que hay y es un despacho PARCIAL; pedir mas que el stock se rechaza', function () {
    $e = log2_pedido(5, ['stock' => 3]);
    $bodega = log2_bodega();
    $servicio = app(DespachoService::class);
    $item = $e['item']->id;

    $contexto = app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, $bodega);
    $l = $contexto['unidades']['lineas'][0];

    expect($l)->toMatchArray(['pendientes' => 5, 'disponibles' => 3, 'maximo' => 3])
        ->and($contexto['bodega'])->not->toBeNull()
        // Pedir MAS que el stock (aunque no supere lo pendiente) es stock insuficiente, no sobre-despacho.
        ->and(log2_error(fn () => $servicio->despachar($bodega, log2_s($e, ['unidades' => [$item => 4]]))))->toBe(Invalida::STOCK_INSUFICIENTE)
        // Sin cantidades explicitas el servicio entiende «todo lo pendiente»: 5 > 3 → rechazo (la interfaz siempre manda las acotadas).
        ->and(log2_error(fn () => $servicio->despachar($bodega, log2_s($e))))->toBe(Invalida::STOCK_INSUFICIENTE)
        ->and(DB::table('shipments')->where('order_id', $e['pedido']->id)->count())->toBe(0);

    // Lo que la interfaz precarga (3) se despacha como PARCIAL.
    $servicio->despachar($bodega, log2_s($e, ['unidades' => [$item => $l['maximo']]]));

    $despues = app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, $bodega);

    expect((int) DB::table('order_items')->where('id', $item)->value('qty_shipped'))->toBe(3)
        ->and(log4_stock($e))->toBe(0)
        ->and($despues['modo'])->toBe('completar')
        ->and($despues['unidades']['pendientes_total'])->toBe(2)
        // Ahora el stock es 0: nada que precargar hasta que reingrese mercancia.
        ->and($despues['unidades']['lineas'][0])->toMatchArray(['pendientes' => 2, 'disponibles' => 0, 'maximo' => 0])
        ->and($despues['bodega'])->toBeNull();

    // Reingresa stock: vuelve a ser despachable lo que quedaba.
    DB::table('product_inventories')->where('product_id', $e['item']->product_id)->where('inventory_source_id', 1)->update(['qty' => 40]);

    expect(log4_linea($e, $bodega))->toMatchArray(['pendientes' => 2, 'disponibles' => 40, 'maximo' => 2]);
});

test('QTY-4 stock 0: maximo 0, sin bodega, nada que despachar y el backend lo rechaza', function () {
    $e = log2_pedido(3, ['stock' => 0]);
    $bodega = log2_bodega();
    $servicio = app(DespachoService::class);
    $item = $e['item']->id;

    $contexto = app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, $bodega);

    expect($contexto['unidades']['lineas'][0])->toMatchArray(['pendientes' => 3, 'disponibles' => 0, 'maximo' => 0])
        ->and($contexto['bodega'])->toBeNull()
        ->and(log2_error(fn () => $servicio->despachar($bodega, log2_s($e, ['unidades' => [$item => 1]]))))->toBe(Invalida::STOCK_INSUFICIENTE)
        ->and(log2_error(fn () => $servicio->despachar($bodega, log2_s($e, ['unidades' => [$item => 0]]))))->toBe(Invalida::SIN_UNIDADES)
        ->and(log2_error(fn () => $servicio->despachar($bodega, log2_s($e))))->toBe(Invalida::STOCK_INSUFICIENTE)
        ->and(DB::table('shipments')->where('order_id', $e['pedido']->id)->count())->toBe(0);

    // Un pedido con una linea sin stock y otra con stock se puede despachar SOLO por la segunda.
    $dos = log2_pedido(2, ['stock' => 5]);
    $sinStock = log2_pedido(4, ['stock' => 0]);
    DB::table('order_items')->where('id', $sinStock['item']->id)->update(['order_id' => $dos['pedido']->id]);

    $lineas = collect(app(ContextoDespachoService::class)->paraPedido((int) $dos['pedido']->id, $bodega)['unidades']['lineas'])->keyBy('id');

    expect($lineas[$dos['item']->id]['maximo'])->toBe(2)
        ->and($lineas[$sinStock['item']->id]['maximo'])->toBe(0);

    $servicio->despachar($bodega, log2_s($dos, ['unidades' => [$dos['item']->id => 2, $sinStock['item']->id => 0]]));

    expect((int) DB::table('order_items')->where('id', $dos['item']->id)->value('qty_shipped'))->toBe(2)
        ->and((int) DB::table('order_items')->where('id', $sinStock['item']->id)->value('qty_shipped'))->toBe(0);
});

test('QTY-5 el backend vuelve a validar al crear el Shipment: formulario viejo, otra operaria o stock que cambio', function () {
    $e = log2_pedido(5, ['stock' => 20]);
    $bodega = log2_bodega();
    $otra = log2_bodega();
    $servicio = app(DespachoService::class);
    $item = $e['item']->id;

    // La operaria abre el drawer: pendiente 5, maximo 5 (formulario que queda "viejo").
    expect(log4_linea($e, $bodega)['maximo'])->toBe(5);

    // Mientras tanto otra operaria despacha 3.
    $servicio->despachar($otra, log2_s($e, ['unidades' => [$item => 3]]));

    // Enviar los 5 del formulario viejo es un sobre-despacho (ahora solo quedan 2).
    expect(log2_error(fn () => $servicio->despachar($bodega, log2_s($e, ['unidades' => [$item => 5]]))))->toBe(Invalida::CANTIDAD_EXCEDE_PENDIENTE)
        ->and((int) DB::table('order_items')->where('id', $item)->value('qty_shipped'))->toBe(3);

    // El stock cambia (otra venta lo consume) antes de confirmar: se rechaza lo que ya no alcanza.
    DB::table('product_inventories')->where('product_id', $e['item']->product_id)->where('inventory_source_id', 1)->update(['qty' => 1]);

    expect(log2_error(fn () => $servicio->despachar($bodega, log2_s($e, ['unidades' => [$item => 2]]))))->toBe(Invalida::STOCK_INSUFICIENTE)
        ->and((int) DB::table('order_items')->where('id', $item)->value('qty_shipped'))->toBe(3);

    // Con lo que si hay (1) se completa parcialmente; ni una unidad mas.
    $servicio->despachar($bodega, log2_s($e, ['unidades' => [$item => 1]]));

    expect((int) DB::table('order_items')->where('id', $item)->value('qty_shipped'))->toBe(4)
        ->and(log4_stock($e))->toBe(0)
        ->and(DB::table('shipments')->where('order_id', $e['pedido']->id)->count())->toBe(2);
});

test('QTY-6 configurable real (hija con qty_ordered = 0): el maximo mide el stock de la variante que el Shipment descuenta', function () {
    Mail::fake();
    f03f_configurarBase();

    $cliente = f03b_cliente(f03b_grupo('QTY6'));
    $configurable = f03b_configurable(8500.0);
    $variante = $configurable->variants()->first();
    f03b_stock($variante, 20);

    $pedido = com_vender($cliente->id, f03b_admin(), $configurable, $variante, 3);
    DB::table('orders')->where('id', $pedido->id)->update(['shipping_method' => 'envio_nacional']);

    $hija = DB::table('order_items')->where('parent_id', DB::table('order_items')->where('order_id', $pedido->id)->whereNull('parent_id')->value('id'))->first();
    $bodega = log2_bodega();
    $servicio = app(DespachoService::class);
    $e = ['pedido' => $pedido];
    $linea = fn () => app(ContextoDespachoService::class)->paraPedido((int) $pedido->id, $bodega)['unidades']['lineas'][0];

    // Caso real del proyecto: la hija no tiene cantidad propia.
    expect((int) $hija->qty_ordered)->toBe(0)
        ->and($linea())->toMatchArray(['pendientes' => 3, 'disponibles' => 20, 'maximo' => 3]);

    // Queda solo 1 unidad de ESA variante: maximo 1 aunque el pedido tenga 3 pendientes.
    DB::table('product_inventories')->where('product_id', $variante->id)->update(['qty' => 1]);

    $l = $linea();

    expect($l)->toMatchArray(['pendientes' => 3, 'disponibles' => 1, 'maximo' => 1])
        ->and(log2_error(fn () => $servicio->despachar($bodega, log2_s($e, ['unidades' => [$l['id'] => 2]]))))->toBe(Invalida::STOCK_INSUFICIENTE);

    $servicio->despachar($bodega, log2_s($e, ['unidades' => [$l['id'] => 1]]));

    // El Shipment descuenta el stock de la variante y la linea queda parcial.
    expect((int) DB::table('order_items')->where('id', $l['id'])->value('qty_shipped'))->toBe(1)
        ->and((int) DB::table('order_items')->where('id', $hija->id)->value('qty_shipped'))->toBe(1)
        ->and((int) DB::table('product_inventories')->where('product_id', $variante->id)->sum('qty'))->toBe(0)
        ->and($linea())->toMatchArray(['pendientes' => 2, 'disponibles' => 0, 'maximo' => 0]);
});

test('QTY-7 quien paga el flete: se DEDUCE de la condicion de envio; una excepcion exige motivo y no contamina el siguiente', function () {
    $bodega = log2_bodega();
    $servicio = app(DespachoService::class);
    $contexto = fn (array $e) => app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, $bodega);

    $a = log2_pedido(2, ['envio' => 'envio_nacional']);
    $b = log2_pedido(2, ['envio' => 'domicilio_local']);
    $c = log2_pedido(2, ['envio' => 'envio_nacional']);

    // Domicilios locales F1: por pagar → Cliente, al recibir; domicilio cobrado en el pedido → ByLopez.
    expect($contexto($a)['sugerencias']['flete_pagador'])->toBe('cliente_al_recibir')
        ->and($contexto($b)['sugerencias']['flete_pagador'])->toBe('bylopez')
        ->and($contexto($a)['pagadores'])->toBe(['cliente_al_recibir', 'bylopez', 'incluido_en_venta', 'otro']);

    // Excepcion en un despacho: ByLopez paga ese flete. Sin motivo se rechaza; con motivo queda guardado.
    expect(log2_error(fn () => $servicio->despachar($bodega, log2_s($a, ['fletePagador' => 'bylopez', 'valorFlete' => '9500']))))->toBe(Invalida::MOTIVO_PAGADOR_REQUERIDO);

    $guia = $servicio->despachar($bodega, log2_s($a, ['fletePagador' => 'bylopez', 'valorFlete' => '9500', 'motivoPagador' => 'Cortesia al cliente']))->guia;

    expect($guia->flete_pagador->value)->toBe('bylopez')
        ->and($guia->pagador_deducido)->toBe('cliente_al_recibir')
        ->and($guia->motivo_pagador)->toBe('Cortesia al cliente')
        // El siguiente despacho nuevo vuelve a empezar en lo deducido (nada del historial lo cambia).
        ->and($contexto($c)['sugerencias']['flete_pagador'])->toBe('cliente_al_recibir')
        ->and($contexto($b)['sugerencias']['flete_pagador'])->toBe('bylopez');

    // Las demas opciones siguen disponibles, siempre como excepcion con motivo.
    foreach (['incluido_en_venta', 'otro'] as $pagador) {
        $p = log2_pedido(1);
        $r = $servicio->despachar($bodega, log2_s($p, ['fletePagador' => $pagador, 'motivoPagador' => 'Caso especial']))->guia;

        expect($r->flete_pagador->value)->toBe($pagador);
    }

    // El drawer ya no recuerda el pagador (si lo hiciera, la excepcion anterior volveria a aparecer).
    $componente = file_get_contents(base_path('packages/ByLopez/Logistica/src/Resources/views/admin/despachos/componente.blade.php'));

    expect($componente)->not->toContain("guardarPreferencia('pagador'")
        ->and($componente)->not->toContain("leerPreferencia('pagador')")
        ->and($componente)->toContain("olvidarPreferencia('pagador')");
});

test('QTY-8 comportamiento real del JavaScript del drawer: precarga, tope, parcial, stock 0 y pagador inicial', function () {
    exec('node -v 2>&1', $version, $codigo);

    if ($codigo !== 0) {
        $this->markTestSkipped('Node no esta disponible en este entorno.');
    }

    $salida = [];
    exec(
        'node '.escapeshellarg(base_path('packages/ByLopez/Logistica/tests/js/componente_despacho.mjs')).' '
        .escapeshellarg(base_path('packages/ByLopez/Logistica/src/Resources/views/admin/despachos/componente.blade.php')).' 2>&1',
        $salida,
        $codigo,
    );

    expect(implode("\n", $salida))->toContain('JS-OK')->and($codigo)->toBe(0);
});
