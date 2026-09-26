<?php

use ByLopez\VentasAsesor\Exceptions\ContextoPrecioComercialInvalidoException;
use ByLopez\VentasAsesor\Support\ContextoPrecioComercial;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\Cart as CartModel;
use Webkul\Checkout\Models\CartItem;
use Webkul\Customer\Models\Customer;
use Webkul\Customer\Models\CustomerGroup;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Faker\Helpers\Product as ProductFaker;
use Webkul\Product\Helpers\Indexers\Price as IndexadorDePrecios;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductCustomerGroupPrice;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderItem;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\get;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| RT-03B — Volver a pedir (admin.sales.orders.reorder)
|--------------------------------------------------------------------------
|
| Bagisto recalcula (addProduct -> prepareForCart -> getFinalPrice): no copia
| precios historicos. El carrito nuevo debe NACER con el grupo ACTUAL del cliente
| del pedido, nunca con el de la sesion ni el invitado, ni siquiera un instante.
| Por eso se registran TODOS los precios que se guardan en sus items.
|
| Precios de fixture: base 8500, grupo A 7400 (tier qty 3: 7000), grupo B 6900 (6500).
|
*/

function ro_grupo(): CustomerGroup
{
    return CustomerGroup::factory()->create(['code' => 'ro_'.uniqid(), 'name' => 'RO', 'is_user_defined' => 1]);
}

function ro_producto(array $preciosPorGrupo, string $tipo = 'fixed'): Product
{
    $producto = (new ProductFaker([
        'attributes' => [5 => 'new', 6 => 'featured', 11 => 'price', 26 => 'guest_checkout'],
        'attribute_value' => [
            'new' => ['boolean_value' => true], 'featured' => ['boolean_value' => true],
            'price' => ['float_value' => 8500], 'guest_checkout' => ['boolean_value' => true],
        ],
    ]))->getSimpleProductFactory()->create();

    ro_precios($producto, $preciosPorGrupo, $tipo);

    return $producto->fresh();
}

function ro_precios(Product $producto, array $preciosPorGrupo, string $tipo): void
{
    foreach ($preciosPorGrupo as $grupoId => $porCantidad) {
        foreach ($porCantidad as $qty => $valor) {
            ProductCustomerGroupPrice::factory()->create([
                'product_id' => $producto->id, 'customer_group_id' => $grupoId,
                'qty' => $qty, 'value_type' => $tipo, 'value' => $valor,
            ]);
        }
    }

    app(IndexadorDePrecios::class)->reindexBatch(collect([$producto->fresh()]));
}

/**
 * Pedido de un cliente con items que Bagisto sabe volver a pedir. El precio del item
 * del pedido es deliberadamente absurdo (1234): si Bagisto lo copiara, se veria.
 *
 * @param  list<array{0: Product, 1: array}>  $items  [producto, additional]
 */
function ro_pedido(?Customer $cliente, array $items): Order
{
    $pedido = Order::factory()->create(array_merge(
        $cliente
            ? ['customer_id' => $cliente->id, 'customer_type' => Customer::class, 'customer_email' => $cliente->email]
            : ['customer_id' => null, 'customer_type' => null, 'is_guest' => 1, 'customer_email' => 'invitado@example.test'],
        ['customer_first_name' => 'RO', 'customer_last_name' => 'Pedido'],
    ));

    foreach ($items as [$producto, $additional]) {
        OrderItem::factory()->create([
            'order_id' => $pedido->id, 'product_id' => $producto->id, 'product_type' => Product::class,
            'sku' => $producto->sku, 'type' => $producto->type, 'name' => 'RO',
            'price' => 1234, 'base_price' => 1234,
            'additional' => $additional,
        ]);
    }

    return $pedido;
}

function ro_sesionCliente(?Customer $cliente): void
{
    if (auth()->guard('customer')->check()) {
        auth()->guard('customer')->logout();
    }

    if ($cliente) {
        auth()->guard('customer')->login($cliente->fresh());
    }
}

/** Ejecuta el reorder real y devuelve el carrito nuevo y TODOS los precios que se guardaron en sus items. */
function ro_reordenar(Order $pedido): array
{
    $precios = [];

    app('events')->listen('eloquent.saved: '.CartItem::class, function (CartItem $item) use (&$precios) {
        if ($item->parent_id === null) {
            $precios[] = (float) $item->price;
        }
    });

    $antes = (int) CartModel::query()->max('id');

    get(route('admin.sales.orders.reorder', $pedido->id))->assertRedirect();

    $carrito = CartModel::query()->where('id', '>', $antes)->latest('id')->first();

    return [$carrito, $precios];
}

