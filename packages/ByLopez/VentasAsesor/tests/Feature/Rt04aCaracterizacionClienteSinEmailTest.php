<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\Admin\Mail\Customer\NewCustomerNotification;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\Cart as CartModel;
use Webkul\Customer\Models\Customer;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderItem;
use Webkul\Shop\Listeners\Order as ShopOrderListener;
use Webkul\Shop\Mail\Order\CreatedNotification;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\postJson;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| RT-04A — Caracterizacion: cliente comercial sin email (SOLO AUDITORIA)
|--------------------------------------------------------------------------
|
| Documentan el comportamiento ACTUAL de Bagisto; no cambian nada. Solo en
| lpz_saas_testing, con MAIL_MAILER=array y QUEUE_CONNECTION=sync (phpunit.xml):
| ningun correo sale del proceso.
|
*/

function rt04_admin(): void
{
    $rol = Role::factory()->create(['name' => 'RT04 rol '.uniqid(), 'permission_type' => 'all', 'permissions' => null]);

    auth()->guard('admin')->login(Admin::factory()->create(['role_id' => $rol->id]));
}

function rt04_pedido(?string $email): Order
{
    $pedido = Order::factory()->create([
        'customer_email' => $email, 'customer_first_name' => 'RT04', 'customer_last_name' => 'Cliente',
    ]);

    OrderItem::factory()->create(['order_id' => $pedido->id, 'additional' => ['locale' => 'es']]);

    return $pedido->fresh();
}

/** Ejecuta exactamente Shop\Listeners\Base::prepareMail (try/catch \Exception + Mail::queue). */
function rt04_prepararCorreo(Order $pedido): void
{
    $listener = new ShopOrderListener;
    (fn () => $this->prepareMail($pedido, new CreatedNotification($pedido)))->call($listener);
}

/*
| Remitente de prueba en memoria: aisla la causa. Sin remitente, TODOS los correos de
| Bagisto fallan con TypeError en el from (ver la prueba "sin remitente").
*/
beforeEach(function () {
    config(['mail.from.address' => 'remitente@example.test', 'mail.from.name' => 'RT04']);
});

test('BD: customers.email admite NULL y varios NULL no chocan con la unica (email, channel_id)', function () {
    $canal = core()->getDefaultChannel()->id;
    $repo = app(CustomerRepository::class);

    $uno = $repo->create(['first_name' => 'Sin', 'last_name' => 'Correo', 'email' => null, 'channel_id' => $canal, 'password' => bcrypt('x')]);
    $dos = $repo->create(['first_name' => 'Sin', 'last_name' => 'Correo2', 'email' => null, 'channel_id' => $canal, 'password' => bcrypt('x')]);

    expect($uno->fresh()->email)->toBeNull()->and($dos->fresh()->email)->toBeNull();
});

