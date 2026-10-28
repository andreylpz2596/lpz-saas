<?php

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Cancelacion;
use ByLopez\Logistica\Services\Despachos\CancelarPendienteService;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarPagoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\Sales\Repositories\OrderRepository;

require_once __DIR__.'/../helpersDespacho.php';
require_once __DIR__.'/../../../VentasAsesor/tests/helpersPagB.php';

uses(TestCase::class);

/*
| Cancelacion de lo PENDIENTE de despacho. Pedido de 10 unidades: despachar 6, cancelar las 4 pendientes. La reserva de las 4 se libera,
| el stock fisico NO aumenta, las 6 conservan su envio y Cartera deja de exigir las 4 (sin Refund). La cancelacion nativa de Bagisto,
| que reintegraria al fisico lo ya enviado, queda bloqueada en el servidor. Solo lpz_saas_testing (transaccion de la prueba).
| Cantidades e importes esperados calculados a mano; la reserva se siembra como la deja la confirmacion de una venta (F5 la prueba).
*/

beforeEach(function () {
    pagb_corte('2000-01-01');
});

function can_admin()
{
    return f03b_admin('custom', array_merge(LOG2_PERMISOS_BODEGA, ['logistica.despachos.cancelar_pendiente']));
}

/** Pedido contra entrega de 10 unidades a 10.000 (linea 100.000) con 10 reservadas y 50 fisicas. */
function can_pedido(): array
{
    $e = log2_pedido(10, ['pago' => 'cashondelivery', 'total' => 100000.0, 'stock' => 50]);
    $e['item']->update(['price' => 10000, 'base_price' => 10000, 'total' => 100000, 'base_total' => 100000, 'base_discount_amount' => 0, 'base_tax_amount' => 0]);
    DB::table('product_ordered_inventories')->updateOrInsert(
        ['product_id' => $e['item']->product_id, 'channel_id' => $e['pedido']->channel_id],
        ['qty' => 10],
    );

    return $e;
}

function can_reservado(array $e): int
{
    return (int) DB::table('product_ordered_inventories')->where('product_id', $e['item']->product_id)->where('channel_id', $e['pedido']->channel_id)->sum('qty');
}

function can_fisico(array $e): int
{
    return (int) DB::table('product_inventories')->where('product_id', $e['item']->product_id)->sum('qty');
}