beforeEach(function () {
    $this->grupoA = ro_grupo();
    $this->grupoB = ro_grupo();
    $this->clienteA = Customer::factory()->create(['customer_group_id' => $this->grupoA->id]);
    $this->clienteB = Customer::factory()->create(['customer_group_id' => $this->grupoB->id]);
    $this->producto = ro_producto([
        $this->grupoA->id => [1 => 7400, 3 => 7000],
        $this->grupoB->id => [1 => 6900, 3 => 6500],
    ]);

    ro_sesionCliente(null);

    $rol = Role::factory()->create(['name' => 'RO rol '.uniqid(), 'permission_type' => 'all', 'permissions' => null]);
    auth()->guard('admin')->login(Admin::factory()->create(['role_id' => $rol->id]));
});

test('R1 - sesion A, pedido de B: el carrito nuevo nace con el grupo B desde el primer calculo', function () {
    $pedido = ro_pedido($this->clienteB, [[$this->producto, ['product_id' => $this->producto->id, 'quantity' => 1]]]);
    ro_sesionCliente($this->clienteA);

    [$carrito, $precios] = ro_reordenar($pedido);

    expect((int) $carrito->customer_id)->toBe((int) $this->clienteB->id)
        ->and($precios)->not->toBeEmpty()
        ->and(array_unique($precios))->toBe([6900.0])   // nunca 7400 (A) ni 8500 (invitado), ni un instante
        ->and((float) $carrito->fresh()->grand_total)->toBe(6900.0);
});

test('R2 - sin cliente en sesion: grupo B del pedido, no invitado', function () {
    $pedido = ro_pedido($this->clienteB, [[$this->producto, ['product_id' => $this->producto->id, 'quantity' => 1]]]);

    [$carrito, $precios] = ro_reordenar($pedido);

    expect(array_unique($precios))->toBe([6900.0])
        ->and((int) $carrito->customer_id)->toBe((int) $this->clienteB->id);
});

test('R3 - el grupo cambio desde el pedido: se usa el grupo ACTUAL, y no se copia el precio historico del pedido', function () {
    // El pedido se hizo cuando B era del grupo A (precio historico 1234 en el item).
    DB::table('customers')->where('id', $this->clienteB->id)->update(['customer_group_id' => $this->grupoA->id]);
    $pedido = ro_pedido($this->clienteB, [[$this->producto, ['product_id' => $this->producto->id, 'quantity' => 1]]]);
    DB::table('customers')->where('id', $this->clienteB->id)->update(['customer_group_id' => $this->grupoB->id]);

    [, $precios] = ro_reordenar($pedido);

    expect(array_unique($precios))->toBe([6900.0])
        ->and($precios)->not->toContain(1234.0)
        ->and($precios)->not->toContain(7400.0);
});

test('R4 - configurable: la variante se precia con el grupo actual del cliente del pedido', function () {
    $configurable = (new ProductFaker([
        'attributes' => [5 => 'new', 6 => 'featured', 11 => 'price', 26 => 'guest_checkout'],
        'attribute_value' => [
            'new' => ['boolean_value' => true], 'featured' => ['boolean_value' => true],
            'price' => ['float_value' => 8500], 'guest_checkout' => ['boolean_value' => true],
        ],
    ]))->getConfigurableProductFactory()->create();
    $variante = $configurable->variants()->first();
    $base = (float) $variante->price;
    ro_precios($variante, [$this->grupoA->id => [1 => 10], $this->grupoB->id => [1 => 30]], 'discount');
    app(IndexadorDePrecios::class)->reindexBatch(collect([$configurable->fresh()]));

    $pedido = ro_pedido($this->clienteB, [[$configurable, [
        'product_id' => $configurable->id, 'quantity' => 1, 'selected_configurable_option' => $variante->id,
    ]]]);
    ro_sesionCliente($this->clienteA);

    [$carrito, $precios] = ro_reordenar($pedido);

    expect(array_map(fn ($p) => round($p, 2), array_unique($precios)))->toBe([round($base * 0.7, 2)])
        ->and(CartItem::query()->where('cart_id', $carrito->id)->whereNotNull('parent_id')->value('product_id'))->toBe($variante->id);
});

test('R5 - cantidad 3: se aplica el tier del grupo actual (B), nunca el de A', function () {
    $pedido = ro_pedido($this->clienteB, [[$this->producto, ['product_id' => $this->producto->id, 'quantity' => 3]]]);
    ro_sesionCliente($this->clienteA);

    [$carrito, $precios] = ro_reordenar($pedido);

    expect(end($precios))->toBe(6500.0)
        ->and(array_diff($precios, [6900.0, 6500.0]))->toBe([])   // solo precios del grupo B
        ->and((float) $carrito->fresh()->sub_total)->toBe(3 * 6500.0);
});

test('R6 y R7 - tras la peticion el contexto desaparece y la siguiente operacion sin contexto es core', function () {
    $pedido = ro_pedido($this->clienteB, [[$this->producto, ['product_id' => $this->producto->id, 'quantity' => 1]]]);
    ro_sesionCliente($this->clienteA);

    ro_reordenar($pedido);

    expect(app(ContextoPrecioComercial::class)->activo())->toBeFalse()
        ->and((int) app(CustomerRepository::class)->getCurrentGroup()->id)->toBe((int) $this->grupoA->id);   // sesion A: core
});

