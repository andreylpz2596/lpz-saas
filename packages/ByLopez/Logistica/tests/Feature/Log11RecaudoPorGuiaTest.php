<?php

use ByLopez\Logistica\Contracts\PagosPendientesDeConfirmar;
use ByLopez\Logistica\DataObjects\SolicitudEventoEnvio;
use ByLopez\Logistica\Enums\FuenteEventoLogistico as Fuente;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Models\MovimientoCuenta;
use ByLopez\Logistica\Models\Transportadora;
use ByLopez\Logistica\Services\Cuentas\LiquidarCuentaService;
use ByLopez\Logistica\Services\Despachos\ContextoDespachoService;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use ByLopez\Logistica\Services\Entregas\RegistrarEntregaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\Sales\Models\OrderItem;

require_once __DIR__.'/../helpersDespacho.php';
require_once __DIR__.'/../helpersDomicilios.php';

uses(TestCase::class);

/*
| C1 (jornada de pruebas 2026-10-21): en un despacho PARCIAL cada guia heredaba el total del pedido como recaudo esperado. Ahora
| cada guia propone el valor historico de SUS unidades (+ el domicilio del pedido, una sola vez, en la primera guia), acotado a
| lo que aun no comprometen las otras guias; apartarse de lo propuesto exige motivo y la suma de las guias nunca supera el saldo
| exigible. Solo lpz_saas_testing, datos de fixture.
|
| Pedido de prueba (contra entrega, domicilio local Bogota; precios HISTORICOS guardados en las lineas):
|   L1: 4 u. · 10.000 − 2.000 descuento     = 38.000  (9.500 c/u)
|   L2: 6 u. ·  5.000 + 3.000 impuesto      = 33.000  (5.500 c/u)
|   L3: 2 u. · 14.500                       = 29.000  (14.500 c/u)
|   mercancia 100.000 + domicilio cobrado al cliente 12.000 = total 112.000
*/

const LOG11_ADMIN = ['logistica', 'logistica.cuentas', 'logistica.cuentas.liquidar', 'logistica.entregas', 'logistica.entregas.registrar'];

