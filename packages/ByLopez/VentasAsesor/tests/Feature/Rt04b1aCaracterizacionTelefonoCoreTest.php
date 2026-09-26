<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\Cart as CartModel;
use Webkul\Checkout\Models\CartAddress;
use Webkul\Customer\Models\Customer;
use Webkul\Sales\Models\Invoice;
use Webkul\Sales\Transformers\OrderAddressResource;

require_once __DIR__.'/../helpersRt04b1a.php';

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| RT-04B1a — Caracterizacion: que telefono usa cada componente del core
|--------------------------------------------------------------------------
| Documenta el comportamiento ACTUAL de Bagisto; no cambia nada. Telefonos
| ficticios: ficha del cliente (customers.phone), facturacion y envio distintos
| entre si para ver cual lee cada componente.
*/

function rt04b1a_tel(): string
{
    return '3'.str_pad((string) random_int(0, 999_999_999), 9, '0', STR_PAD_LEFT);
}

beforeEach(function () {
    config(['mail.from.address' => 'remitente@example.test', 'mail.from.name' => 'RT04B1a']);
});

test('Cart: saveAddresses guarda el telefono de la direccion recibida; nunca copia customers.phone (sin items almacenables no crea direccion de envio)', function () {
    $cliente = Customer::factory()->create(['phone' => $ficha = rt04b1a_tel()]);
    $carrito = CartModel::factory()->create(['customer_id' => $cliente->id, 'customer_email' => $cliente->email, 'is_guest' => 0]);
    $direccion = fn (string $tel) => [
        'first_name' => 'RT04B1a', 'last_name' => 'Cliente', 'email' => 'dir@example.test', 'address' => ['Calle 1'],
        'country' => 'CO', 'state' => 'DC', 'city' => 'Bogota', 'postcode' => '110111', 'phone' => $tel,
    ];

    Cart::setCart($carrito);
    Cart::saveAddresses(['billing' => $direccion($fact = rt04b1a_tel()), 'shipping' => $direccion(rt04b1a_tel())]);

    $telefonos = CartAddress::query()->where('cart_id', $carrito->id)->pluck('phone', 'address_type')->all();

    // Cart::updateOrCreateShippingAddress (l. 490): sin items almacenables no guarda envio.
    expect($telefonos)->toBe([CartAddress::ADDRESS_TYPE_BILLING => $fact])
        ->and($cliente->fresh()->phone)->toBe($ficha)
        ->and(Schema::getColumnListing('cart'))->not->toContain('phone')
        ->and(Schema::getColumnListing('cart'))->not->toContain('customer_phone');
});

test('Order: OrderAddressResource copia el telefono de la direccion del carrito (no el de la ficha)', function () {
    $direccion = new CartAddress(['phone' => '3000000009', 'email' => null, 'address_type' => CartAddress::ADDRESS_TYPE_BILLING]);

    expect((new OrderAddressResource($direccion))->jsonSerialize())
        ->toMatchArray(['phone' => '3000000009', 'email' => null]);
});

test('Esquema: pedidos, facturas y envios no tienen columna de telefono; SOLO shipments apunta a una direccion del pedido', function () {
    foreach (['orders', 'invoices', 'shipments'] as $tabla) {
        expect(collect(Schema::getColumnListing($tabla))->filter(fn ($c) => str_contains($c, 'phone'))->all())->toBe([]);
    }

    // HALLAZGO: InvoiceRepository::create envia order_address_id (l. 69), pero la tabla
    // no tiene esa columna y Eloquent la descarta en silencio.
    expect(Schema::getColumnListing('invoices'))->not->toContain('order_address_id')
        ->and(Schema::getColumnListing('shipments'))->toContain('order_address_id');
});

test('Invoice: Invoice::address() queda NULL (columna inexistente); la factura usa order.billing_address; funciona con customers.phone NULL', function () {
    Mail::fake();
    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido(['ficha' => null, 'facturacion' => $fact = rt04b1a_tel(), 'envio' => rt04b1a_tel()]);

    $factura = rt04b1a_facturar($pedido, $item);

    expect($factura->fresh()->address)->toBeNull()
        ->and($factura->order->billing_address->phone)->toBe($fact)
        ->and($factura->order->customer->phone)->toBeNull();
});

test('el modulo no depende de Invoice::address() (roto en el core): ningun codigo de VentasAsesor lo usa', function () {
    $usos = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $archivo) {
        if ($archivo->getExtension() === 'php'
            && preg_match('/(invoice|factura)\w*\s*->\s*address\b/i', file_get_contents($archivo->getPathname()))) {
            $usos[] = $archivo->getFilename();
        }
    }

    // La fuente correcta de telefono/direccion de una factura es su pedido (ver la prueba anterior).
    expect($usos)->toBe([]);
});

test('Shipment: su direccion es la de ENVIO del pedido; funciona con customers.phone NULL', function () {
    Mail::fake();
    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido(['ficha' => null, 'facturacion' => rt04b1a_tel(), 'envio' => $envio = rt04b1a_tel()]);

    $envioCreado = rt04b1a_enviar($pedido, $item);

    expect($envioCreado->address->address_type)->toBe('order_shipping')
        ->and($envioCreado->address->phone)->toBe($envio);
});

test('PDF de factura (admin y tienda usan la misma vista): muestra los telefonos de facturacion y envio, nunca customers.phone', function () {
    Mail::fake();
    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido([
        'ficha' => $ficha = rt04b1a_tel(), 'facturacion' => $fact = rt04b1a_tel(), 'envio' => $envio = rt04b1a_tel(),
    ]);
    $factura = rt04b1a_facturar($pedido, $item);

    $html = view('shop::customers.account.orders.pdf', [
        'invoice' => $factura->fresh(), 'orderCurrencyCode' => $pedido->order_currency_code,
    ])->render();

    expect($html)->toContain($fact)->toContain($envio)->not->toContain($ficha);
});

test('HALLAZGO core: Shop\Listeners\Invoice marca email_sent = 1 en TODAS las facturas (query()->update sin where)', function () {
    Mail::fake();
    ['pedido' => $p1, 'item' => $i1] = rt04b1a_pedido();
    $primera = rt04b1a_facturar($p1, $i1);
    DB::table('invoices')->update(['email_sent' => 0]);

    ['pedido' => $p2, 'item' => $i2] = rt04b1a_pedido();
    rt04b1a_facturar($p2, $i2);

    expect((int) Invoice::query()->whereKey($primera->id)->value('email_sent'))->toBe(1)
        ->and(Invoice::query()->where('email_sent', 0)->count())->toBe(0);
});