test('R8 - contexto de creacion: un carrito de OTRO cliente creado durante la operacion se rechaza (fail closed)', function () {
    $contexto = app(ContextoPrecioComercial::class);

    expect(fn () => $contexto->duranteCreacionDeCarrito($this->clienteB->id, function () {
        Cart::createCart(['customer' => $this->clienteA, 'is_active' => false]);
    }))->toThrow(fn (ContextoPrecioComercialInvalidoException $e) => expect($e->codigo)->toBe(ContextoPrecioComercialInvalidoException::CARRITO_DE_OTRO_CLIENTE));

    expect($contexto->activo())->toBeFalse();

    // Cliente inexistente: no se declara ningun contexto.
    expect(fn () => $contexto->duranteCreacionDeCarrito(999999999, fn () => null))
        ->toThrow(fn (ContextoPrecioComercialInvalidoException $e) => expect($e->codigo)->toBe(ContextoPrecioComercialInvalidoException::CLIENTE_INEXISTENTE));
});

test('R8 - contexto de creacion: al crearse el carrito del mismo cliente pasa a contexto normal con el grupo ya resuelto', function () {
    $contexto = app(ContextoPrecioComercial::class);

    $contexto->duranteCreacionDeCarrito($this->clienteB->id, function () use ($contexto) {
        expect($contexto->identificacion())->toBe(['tipo' => 'creacion', 'customer_id' => $this->clienteB->id])
            ->and((int) app(CustomerRepository::class)->getCurrentGroup()->id)->toBe((int) $this->grupoB->id);

        $carrito = Cart::createCart(['customer' => $this->clienteB, 'is_active' => false]);

        expect($contexto->identificacion())->toBe(['tipo' => 'carrito', 'cart_id' => $carrito->id, 'customer_id' => $this->clienteB->id])
            ->and((int) app(CustomerRepository::class)->getCurrentGroup()->id)->toBe((int) $this->grupoB->id);
    });

    expect($contexto->activo())->toBeFalse();
});

test('R8 - pedido de invitado o de cliente borrado: Bagisto no vuelve a pedir y no se declara contexto', function () {
    $deInvitado = ro_pedido(null, [[$this->producto, ['product_id' => $this->producto->id, 'quantity' => 1]]]);
    $deBorrado = ro_pedido($this->clienteB, [[$this->producto, ['product_id' => $this->producto->id, 'quantity' => 1]]]);
    DB::table('customers')->where('id', $this->clienteB->id)->delete();
    $antes = CartModel::query()->count();

    get(route('admin.sales.orders.reorder', $deInvitado->id))->assertRedirect(route('admin.sales.orders.view', $deInvitado->id));
    get(route('admin.sales.orders.reorder', $deBorrado->id))->assertRedirect(route('admin.sales.orders.view', $deBorrado->id));

    expect(CartModel::query()->count())->toBe($antes)
        ->and(app(ContextoPrecioComercial::class)->activo())->toBeFalse();
});

test('R8 - el contexto de creacion no puede usarse para saltarse la validacion carrito/cliente', function () {
    $carritoA = CartModel::factory()->create(['customer_id' => $this->clienteA->id, 'is_guest' => 0]);
    $contexto = app(ContextoPrecioComercial::class);

    // durante() normal sigue exigiendo cart.customer_id == customer_id.
    expect(fn () => $contexto->durante($carritoA->id, $this->clienteB->id, fn () => null))
        ->toThrow(ContextoPrecioComercialInvalidoException::class);

    // No existe forma publica de declarar "customer sin carrito" salvo la de creacion.
    $metodos = collect((new ReflectionClass(ContextoPrecioComercial::class))->getMethods(ReflectionMethod::IS_PUBLIC))->map->getName()->all();

    expect($metodos)->toContain('duranteCreacionDeCarrito', 'fijarCreacionParaPeticion', 'carritoCreado')
        ->and((new ReflectionMethod(ContextoPrecioComercial::class, 'durante'))->getParameters()[0]->allowsNull())->toBeFalse();
});

test('R9 - storefront sin regresion: sin contexto, el cliente autenticado usa su grupo', function () {
    auth()->guard('admin')->logout();
    ro_sesionCliente($this->clienteA);

    Pest\Laravel\postJson(route('shop.api.checkout.cart.store'), ['product_id' => $this->producto->id, 'quantity' => 1])->assertOk();

    expect(app(ContextoPrecioComercial::class)->activo())->toBeFalse()
        ->and((float) CartItem::query()->where('product_id', $this->producto->id)->latest('id')->value('price'))->toBe(7400.0);
});
