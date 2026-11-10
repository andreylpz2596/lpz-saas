<?php

use ByLopez\Logistica\DataObjects\SolicitudEventoEnvio;
use ByLopez\Logistica\Enums\FuenteEventoLogistico as Fuente;
use ByLopez\Logistica\Services\Entregas\RegistrarRetornoTransportadoraService;
use ByLopez\VentasAsesor\Services\Comisiones\AvanceAsesorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\Sales\Repositories\ShipmentRepository;

require_once __DIR__.'/../helpersRt04b1a.php';
require_once __DIR__.'/../helpersFase03b.php';

uses(TestCase::class);

/*
| PORCENTAJE DE DEVOLUCION TOTAL POR ASESORA (2026-11-04). Solo lpz_saas_testing. «Total» = TODAS las unidades de un envio devueltas; la
| devolucion parcial no cuenta. Pedido y envio reales (ShipmentRepository) y retorno por el servicio de Logistica; la expectativa se
| calcula a mano (paquetes devueltos completos / paquetes despachados).
*/

/** Pedido de `$qty` unidades despachado en un envio, con una devolucion registrada de `$devuelve` (null = sin devolucion). */
function com8_envio(int $qty, ?int $devuelve): array
{
    Mail::fake();
    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido();
    $item->update(['qty_ordered' => $qty, 'qty_shipped' => 0]);

    $envio = app(ShipmentRepository::class)->create(['order_id' => $pedido->id, 'shipment' => [
        'carrier_title' => 'BYLOPEZ', 'track_number' => 'COM8-'.uniqid(), 'source' => 1,
        'items' => [$item->id => [1 => $qty]],
    ]]);
    $si = (int) DB::table('shipment_items')->where('shipment_id', $envio->id)->value('id');

    if ($devuelve !== null) {
        app(RegistrarRetornoTransportadoraService::class)->registrar(f03b_admin('custom', ['logistica', 'logistica.entregas', 'logistica.entregas.registrar']), new SolicitudEventoEnvio(
            shipmentId: (int) $envio->id, orderId: (int) $pedido->id, fecha: now()->toDateString(), items: [$si => $devuelve],
            fuente: Fuente::BODEGA_MANUAL, idempotencyKey: 'com8-ret-'.Str::uuid(), motivo: 'COM8',
        ));
    }

    return ['pedido' => (int) $pedido->id, 'item' => (int) $item->id, 'envio' => (int) $envio->id];
}

function com8_devolucion(array $pedidos, array $items): array
{
    $metodo = new ReflectionMethod(AvanceAsesorService::class, 'devolucionTotal');

    return $metodo->invoke(app(AvanceAsesorService::class), $pedidos, $items);
}

test('DV-1 un paquete devuelto completo cuenta (100 %, «alto», por recibir); una devolucion parcial NO cuenta', function () {
    $total = com8_envio(10, 10);
    $parcial = com8_envio(10, 4);
    $sinDevolucion = com8_envio(5, null);

    $d = com8_devolucion([$total['pedido']], [$total['item']]);
    expect($d['paquetes'])->toBe(1)->and($d['devueltos'])->toBe(1)->and($d['por_recibir'])->toBe(1)->and($d['recibidos'])->toBe(0)
        ->and($d['porcentaje'])->toBe(100.0)->and($d['nivel'])->toBe('alerta');

    $p = com8_devolucion([$parcial['pedido']], [$parcial['item']]);
    expect($p['devueltos'])->toBe(0)->and($p['porcentaje'])->toBe(0.0)->and($p['nivel'])->toBe('ok');

    // Tres paquetes de la misma asesora: 1 devuelto completo de 3 = 33,3 % → «alto» (≥ 25 %).
    $t = com8_devolucion([$total['pedido'], $parcial['pedido'], $sinDevolucion['pedido']], [$total['item'], $parcial['item'], $sinDevolucion['item']]);
    expect($t['paquetes'])->toBe(3)->and($t['devueltos'])->toBe(1)->and($t['porcentaje'])->toBe(33.3)->and($t['nivel'])->toBe('alerta');
});

test('DV-2 sin envios o sin pedidos no hay porcentaje (nunca 0 %)', function () {
    expect(com8_devolucion([], []))->toBe(['paquetes' => 0, 'devueltos' => 0, 'recibidos' => 0, 'por_recibir' => 0, 'porcentaje' => null, 'nivel' => 'sin_datos']);

    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido();
    expect(com8_devolucion([(int) $pedido->id], [(int) $item->id])['porcentaje'])->toBeNull();
});

test('DV-3 las franjas de color salen del 10 % y el 25 %', function () {
    $envios = array_map(fn () => com8_envio(2, null), range(1, 10));
    $devuelto = com8_envio(2, 2);
    $todos = [...$envios, $devuelto];
    // 1 de 11 = 9,1 % → ok
    expect(com8_devolucion(array_column($todos, 'pedido'), array_column($todos, 'item'))['nivel'])->toBe('ok');

    $dos = com8_envio(2, 2);
    $todos = [...$envios, $devuelto, $dos];
    // 2 de 12 = 16,7 % → aviso
    expect(com8_devolucion(array_column($todos, 'pedido'), array_column($todos, 'item'))['nivel'])->toBe('aviso');
});