test('CAN-1 10 unidades: despachar 6 y cancelar las 4 pendientes libera la reserva de las 4 y no aumenta el stock fisico', function () {
    $e = can_pedido();
    $id = (int) $e['pedido']->id;

    app(DespachoService::class)->despachar(log2_bodega(), log2_s($e, ['unidades' => [$e['item']->id => 6]]));

    // El despacho consume lo enviado: fisico 50 → 44; reserva 10 → 4 (lo pendiente).
    expect(can_fisico($e))->toBe(44)->and(can_reservado($e))->toBe(4);

    $c = app(CancelarPendienteService::class)->cancelar(can_admin(), $id, 'El cliente ya no quiere las 4 restantes');

    expect($c->unidades)->toBe(4)
        ->and((float) $c->valor_cancelado)->toBe(40000.0)
        // Reserva liberada; fisico intacto; las 6 enviadas conservan su trazabilidad.
        ->and(can_reservado($e))->toBe(0)->and(can_fisico($e))->toBe(44)
        ->and((int) $e['item']->fresh()->qty_canceled)->toBe(4)->and((int) $e['item']->fresh()->qty_shipped)->toBe(6)
        ->and(DB::table('shipments')->where('order_id', $id)->count())->toBe(1)
        ->and(DB::table('shipment_items')->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')->where('shipments.order_id', $id)->sum('shipment_items.qty') + 0)->toBe(6)
        // Ninguna unidad queda cancelada Y despachada a la vez: enviado + cancelado = pedido.
        ->and((int) $e['item']->fresh()->qty_shipped + (int) $e['item']->fresh()->qty_canceled)->toBe((int) $e['item']->fresh()->qty_ordered)
        // Sin Refund ni cambio al total del pedido.
        ->and(DB::table('refunds')->where('order_id', $id)->count())->toBe(0)
        ->and((float) $e['pedido']->fresh()->base_grand_total)->toBe(100000.0);

    // Historial con actor, motivo y lineas.
    $linea = DB::table('bylopez_lg_cancelacion_lineas')->where('cancelacion_id', $c->id)->first();
    expect((int) $linea->order_item_id)->toBe((int) $e['item']->id)->and((int) $linea->cantidad)->toBe(4)->and((int) $linea->libero_reserva)->toBe(1)
        ->and($c->motivo)->toBe('El cliente ya no quiere las 4 restantes');
});

test('CAN-2 repetir la cancelacion no libera ni ajusta dos veces; el historial es inmutable', function () {
    $e = can_pedido();
    $id = (int) $e['pedido']->id;
    app(DespachoService::class)->despachar(log2_bodega(), log2_s($e, ['unidades' => [$e['item']->id => 6]]));
    $c = app(CancelarPendienteService::class)->cancelar(can_admin(), $id, 'Pedido reducido por el cliente');

    expect(log2_error(fn () => app(CancelarPendienteService::class)->cancelar(can_admin(), $id, 'Doble clic')))->toBe(Invalida::CANCELACION_SIN_PENDIENTE)
        ->and(Cancelacion::query()->where('order_id', $id)->count())->toBe(1)
        ->and(can_reservado($e))->toBe(0)->and(can_fisico($e))->toBe(44)
        ->and((int) $e['item']->fresh()->qty_canceled)->toBe(4)
        ->and(log2_error(fn () => $c->update(['motivo' => 'otro'])))->toBe(Invalida::REGISTRO_INMUTABLE)
        ->and(log2_error(fn () => $c->delete()))->toBe(Invalida::REGISTRO_INMUTABLE);
});

test('CAN-3 permiso y motivo se exigen en el servidor; sin ellos no cambia nada', function () {
    $e = can_pedido();
    $id = (int) $e['pedido']->id;
    $servicio = app(CancelarPendienteService::class);

    expect(log2_error(fn () => $servicio->cancelar(log2_bodega(), $id, 'Bodega no puede cancelar')))->toBe(Invalida::SIN_PERMISO)
        ->and(log2_error(fn () => $servicio->cancelar(log2_asesor(), $id, 'Asesora no puede cancelar')))->toBe(Invalida::SIN_PERMISO)
        ->and(log2_error(fn () => $servicio->cancelar(can_admin(), $id, '  ')))->toBe(Invalida::MOTIVO_CANCELACION_REQUERIDO)
        ->and(log2_error(fn () => $servicio->cancelar(can_admin(), $id, 'no')))->toBe(Invalida::MOTIVO_CANCELACION_REQUERIDO)
        ->and(can_reservado($e))->toBe(10)->and((int) $e['item']->fresh()->qty_canceled)->toBe(0)
        ->and(Cancelacion::query()->where('order_id', $id)->count())->toBe(0);

    // HTTP: el Bouncer de Bagisto corta la ruta sin la clave (401) antes de llegar al servicio.
    $url = route('admin.logistica.despachos.cancelar_pendiente', $id);
    $this->actingAs(log2_bodega(), 'admin')->postJson($url, ['motivo' => 'Intento de bodega'])->assertStatus(401);
    $this->actingAs(can_admin(), 'admin')->postJson($url, ['motivo' => ''])->assertStatus(422)->assertJsonPath('codigo', Invalida::MOTIVO_CANCELACION_REQUERIDO);
    expect(can_reservado($e))->toBe(10);

    $this->actingAs(can_admin(), 'admin')->postJson($url, ['motivo' => 'Cliente desiste del pedido'])
        ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('unidades', 10)->assertJsonPath('situacion.pendientes', 0);
    expect(can_reservado($e))->toBe(0)->and(can_fisico($e))->toBe(50);
});

test('CAN-4 cancelacion nativa con unidades despachadas: se detiene antes de cambiar nada (servicio y endpoint)', function () {
    $e = can_pedido();
    app(DespachoService::class)->despachar(log2_bodega(), log2_s($e, ['unidades' => [$e['item']->id => 6]]));
    $fisico = can_fisico($e);
    $reserva = can_reservado($e);

    $codigo = null;

    try {
        app(OrderRepository::class)->cancel($e['pedido']->id, true);
    } catch (Invalida $x) {
        $codigo = $x->codigo;
    }

    expect($codigo)->toBe('cancelacion_nativa_bloqueada')
        ->and(can_fisico($e))->toBe($fisico)->and(can_reservado($e))->toBe($reserva)
        ->and((int) $e['item']->fresh()->qty_canceled)->toBe(0);

    // El endpoint nativo del panel vuelve a la ficha con el aviso, sin tocar inventario.
    $this->actingAs(f03b_admin('all'), 'admin')->from(route('admin.sales.orders.view', $e['pedido']->id))
        ->post(route('admin.sales.orders.cancel', $e['pedido']->id))
        ->assertRedirect()->assertSessionHas('error');
    expect(can_fisico($e))->toBe($fisico)->and((int) $e['item']->fresh()->qty_canceled)->toBe(0);
});

test('CAN-5 sin despachos, la cancelacion nativa conserva su comportamiento (libera reserva, sin fisico que reintegrar)', function () {
    $e = can_pedido();

    expect(app(OrderRepository::class)->cancel($e['pedido']->id, true))->not->toBeFalse()
        ->and(can_reservado($e))->toBe(0)->and(can_fisico($e))->toBe(50);
});

test('CAN-6 Cartera deja de exigir lo cancelado, aparte de lo devuelto, sin Refund; con pago aplicado queda saldo a favor identificado', function () {
    $e = can_pedido();
    $id = (int) $e['pedido']->id;
    $cartera = fn () => app(CarteraConsultaService::class)->saldosDePedidos([$id])[$id];

    app(DespachoService::class)->despachar(log2_bodega(), log2_s($e, ['unidades' => [$e['item']->id => 6]]));
    expect($cartera()->saldo)->toBe(100000.0);

    // El cliente reporta 70.000 antes de cancelar (el sistema no admite pagar mas que el saldo, asi que se paga antes).
    app(RegistrarPagoService::class)->registrar(f03b_admin('all'), $id, [
        'monto' => '70000', 'medio_cobro' => 'efectivo', 'fecha_pago' => now()->toDateString(),
    ], 'can-pago-'.Str::uuid());
    expect($cartera()->saldo)->toBe(30000.0);

    app(CancelarPendienteService::class)->cancelar(can_admin(), $id, 'Cancelacion parcial pedida por el cliente');
    $s = $cartera();

    // Total 100.000 − 4 × 10.000 cancelado = 60.000 exigible; el total original no se toca. Pagado 70.000 → 10.000 a favor, sin reembolso.
    expect($s->total)->toBe(100000.0)->and($s->cancelado)->toBe(40000.0)->and($s->devuelto)->toBe(0.0)
        ->and($s->totalOperativo)->toBe(60000.0)->and($s->saldo)->toBe(0.0)->and($s->saldoAFavor)->toBe(10000.0)
        ->and(DB::table('refunds')->where('order_id', $id)->count())->toBe(0);
});

test('CAN-7 cancelar TODO lo pendiente con dinero ya aplicado se bloquea (devolver dinero es una decision humana)', function () {
    $e = can_pedido();
    $id = (int) $e['pedido']->id;
    app(RegistrarPagoService::class)->registrar(f03b_admin('all'), $id, [
        'monto' => '30000', 'medio_cobro' => 'efectivo', 'fecha_pago' => now()->toDateString(),
    ], 'can-pago-'.Str::uuid());

    expect(log2_error(fn () => app(CancelarPendienteService::class)->cancelar(can_admin(), $id, 'Cancelar todo con pago')))->toBe(Invalida::CANCELACION_CON_PAGOS)
        ->and(can_reservado($e))->toBe(10)->and((int) $e['item']->fresh()->qty_canceled)->toBe(0)
        ->and(Cancelacion::query()->where('order_id', $id)->count())->toBe(0);

    // Con 6 ya enviados, cancelar las 4 restantes SI procede aunque haya dinero aplicado: el pedido no queda sin unidades.
    $otro = can_pedido();
    app(DespachoService::class)->despachar(log2_bodega(), log2_s($otro, ['unidades' => [$otro['item']->id => 6]]));
    app(RegistrarPagoService::class)->registrar(f03b_admin('all'), (int) $otro['pedido']->id, [
        'monto' => '30000', 'medio_cobro' => 'efectivo', 'fecha_pago' => now()->toDateString(),
    ], 'can-pago-'.Str::uuid());

    expect(app(CancelarPendienteService::class)->cancelar(can_admin(), (int) $otro['pedido']->id, 'Cancela las 4 que faltan')->unidades)->toBe(4);
});

test('CAN-8 despues de cancelar el pendiente ya no hay nada por despachar', function () {
    $e = can_pedido();
    $id = (int) $e['pedido']->id;
    app(DespachoService::class)->despachar(log2_bodega(), log2_s($e, ['unidades' => [$e['item']->id => 6]]));
    app(CancelarPendienteService::class)->cancelar(can_admin(), $id, 'Pedido reducido');

    expect(log2_error(fn () => app(DespachoService::class)->despachar(log2_bodega(), log2_s($e))))->not->toBeNull()
        ->and((int) $e['item']->fresh()->qty_shipped)->toBe(6)->and(can_fisico($e))->toBe(44);
});
