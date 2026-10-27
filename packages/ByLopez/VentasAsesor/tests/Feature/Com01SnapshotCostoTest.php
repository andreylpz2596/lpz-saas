<?php

use ByLopez\VentasAsesor\Enums\FuenteCostoVenta;
use ByLopez\VentasAsesor\Exceptions\CostoVentaInmutableException;
use ByLopez\VentasAsesor\Listeners\CapturarCostoAlCrearPedido;
use ByLopez\VentasAsesor\Models\CostoVenta;
use ByLopez\VentasAsesor\Services\Comisiones\CapturarCostoVentaService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderItem;

require_once __DIR__.'/../helpersCom.php';

uses(TestCase::class);

/*
| COM-01 — snapshot del costo al crear el pedido, por el flujo REAL: Nueva venta → ConfirmarVentaService →
| OrderRepository → checkout.order.save.after → CapturarCostoAlCrearPedido. Solo lpz_saas_testing.
*/

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();

    $this->asesor = f03b_admin();
    $this->cliente = f03b_cliente(f03b_grupo('C'));
    $this->configurable = f03b_configurable(10000.0);
    $this->variante = $this->configurable->variants()->first();
    f03b_stock($this->variante, 20);
});

function com01_vender($test): Order
{
    return com_vender($test->cliente->id, $test->asesor, $test->configurable, $test->variante);
}

test('COM01-1 configurable: un solo snapshot por la linea padre, con el costo COMISIONABLE (con autoridad) y el SKU de la VARIANTE real', function () {
    com_costo($this->configurable, 9999.0); // costo del padre: nunca debe usarse
    com_costo($this->variante, 5500.0);

    $pedido = com01_vender($this);
    $padre = OrderItem::query()->where('order_id', $pedido->id)->whereNull('parent_id')->sole();
    $hija = OrderItem::query()->where('parent_id', $padre->id)->sole();
    $snapshots = CostoVenta::query()->where('order_id', $pedido->id)->get();

    expect($padre->type)->toBe('configurable')
        ->and($snapshots)->toHaveCount(1)
        ->and($snapshots->first()->order_item_id)->toBe($padre->id)
        ->and($snapshots->first()->variante_order_item_id)->toBe($hija->id)
        ->and($snapshots->first()->product_id)->toBe($this->configurable->id)
        ->and($snapshots->first()->variant_product_id)->toBe($this->variante->id)
        ->and($snapshots->first()->sku_snapshot)->toBe($this->variante->sku)
        ->and((float) $snapshots->first()->costo_unitario)->toBe(5500.0)
        ->and($snapshots->first()->fuente)->toBe(FuenteCostoVenta::COST_BAGISTO_COMISIONABLE)
        ->and($snapshots->first()->autoridad_comision)->toBeTrue()
        ->and(CostoVenta::query()->where('order_item_id', $hija->id)->exists())->toBeFalse();
});

test('COM01-2 cambiar despues el cost del catalogo NO altera el snapshot; el modelo rechaza editarlo o borrarlo', function () {
    com_costo($this->variante, 5500.0);
    $pedido = com01_vender($this);

    com_costo($this->variante, 6100.0);
    app(CapturarCostoVentaService::class)->capturarPedido($pedido->id);

    $snapshot = CostoVenta::query()->where('order_id', $pedido->id)->sole();

    expect((float) $snapshot->costo_unitario)->toBe(5500.0)
        ->and(fn () => $snapshot->update(['costo_unitario' => 6100]))->toThrow(CostoVentaInmutableException::class)
        ->and(fn () => $snapshot->delete())->toThrow(CostoVentaInmutableException::class)
        ->and((float) CostoVenta::query()->whereKey($snapshot->id)->value('costo_unitario'))->toBe(5500.0);
});

test('COM01-3 sin costo: la venta se crea igual y el snapshot queda NULL / sin_costo / SIN autoridad, nunca 0', function () {
    com_costo($this->variante, null);

    $pedido = com01_vender($this);
    $snapshot = CostoVenta::query()->where('order_id', $pedido->id)->sole();

    expect($pedido->exists)->toBeTrue()
        ->and($snapshot->costo_unitario)->toBeNull()
        ->and($snapshot->fuente)->toBe(FuenteCostoVenta::SIN_COSTO)
        ->and($snapshot->fuente_referencia)->toBe('cost_ausente')
        ->and($snapshot->autoridad_comision)->toBeFalse();
});

test('COM01-4 reintento idempotente: servicio y evento repetidos no duplican ni modifican el snapshot', function () {
    com_costo($this->variante, 4900.0);
    $pedido = com01_vender($this);
    $original = CostoVenta::query()->where('order_id', $pedido->id)->sole();

    $r = app(CapturarCostoVentaService::class)->capturarPedido($pedido->id);
    app(CapturarCostoAlCrearPedido::class)->handle($pedido);

    $despues = CostoVenta::query()->where('order_id', $pedido->id)->get();

    expect($r->esNoOp())->toBeTrue()
        ->and($r->existentes)->toBe(1)
        ->and($despues)->toHaveCount(1)
        ->and($despues->first()->id)->toBe($original->id)
        ->and($despues->first()->updated_at->equalTo($original->updated_at))->toBeTrue();
});
