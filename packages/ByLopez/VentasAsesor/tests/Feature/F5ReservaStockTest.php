<?php

use ByLopez\Logistica\Services\Despachos\CancelarPendienteService;
use ByLopez\VentasAsesor\Services\Venta\ConfirmarVentaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Product\Helpers\Indexers\Inventory;
use Webkul\Product\Models\ProductOrderedInventory;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderItem;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Repositories\ShipmentRepository;

require_once __DIR__.'/../helpersFase03f.php';

uses(TestCase::class);

/*
| F5 (auditoria 2026-10-21; regla aprobada 2026-10-26): el pedido CONFIRMADO compromete sus unidades; los borradores siguen
| siendo informativos. Solo lpz_saas_testing (transaccion de la prueba). Cantidades esperadas calculadas a mano en cada caso.
*/

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();

    $this->asesor = f03b_admin();
    $this->cliente = f03b_cliente(f03b_grupo('F5'));
    $this->configurable = f03b_configurable(10000.0);
    $this->variante = $this->configurable->variants()->first();
    f03b_stock($this->variante, 50);
});

function f5_confirmar($test, int $cantidad): Order
{
    $borrador = f03f_borradorListo($test->cliente->id, $test->asesor, [[$test->configurable, $test->variante, $cantidad]]);
    $r = f03f_sinCommitPrematuro(fn () => app(ConfirmarVentaService::class)->confirmar($borrador->id, $test->asesor, f03f_total($borrador)));

    return Order::query()->findOrFail($r->orderId);
}

function f5_reservado($test): int
{
    return (int) ProductOrderedInventory::query()->where('product_id', $test->variante->id)->sum('qty');
}

function f5_fisico($test): int
{
    return (int) DB::table('product_inventories')->where('product_id', $test->variante->id)->sum('qty');
}

/** Despacho nativo (el mismo ShipmentRepository que usa DespachoService de Logistica). */
function f5_despachar(Order $pedido, int $cantidad): void
{
    $padre = $pedido->items()->whereNull('parent_id')->sole();
    $fuente = (int) DB::table('product_inventories')->where('product_id', $padre->children()->first()->product_id)->value('inventory_source_id');

    app(ShipmentRepository::class)->create(['order_id' => $pedido->id, 'shipment' => [
        'carrier_title' => 'F5', 'track_number' => 'F5-'.uniqid(), 'source' => $fuente,
        'items' => [$padre->id => [$fuente => $cantidad]],
    ]]);
}

test('F5-1 confirmar reserva; despacho parcial consume solo lo despachado (reserva y fisico, sin doble descuento) y conserva el pendiente', function () {
    $pedido = f5_confirmar($this, 30);

    // 50 fisicas; confirmar reserva 30 → vendible 20; el fisico no se toca.
    expect(f5_reservado($this))->toBe(30)->and(f5_fisico($this))->toBe(50);

    f5_despachar($pedido, 10);

    // Despachar 10: la reserva pasa a 20 (pendiente) y el fisico a 40. Vendible = 40 − 20 = 20, igual que antes (sin doble descuento).
    expect(f5_reservado($this))->toBe(20)->and(f5_fisico($this))->toBe(40);

    f5_despachar($pedido->fresh(), 20);

    expect(f5_reservado($this))->toBe(0)->and(f5_fisico($this))->toBe(20);
});

test('F5-2 cancelar el pendiente (servicio propio) libera solo la reserva de lo pendiente y NO toca el fisico; la cancelacion nativa queda bloqueada', function () {
    $otro = f5_confirmar($this, 5);
    $pedido = f5_confirmar($this, 30);
    expect(f5_reservado($this))->toBe(35);

    f5_despachar($pedido, 10);
    expect(f5_reservado($this))->toBe(25)->and(f5_fisico($this))->toBe(40);

    // HALLAZGO NATIVO (jornada 2026-10-27): OrderItemRepository::returnQtyToProductInventory devolveria al fisico lo YA despachado
    // (40 → 50). En un pedido autofacturado de ByLopez Bagisto ni siquiera permite cancelar (qty_to_cancel descuenta lo facturado):
    // devuelve false y no cambia nada. Si el pedido no estuviera facturado, el servidor corta la cancelacion antes de cambiar nada
    // (CAN-4 en Log15CancelarPendienteTest).
    expect(app(OrderRepository::class)->cancel($pedido->id))->toBeFalse()->and(f5_fisico($this))->toBe(40)->and(f5_reservado($this))->toBe(25);

    // Camino correcto: cancela las 20 pendientes de ESTE pedido (variante de configurable con su reserva); quedan las 5 del otro.
    $admin = f03b_admin('custom', ['logistica', 'logistica.despachos', 'logistica.despachos.cancelar_pendiente']);
    $c = app(CancelarPendienteService::class)->cancelar($admin, (int) $pedido->id, 'F5: cancelar lo pendiente');

    expect($c->unidades)->toBe(20)
        ->and(f5_reservado($this))->toBe(5)
        ->and(f5_fisico($this))->toBe(40)
        ->and($otro->fresh()->status)->not->toBe('canceled');
});