test('BD: dos clientes con email "" en el mismo canal SI chocan (la cadena vacia es un valor)', function () {
    $canal = core()->getDefaultChannel()->id;

    Customer::factory()->create(['email' => '', 'channel_id' => $canal]);

    expect(fn () => Customer::factory()->create(['email' => '', 'channel_id' => $canal]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('BD: customers.phone es UNICO global (no por canal): dos clientes no pueden compartir telefono', function () {
    Customer::factory()->create(['phone' => '3000000001']);

    expect(fn () => Customer::factory()->create(['phone' => '3000000001']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('Admin: crear cliente sin email se rechaza en la validacion del controlador (422, campo email)', function () {
    rt04_admin();

    postJson(route('admin.customers.customers.store'), [
        'first_name' => 'Aurora', 'last_name' => 'Ejemplo', 'gender' => 'Female',
        'channel_id' => core()->getDefaultChannel()->id, 'phone' => '3000000002',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);
});

test('Admin: el telefono con espacios o guiones se rechaza (PhoneNumber exige ^\+?\d+$)', function () {
    rt04_admin();

    postJson(route('admin.customers.customers.store'), [
        'first_name' => 'Aurora', 'last_name' => 'Ejemplo', 'gender' => 'Female',
        'channel_id' => core()->getDefaultChannel()->id, 'email' => 'rt04-'.uniqid().'@example.test',
        'phone' => '300 123 0045',
    ])->assertStatus(422)->assertJsonValidationErrors(['phone']);
});

test('Admin: si no se envia customer_group_id el cliente queda SIN grupo', function () {
    rt04_admin();
    $email = 'rt04-'.uniqid().'@example.test';

    postJson(route('admin.customers.customers.store'), [
        'first_name' => 'Aurora', 'last_name' => 'Ejemplo', 'gender' => 'Female',
        'channel_id' => core()->getDefaultChannel()->id, 'email' => $email,
    ])->assertOk();

    $cliente = Customer::query()->where('email', $email)->firstOrFail();

    expect($cliente->customer_group_id)->toBeNull()
        ->and($cliente->group)->toBeNull();   // getCurrentGroup(): $customer->group ?? invitado
});

test('Carrito: al guardar direcciones, un cliente con email NULL toma el email de la direccion de facturacion', function () {
    $cliente = Customer::factory()->create(['email' => null]);
    $carrito = CartModel::factory()->create(['customer_id' => $cliente->id, 'customer_email' => null, 'is_guest' => 0]);
    $direccion = [
        'first_name' => 'Aurora', 'last_name' => 'Ejemplo', 'email' => 'direccion@example.test',
        'address' => ['Calle 1'], 'country' => 'CO', 'state' => 'DC', 'city' => 'Bogota', 'postcode' => '110111', 'phone' => '3000000003',
        'use_for_shipping' => true,
    ];

    Cart::setCart($carrito);
    Cart::saveAddresses(['billing' => $direccion]);

    expect($carrito->fresh()->customer_email)->toBe('direccion@example.test');
});

test('Carrito: con email "" el ?? NO cae a la direccion: el carrito queda con email vacio', function () {
    $cliente = Customer::factory()->create(['email' => '']);
    $carrito = CartModel::factory()->create(['customer_id' => $cliente->id, 'customer_email' => '', 'is_guest' => 0]);

    Cart::setCart($carrito);
    Cart::saveAddresses(['billing' => [
        'first_name' => 'Aurora', 'last_name' => 'Ejemplo', 'email' => 'direccion@example.test',
        'address' => ['Calle 1'], 'country' => 'CO', 'state' => 'DC', 'city' => 'Bogota', 'postcode' => '110111', 'phone' => '3000000004',
        'use_for_shipping' => true,
    ]]);

    expect($carrito->fresh()->customer_email)->toBe('');
});

test('Admin: la direccion del carrito exige email (CartAddressRequest)', function () {
    rt04_admin();
    $cliente = Customer::factory()->create();
    $carrito = CartModel::factory()->create(['customer_id' => $cliente->id, 'is_guest' => 0, 'is_active' => 0]);

    postJson(route('admin.sales.cart.addresses.store', $carrito->id), ['billing' => [
        'first_name' => 'Aurora', 'last_name' => 'Ejemplo',
        'address' => ['Calle 1'], 'country' => 'CO', 'state' => 'DC', 'city' => 'Bogota', 'postcode' => '110111', 'phone' => '3000000005',
        'use_for_shipping' => true,
    ]])->assertStatus(422)->assertJsonValidationErrors(['billing.email']);
});

test('Notificacion de pedido con customer_email NULL: TypeError que el try/catch(\Exception) NO atrapa', function () {
    $pedido = rt04_pedido(null);

    expect(fn () => rt04_prepararCorreo($pedido))->toThrow(TypeError::class);
});

test('Notificacion de pedido con customer_email "": la excepcion de Symfony se atrapa y solo se registra', function () {
    $pedido = rt04_pedido('');

    rt04_prepararCorreo($pedido);   // no lanza

    expect(true)->toBeTrue();
});

test('Credenciales de cliente nuevo (admin) con email NULL: TypeError', function () {
    $cliente = Customer::factory()->create(['email' => null]);

    expect(fn () => Mail::queue(new NewCustomerNotification($cliente, '123456')))->toThrow(TypeError::class);
});

test('Registro: syncNewRegisteredCustomerInformation enlaza pedidos por customer_email', function () {
    $cliente = Customer::factory()->create(['email' => 'enlace-'.uniqid().'@example.test']);
    $pedido = Order::factory()->create(['customer_email' => $cliente->email, 'customer_id' => null, 'is_guest' => 1]);

    app(CustomerRepository::class)->syncNewRegisteredCustomerInformation($cliente);

    expect((int) $pedido->fresh()->customer_id)->toBe((int) $cliente->id)
        ->and(DB::table('orders')->where('id', $pedido->id)->value('is_guest'))->toBe(0);
});

test('Entorno sin remitente: el correo de credenciales lanza TypeError que el controlador no atrapa (500) DESPUES de guardar al cliente', function () {
    config(['mail.from.address' => null]);
    rt04_admin();
    $email = 'rt04-'.uniqid().'@example.test';

    postJson(route('admin.customers.customers.store'), [
        'first_name' => 'Aurora', 'last_name' => 'Ejemplo', 'gender' => 'Female',
        'channel_id' => core()->getDefaultChannel()->id, 'email' => $email, 'customer_group_id' => 2,
    ])->assertStatus(500);

    expect(Customer::query()->where('email', $email)->exists())->toBeTrue();
});