/** @return array{pedido: mixed, l1: int, l2: int, l3: int, jeison: Transportadora} */
function log11_pedido(float $pagadoAntes = 0.0): array
{
    app()->instance(PagosPendientesDeConfirmar::class, new class implements PagosPendientesDeConfirmar
    {
        public function estado(int $orderId): array
        {
            return ['sin_revisar' => 0, 'saldo' => null];
        }
    });
    log6_municipio('11001');
    $c = log6_catalogo();

    $e = log2_pedido(4, ['envio' => 'domicilio_local', 'pago' => 'cashondelivery', 'total' => 112000.0, 'stock' => 60]);
    $linea = fn (int $qty, float $precio, float $descuento = 0, float $impuesto = 0) => [
        'qty_ordered' => $qty, 'qty_shipped' => 0, 'qty_canceled' => 0, 'qty_refunded' => 0,
        'price' => $precio, 'base_price' => $precio, 'total' => $precio * $qty, 'base_total' => $precio * $qty,
        'discount_amount' => $descuento, 'base_discount_amount' => $descuento, 'tax_amount' => $impuesto, 'base_tax_amount' => $impuesto,
    ];
    $e['item']->update($linea(4, 10000, 2000));
    $nueva = fn (string $n, array $d) => (int) OrderItem::factory()->create($d + [
        'product_id' => $e['item']->product_id, 'order_id' => $e['pedido']->id, 'sku' => $e['item']->sku,
        'type' => $e['item']->type, 'name' => $e['item']->name.' '.$n, 'additional' => ['locale' => 'es'],
    ])->id;
    $l2 = $nueva('L2', $linea(6, 5000, 0, 3000));
    $l3 = $nueva('L3', $linea(2, 14500));

    if ($pagadoAntes > 0) {
        DB::table('order_transactions')->insert([
            'transaction_id' => 'c1-'.Str::uuid(), 'payment_method' => 'transferencia', 'amount' => $pagadoAntes,
            'order_id' => $e['pedido']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return ['pedido' => $e['pedido'], 'l1' => (int) $e['item']->id, 'l2' => $l2, 'l3' => $l3, 'jeison' => $c['jeison']];
}

/** Despacho parcial de `unidades` (order_item_id => cantidad) con la transportadora; `extra` = recaudoEsperado, motivoRecaudo, idempotencyKey... */
function log11_despachar(array $p, array $unidades, array $extra = []): Guia
{
    return app(DespachoService::class)->despachar(log2_bodega(), log2_s(['pedido' => $p['pedido']], $extra + ['transportadora' => $p['jeison']->codigo, 'fletePagador' => '', 'unidades' => $unidades]))->guia;
}

function log11_entregar_todo(Guia $guia, ?string $clave = null): void
{
    $items = DB::table('shipment_items')->where('shipment_id', $guia->shipment_id)->whereNotNull('order_item_id')->where('qty', '>', 0)->pluck('qty', 'id')->map(fn ($q) => (int) $q)->all();

    app(RegistrarEntregaService::class)->registrar(f03b_admin('custom', LOG11_ADMIN), new SolicitudEventoEnvio(
        shipmentId: (int) $guia->shipment_id, orderId: (int) $guia->order_id, fecha: now()->toDateString(), items: $items,
        fuente: Fuente::SUPERVISOR_MANUAL, idempotencyKey: $clave ?? 'c1-'.Str::uuid(),
    ));
}

test('C1-01 despacho parcial: cada guia cobra SUS unidades a precio historico, el domicilio una vez, respeta pagos previos y la suma nunca supera el saldo', function () {
    // ---- (a) sin pagos previos: dos guias que reparten el pedido; la suma es EXACTAMENTE el total (antes: 112.000 + 112.000) ----
    $p = log11_pedido();
    $ctx = fn () => app(ContextoDespachoService::class)->paraPedido((int) $p['pedido']->id, log2_bodega())['recaudo_guia'];

    expect($ctx())->toMatchArray(['contra_entrega' => true, 'exigible' => 112000.0, 'comprometido' => 0.0, 'disponible' => 112000.0, 'hay_previas' => false, 'flete' => 12000.0]);

    // Guia 1: L1 x4 (38.000) + L2 x2 (11.000) = 49.000 de mercancia (descuento e impuesto de la linea incluidos) + 12.000 de domicilio.
    $g1 = log11_despachar($p, [$p['l1'] => 4, $p['l2'] => 2]);

    expect((float) $g1->recaudo_esperado)->toBe(61000.0)
        ->and((float) $g1->recaudo_sugerido)->toBe(61000.0)
        ->and($g1->motivo_recaudo)->toBeNull()
        ->and($g1->recaudo_estado->value)->toBe('pendiente_liquidar');

    // El contexto de la segunda guia ya ve lo comprometido y NO vuelve a cobrar el domicilio.
    expect($ctx())->toMatchArray(['comprometido' => 61000.0, 'disponible' => 51000.0, 'hay_previas' => true, 'flete' => 0.0]);

    // Guia 2 (completa el pedido): L2 x4 (22.000) + L3 x2 (29.000) = 51.000, sin domicilio.
    $g2 = log11_despachar($p, [$p['l2'] => 4, $p['l3'] => 2]);

    expect((float) $g2->recaudo_esperado)->toBe(51000.0)
        ->and((float) $g2->recaudo_sugerido)->toBe(51000.0)
        ->and((float) Guia::query()->where('order_id', $p['pedido']->id)->sum('recaudo_esperado'))->toBe(112000.0);

    // Cuenta corriente: cada entrega aporta SU recaudo (+61.000, +51.000) y el servicio de cada viaje (−10.000); sin diferencia fantasma.
    log11_entregar_todo($g1);
    log11_entregar_todo($g2);
    $ids = MovimientoCuenta::query()->where('transportadora_id', $p['jeison']->id)->where('estado', 'abierto')->pluck('id')->all();
    $recaudos = MovimientoCuenta::query()->whereIn('id', $ids)->where('concepto', 'recaudo')->orderBy('id')->pluck('importe')->map(fn ($v) => (float) $v)->all();

    expect($recaudos)->toBe([61000.0, 51000.0]);

    $liq = app(LiquidarCuentaService::class)->liquidar(f03b_admin('custom', LOG11_ADMIN), (int) $p['jeison']->id, ['movimientos' => $ids, 'valor' => '92000', 'idempotency_key' => 'liq-'.Str::uuid()]);

    expect((float) $liq->saldo_esperado)->toBe(92000.0)   // 112.000 de recaudo − 2 × 10.000 de servicio
        ->and((float) $liq->diferencia)->toBe(0.0);

    // ---- (b) con 40.000 ya pagados: solo quedan 72.000 exigibles; la mercancia despachada vale mas, pero la suma se acota ----
    $q = log11_pedido(40000.0);
    $ctxQ = fn () => app(ContextoDespachoService::class)->paraPedido((int) $q['pedido']->id, log2_bodega())['recaudo_guia'];

    expect($ctxQ())->toMatchArray(['exigible' => 72000.0, 'disponible' => 72000.0]);

    $h1 = log11_despachar($q, [$q['l1'] => 4, $q['l2'] => 2]);   // 49.000 + 12.000 = 61.000 ≤ 72.000
    $h2 = log11_despachar($q, [$q['l2'] => 4, $q['l3'] => 2]);   // vale 51.000, pero solo quedan 72.000 − 61.000 = 11.000

    expect((float) $h1->recaudo_esperado)->toBe(61000.0)
        ->and((float) $h2->recaudo_sugerido)->toBe(11000.0)
        ->and((float) $h2->recaudo_esperado)->toBe(11000.0)
        ->and((float) Guia::query()->where('order_id', $q['pedido']->id)->sum('recaudo_esperado'))->toBe(72000.0);

    // ---- (c) tres guias: la segunda NO repite el domicilio aunque haya saldo de sobra (el tope no lo oculta) ----
    $t = log11_pedido();
    $i1 = log11_despachar($t, [$t['l1'] => 4]);                        // 38.000 + 12.000 de domicilio
    $i2 = log11_despachar($t, [$t['l2'] => 2]);                        // 11.000: con 62.000 disponibles, sin domicilio
    $i3 = log11_despachar($t, [$t['l2'] => 4, $t['l3'] => 2]);          // 22.000 + 29.000 = 51.000 = lo que queda

    expect([(float) $i1->recaudo_esperado, (float) $i2->recaudo_esperado, (float) $i3->recaudo_esperado])->toBe([50000.0, 11000.0, 51000.0])
        ->and((float) Guia::query()->where('order_id', $t['pedido']->id)->sum('recaudo_esperado'))->toBe(112000.0);

    // Despacho unico (el caso normal): sigue proponiendo TODO el saldo exigible, como antes de C1.
    $u = log11_pedido(40000.0);
    $unica = log11_despachar($u, []);

    expect((float) $unica->recaudo_esperado)->toBe(72000.0)
        ->and((float) $unica->recaudo_sugerido)->toBe(72000.0);
});

test('C1-02 modificar el recaudo exige motivo, nunca permite superar el saldo, deja trazabilidad y un reintento no duplica nada', function () {
    $p = log11_pedido();
    $pedido = (int) $p['pedido']->id;
    $primera = [$p['l1'] => 4, $p['l2'] => 2];   // el sistema propone 61.000

    // Un valor distinto al propuesto SIN motivo: el servidor lo rechaza y no crea nada (ni guia ni Shipment).
    $envios = DB::table('shipments')->where('order_id', $pedido)->count();

    expect(log2_error(fn () => log11_despachar($p, $primera, ['recaudoEsperado' => '70000'])))->toBe(Invalida::MOTIVO_RECAUDO_REQUERIDO)
        ->and(Guia::query()->where('order_id', $pedido)->count())->toBe(0)
        ->and(DB::table('shipments')->where('order_id', $pedido)->count())->toBe($envios);

    // Con motivo se registra el valor real; queda lo propuesto, lo registrado, el motivo, el actor, el pedido, el Shipment y la guia.
    $solicitud = log2_s(['pedido' => $p['pedido']], ['transportadora' => $p['jeison']->codigo, 'fletePagador' => '', 'unidades' => $primera, 'recaudoEsperado' => '70000', 'motivoRecaudo' => 'El cliente pidió que cobren el empaque especial']);
    $bodega = log2_bodega();
    $g1 = app(DespachoService::class)->despachar($bodega, $solicitud)->guia;

    expect((float) $g1->recaudo_sugerido)->toBe(61000.0)
        ->and((float) $g1->recaudo_esperado)->toBe(70000.0)
        ->and($g1->motivo_recaudo)->toBe('El cliente pidió que cobren el empaque especial')
        ->and($g1->actor_admin_id)->toBe((int) $bodega->id)
        ->and((int) $g1->order_id)->toBe($pedido)
        ->and($g1->shipment_id)->not->toBeNull()
        ->and($g1->transportadora_codigo)->toBe($p['jeison']->codigo);

    // Reintento (mismo clic, misma clave): la misma guia; no se crean otra guia, otro Shipment ni dinero.
    $repetida = app(DespachoService::class)->despachar($bodega, $solicitud);

    expect($repetida->yaExistia)->toBeTrue()
        ->and((int) $repetida->guia->id)->toBe((int) $g1->id)
        ->and(Guia::query()->where('order_id', $pedido)->count())->toBe(1)
        ->and(DB::table('shipments')->where('order_id', $pedido)->count())->toBe($envios + 1);

    // Doble clic en «Registrar entrega» (misma clave): una sola entrega.
    log11_entregar_todo($g1, 'c1-entrega-g1');
    log11_entregar_todo($g1, 'c1-entrega-g1');

    expect(MovimientoCuenta::query()->where('shipment_id', $g1->shipment_id)->where('concepto', 'recaudo')->pluck('importe')->map(fn ($v) => (float) $v)->all())->toBe([70000.0]);

    // Segunda guia: ya hay 70.000 comprometidos de 112.000 → el sistema propone lo que queda (42.000, no los 51.000 de su mercancia).
    $segunda = [$p['l2'] => 4, $p['l3'] => 2];
    $disponible = app(ContextoDespachoService::class)->paraPedido($pedido, $bodega)['recaudo_guia']['disponible'];

    expect($disponible)->toBe(42000.0);

    // Superar el saldo exigible, aun con motivo, se rechaza en el servidor (la suma de las guias nunca pasa de 112.000).
    $error = null;

    try {
        log11_despachar($p, $segunda, ['recaudoEsperado' => '50000', 'motivoRecaudo' => 'Cobrar todo lo de la mercancía']);
    } catch (Invalida $e) {
        $error = $e;
    }

    expect($error?->codigo)->toBe(Invalida::RECAUDO_SUPERA_SALDO)
        ->and($error->detalles['maximo'])->toBe(42000.0)
        ->and(Guia::query()->where('order_id', $pedido)->count())->toBe(1);

    // Aceptar exactamente lo propuesto no pide motivo; la suma queda en el saldo exigible, ni un peso mas.
    $g2 = log11_despachar($p, $segunda, ['recaudoEsperado' => '42000']);

    expect((float) $g2->recaudo_esperado)->toBe(42000.0)
        ->and((float) $g2->recaudo_sugerido)->toBe(42000.0)
        ->and($g2->motivo_recaudo)->toBeNull()
        ->and((float) Guia::query()->where('order_id', $pedido)->sum('recaudo_esperado'))->toBe(112000.0);
});