test('F5-3 transicion sin backfill: lo pendiente de un pedido ANTERIOR (sin reserva) cuenta como comprometido en el cerco', function () {
    $viejo = f5_confirmar($this, 30);
    // Pedido anterior a F5: hijo con qty_ordered 0 y sin reserva (asi quedaron en produccion).
    OrderItem::query()->where('order_id', $viejo->id)->whereNotNull('parent_id')->update(['qty_ordered' => 0]);
    ProductOrderedInventory::query()->where('product_id', $this->variante->id)->update(['qty' => 0]);
    // El indice de inventario de Bagisto (el que mira el carrito) se recalcula como tras cualquier cambio de stock.
    app(Inventory::class)->reindexBatch([$this->variante->fresh()]);

    // Bagisto ve 50 vendibles y deja armar la venta; el cerco descuenta las 30 pendientes del pedido anterior: 50 − 30 = 20 < 30.
    $borrador = f03f_borradorListo($this->cliente->id, $this->asesor, [[$this->configurable, $this->variante, 30]]);
    $error = f03f_error(fn () => f03f_sinCommitPrematuro(fn () => app(ConfirmarVentaService::class)->confirmar($borrador->id, $this->asesor, f03f_total($borrador))));

    // ConfirmarVentaService devuelve el rechazo del cerco como stock_insuficiente con fase «cerco».
    expect($error?->codigo)->toBe('stock_insuficiente')
        ->and($error->detalles['fase'] ?? null)->toBe('cerco')
        ->and($error->detalles['variantes'][0]['stock_vendible'] ?? null)->toBe(20)
        ->and(f5_reservado($this))->toBe(0);

    // 20 si caben.
    $borrador = f03f_borradorListo($this->cliente->id, $this->asesor, [[$this->configurable, $this->variante, 20]]);
    f03f_sinCommitPrematuro(fn () => app(ConfirmarVentaService::class)->confirmar($borrador->id, $this->asesor, f03f_total($borrador)));

    expect(f5_reservado($this))->toBe(20);
});

test('F5-4 despachar un pedido ANTERIOR (sin reserva) no consume las reservas de los pedidos nuevos; el fisico si baja', function () {
    $viejo = f5_confirmar($this, 10);
    OrderItem::query()->where('order_id', $viejo->id)->whereNotNull('parent_id')->update(['qty_ordered' => 0]);
    ProductOrderedInventory::query()->where('product_id', $this->variante->id)->update(['qty' => 0]);

    f5_confirmar($this, 15);
    expect(f5_reservado($this))->toBe(15);

    f5_despachar($viejo->fresh(), 10);

    // Sin la proteccion, Bagisto restaria 10 de las 15 reservadas por el pedido nuevo (quedarian 5).
    expect(f5_reservado($this))->toBe(15)->and(f5_fisico($this))->toBe(40);
});

test('F5-5 cancelar el pendiente de un pedido ANTERIOR (sin reserva) no resta las reservas de los pedidos nuevos y quita su compromiso legado', function () {
    $viejo = f5_confirmar($this, 10);
    OrderItem::query()->where('order_id', $viejo->id)->whereNotNull('parent_id')->update(['qty_ordered' => 0]);
    ProductOrderedInventory::query()->where('product_id', $this->variante->id)->update(['qty' => 0]);

    f5_confirmar($this, 15);
    expect(f5_reservado($this))->toBe(15);

    $admin = f03b_admin('custom', ['logistica', 'logistica.despachos', 'logistica.despachos.cancelar_pendiente']);
    $c = app(CancelarPendienteService::class)->cancelar($admin, (int) $viejo->id, 'F5: pedido anterior');

    // Se cancelan las 10 de la linea padre; la hija legada (cantidad 0) no tenia reserva, asi que la de los pedidos nuevos queda igual.
    $linea = OrderItem::query()->where('order_id', $viejo->id)->whereNull('parent_id')->sole();
    expect($c->unidades)->toBe(10)->and((int) $linea->qty_canceled)->toBe(10)
        ->and(f5_reservado($this))->toBe(15)->and(f5_fisico($this))->toBe(50)
        ->and((bool) DB::table('bylopez_lg_cancelacion_lineas')->where('cancelacion_id', $c->id)->value('libero_reserva'))->toBeFalse();

    // El cerco ya no cuenta esas 10 como compromiso legado: vendible = 50 − 15 reservadas = 35.
    $borrador = f03f_borradorListo($this->cliente->id, $this->asesor, [[$this->configurable, $this->variante, 35]]);
    f03f_sinCommitPrematuro(fn () => app(ConfirmarVentaService::class)->confirmar($borrador->id, $this->asesor, f03f_total($borrador)));

    expect(f5_reservado($this))->toBe(50);
});
