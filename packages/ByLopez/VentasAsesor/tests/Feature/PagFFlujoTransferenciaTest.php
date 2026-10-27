<?php

use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Services\Cartera\ConfirmarPagoService;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarPagoService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\Payment\Listeners\GenerateInvoice;
use Webkul\Sales\Models\Order;

require_once __DIR__.'/../helpersPagB.php';

uses(TestCase::class);

/*
| PAG-F · flujo operativo de extremo a extremo con la configuracion objetivo de `moneytransfer` (Doc. 05 §42.9):
| generate_invoice = 1, invoice_status = pending, order_status = pending_payment. La factura la genera el listener REAL
| de Bagisto (`checkout.order.save.after` → GenerateInvoice), el mismo que corre al confirmar una venta. Todo dentro de
| la transaccion de la prueba (lpz_saas_testing).
*/

beforeEach(function () {
    pagb_corte('2000-01-01');

    foreach ([
        'moneytransfer' => ['1', 'pending', 'pending_payment'],
        'cashondelivery' => ['1', 'pending', 'pending_payment'],
    ] as $metodo => [$generar, $factura, $pedido]) {
        foreach (['active' => '1', 'generate_invoice' => $generar, 'invoice_status' => $factura, 'order_status' => $pedido] as $campo => $valor) {
            DB::table('core_config')->updateOrInsert(
                ['code' => "sales.payment_methods.$metodo.$campo", 'channel_code' => 'default'],
                ['value' => $valor, 'locale_code' => null, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }
});

/** Pedido con linea, direccion de facturacion y metodo de pago; la factura la crea el listener real de Bagisto. */
function pagf_pedido_con_factura_automatica(string $metodo, float $total): Order
{
    $pedido = pagb_pedido(pagb_cliente(), $total, ['status' => 'pending', 'grand_total_invoiced' => 0, 'base_grand_total_invoiced' => 0, 'sub_total_invoiced' => 0, 'base_sub_total_invoiced' => 0]);

    DB::table('order_payment')->insert(['order_id' => $pedido->id, 'method' => $metodo, 'method_title' => $metodo, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('addresses')->insert([
        'address_type' => 'order_billing', 'order_id' => $pedido->id, 'first_name' => 'Cliente', 'last_name' => 'PAGF',
        'address' => 'Calle 1', 'city' => 'Cali', 'country' => 'CO', 'phone' => '3000000000', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('order_items')->insert([
        'order_id' => $pedido->id, 'sku' => 'PAGF-'.$pedido->id, 'type' => 'simple', 'name' => 'Prenda',
        'qty_ordered' => 2, 'qty_shipped' => 0, 'qty_invoiced' => 0, 'qty_canceled' => 0, 'qty_refunded' => 0,
        'price' => $total / 2, 'base_price' => $total / 2, 'total' => $total, 'base_total' => $total,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Lo que hace Bagisto al guardar el pedido (checkout.order.save.after).
    app(GenerateInvoice::class)->handle(Order::query()->with(['payment', 'items'])->findOrFail($pedido->id));

    return $pedido->fresh();
}

test('transferencia con la configuracion objetivo: pedido pending_payment + factura pending + 0 Transactions; registrar baja el saldo; confirmar crea la Transaction, paga la factura y NO vuelve a bajar el saldo', function () {
    $pedido = pagf_pedido_con_factura_automatica('moneytransfer', 120000);
    $factura = DB::table('invoices')->where('order_id', $pedido->id)->get();

    // 1-4) Recien creado: esperando pago, factura abierta y ningun dinero en Bagisto.
    expect($pedido->status)->toBe('pending_payment')
        ->and($factura)->toHaveCount(1)
        ->and($factura[0]->state)->toBe('pending')
        ->and(DB::table('order_transactions')->where('order_id', $pedido->id)->count())->toBe(0);

    $consulta = app(CarteraConsultaService::class);

    expect($consulta->saldosDePedidos([$pedido->id])[$pedido->id]->saldo)->toBe(120000.0)
        ->and($consulta->saldosDePedidos([$pedido->id])[$pedido->id]->requiereRevision)->toBeFalse();   // ya no es «factura pagada sin evidencia»

    // 5-7) El asesor registra el pago: sin revisar y el saldo comercial baja de inmediato.
    $actor = f03b_admin('all');
    $pagoId = app(RegistrarPagoService::class)->registrar($actor, $pedido->id, [
        'monto' => '120000', 'medio_cobro' => 'transferencia', 'plataforma' => 'nequi', 'fecha_pago' => now()->toDateString(), 'referencia' => 'TRX-PAGF',
    ], 'clave-pagf-e2e-000001')->pagoId;

    $registrado = $consulta->saldosDePedidos([$pedido->id])[$pedido->id];

    expect(Pago::query()->findOrFail($pagoId)->estado)->toBe(EstadoPago::SIN_REVISAR)
        ->and($registrado->saldo)->toBe(0.0)->and($registrado->sinRevisar)->toBe(120000.0)->and($registrado->verificado)->toBe(0.0)
        ->and(DB::table('order_transactions')->where('order_id', $pedido->id)->count())->toBe(0)
        ->and(DB::table('invoices')->where('order_id', $pedido->id)->value('state'))->toBe('pending');

    // 8-14) El supervisor confirma: nace la Transaction, la factura queda pagada, el pedido recalcula su estado y el
    // saldo NO vuelve a bajar (solo sin revisar → verificado).
    app(ConfirmarPagoService::class)->confirmar($actor, $pagoId);

    $confirmado = $consulta->saldosDePedidos([$pedido->id])[$pedido->id];

    expect(DB::table('order_transactions')->where('order_id', $pedido->id)->count())->toBe(1)
        ->and((float) DB::table('order_transactions')->where('order_id', $pedido->id)->value('amount'))->toBe(120000.0)
        ->and(DB::table('order_transactions')->where('order_id', $pedido->id)->value('payment_method'))->toBe('moneytransfer')
        ->and(DB::table('invoices')->where('order_id', $pedido->id)->value('state'))->toBe('paid')
        ->and(DB::table('orders')->where('id', $pedido->id)->value('status'))->toBe('processing')
        ->and($confirmado->saldo)->toBe(0.0)->and($confirmado->aplicadoTotal)->toBe(120000.0)
        ->and($confirmado->sinRevisar)->toBe(0.0)->and($confirmado->verificado)->toBe(120000.0)
        ->and($confirmado->requiereRevision)->toBeFalse();
});

test('contra entrega no cambia: pedido pending_payment, factura pending, 0 Transactions y sigue recibiendo pagos', function () {
    $pedido = pagf_pedido_con_factura_automatica('cashondelivery', 90000);

    expect($pedido->status)->toBe('pending_payment')
        ->and(DB::table('invoices')->where('order_id', $pedido->id)->value('state'))->toBe('pending')
        ->and(DB::table('order_transactions')->where('order_id', $pedido->id)->count())->toBe(0)
        ->and(app(CarteraConsultaService::class)->saldosDePedidos([$pedido->id])[$pedido->id]->saldo)->toBe(90000.0);
});
