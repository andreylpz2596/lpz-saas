<?php

use Webkul\Customer\Models\Customer;
use Webkul\Faker\Helpers\Product as ProductFaker;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderAddress;
use Webkul\Sales\Models\OrderItem;
use Webkul\Sales\Models\OrderPayment;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Repositories\RefundRepository;
use Webkul\Sales\Repositories\ShipmentRepository;

/*
|--------------------------------------------------------------------------
| Helpers RT-04B1a: pedido facturable y enviable (solo lpz_saas_testing)
|--------------------------------------------------------------------------
| Mismo armado que las pruebas del core (Admin/tests/Feature/Sales/InvoiceTest.php):
| fabricas de Order, OrderItem, OrderAddress y OrderPayment. Telefonos ficticios.
*/

if (! function_exists('rt04b1a_pedido')) {
    /**
     * @param  array{email?: ?string, ficha?: ?string, facturacion?: string, envio?: string, email_direccion?: ?string}  $o
     * @return array{cliente: Customer, pedido: Order, item: OrderItem}
     */
    function rt04b1a_pedido(array $o = []): array
    {
        $o += ['email' => 'cliente-'.uniqid().'@example.test', 'ficha' => null, 'facturacion' => '3000000001', 'envio' => '3000000002', 'email_direccion' => null];

        $cliente = Customer::factory()->create(['email' => $o['email'], 'phone' => $o['ficha']]);

        $producto = (new ProductFaker(['attributes' => [5 => 'new'], 'attribute_value' => ['new' => ['boolean_value' => true]]]))
            ->getSimpleProductFactory()->create();

        $pedido = Order::factory()->create([
            'customer_id' => $cliente->id,
            'customer_type' => Customer::class,
            'customer_email' => $o['email'],
            'customer_first_name' => $cliente->first_name,
            'customer_last_name' => $cliente->last_name,
            'is_guest' => 0,
            // La fabrica usa USD, que no existe en testing: la vista del PDF lo necesita real.
            'base_currency_code' => core()->getBaseCurrencyCode(),
            'channel_currency_code' => core()->getBaseCurrencyCode(),
            'order_currency_code' => core()->getBaseCurrencyCode(),
        ]);

        $item = OrderItem::factory()->create([
            'product_id' => $producto->id, 'order_id' => $pedido->id, 'sku' => $producto->sku,
            'type' => $producto->type, 'name' => $producto->name, 'additional' => ['locale' => 'es'],
        ]);

        foreach ([OrderAddress::ADDRESS_TYPE_BILLING => $o['facturacion'], OrderAddress::ADDRESS_TYPE_SHIPPING => $o['envio']] as $tipo => $telefono) {
            OrderAddress::factory()->create([
                'order_id' => $pedido->id, 'customer_id' => $cliente->id, 'address_type' => $tipo,
                'first_name' => 'RT04B1a', 'last_name' => 'Cliente', 'email' => $o['email_direccion'],
                'address' => 'Calle ficticia', 'city' => 'Bogota', 'state' => 'DC', 'country' => 'CO',
                'postcode' => '110111', 'phone' => $telefono,
            ]);
        }

        OrderPayment::factory()->create(['order_id' => $pedido->id]);

        return ['cliente' => $cliente, 'pedido' => $pedido->fresh(), 'item' => $item];
    }
}

if (! function_exists('rt04b1a_facturar')) {
    function rt04b1a_facturar(Order $pedido, OrderItem $item)
    {
        return app(InvoiceRepository::class)->create(['order_id' => $pedido->id, 'invoice' => ['items' => [$item->id => 1]]]);
    }
}

if (! function_exists('rt04b1a_reembolsar')) {
    /** Exige una factura previa: qty_to_refund sale de qty_invoiced. */
    function rt04b1a_reembolsar(Order $pedido, OrderItem $item)
    {
        return app(RefundRepository::class)->create(['order_id' => $pedido->id, 'refund' => [
            'items' => [$item->id => 1], 'shipping' => 0, 'adjustment_refund' => 0, 'adjustment_fee' => 0,
        ]]);
    }
}

if (! function_exists('rt04b1a_enviar')) {
    function rt04b1a_enviar(Order $pedido, OrderItem $item)
    {
        return app(ShipmentRepository::class)->create(['order_id' => $pedido->id, 'shipment' => [
            'carrier_title' => 'Ficticia', 'track_number' => 'RT04B1A', 'source' => 1,
            'items' => [$item->id => [1 => 1]],
        ]]);
    }
}
