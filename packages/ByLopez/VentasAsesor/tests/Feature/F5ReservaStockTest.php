<?php

use ByLopez\VentasAsesor\Services\Venta\ConfirmarVentaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Core\Core;
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

test('F5-2 cancelar el pendiente libera solo la reserva de lo pendiente (pedido sin factura, unico caso que Bagisto deja cancelar)', function () {
    // La cancelacion nativa exige qty_to_cancel > 0 (ordenado − cancelado − FACTURADO). Con la factura automatica de los metodos de
    // ByLopez no hay cancelacion posible; aqui se apaga solo para la prueba.
    DB::table('core_config')->where('code', 'like', 'sales.payment_methods.%.generate_invoice')->update(['value' => '0']);
    app(Core::class)->getConfigData('sales.payment_methods.moneytransfer.generate_invoice'); // sin cache previa

    $otro = f5_confirmar($this, 5);
    $pedido = f5_confirmar($this, 30);
    expect(DB::table('invoices')->where('order_id', $pedido->id)->count())->toBe(0)
        ->and(f5_reservado($this))->toBe(35);

    f5_despachar($pedido, 10);
    expect(f5_reservado($this))->toBe(25)->and(f5_fisico($this))->toBe(40);

    expect(app(OrderRepository::class)->cancel($pedido->id))->not->toBeFalse();

    // Se libera lo pendiente de ESTE pedido (20); quedan las 5 del otro. El fisico se registra tal cual lo deja Bagisto (ver nota).
    expect(f5_reservado($this))->toBe(5)
        ->and($otro->fresh()->status)->not->toBe('canceled');
    // HALLAZGO NATIVO (no se cambia aqui): OrderItemRepository::returnQtyToProductInventory devuelve al fisico lo YA despachado
    // (10) al cancelar: 40 → 50 aunque esas prendas salieron. Inalcanzable hoy en ByLopez (factura automatica = sin cancelacion).
    expect(f5_fisico($this))->toBe(50);
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
