<?php

use ByLopez\VentasAsesor\Support\ContextoPrecioComercial;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\CartRule\Models\CartRule;
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
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| RT-03 — Precio por grupo del cliente del carrito
|--------------------------------------------------------------------------
|
| RT-03A reprodujo el defecto: en el panel, Bagisto preciaba con el grupo del
| cliente de la SESION (o invitado), no con el del cliente del carrito.
| RT-03B lo corrige (ContextoPrecioComercial + adaptador RouteMatched). Estas
| pruebas son ahora el comportamiento ESPERADO.
|
| Sesion administrativa REAL: guard('admin')->login(), SIN actingAs(), porque
| actingAs() cambia el guard por defecto y ocultaria el defecto original.
|
| Precios de fixture (no reglas de negocio): base 8500, grupo A 7400, grupo B 6900.
|
*/

const RT03_BASE = 8500.0;
const RT03_A = 7400.0;
const RT03_B = 6900.0;

function rt03_grupo(string $nombre): CustomerGroup
{
    return CustomerGroup::factory()->create([
        'code' => 'rt03_'.strtolower($nombre).'_'.uniqid(),
        'name' => 'RT03 '.$nombre,
        'is_user_defined' => 1,
    ]);
}

function rt03_cliente(CustomerGroup $grupo): Customer
{
    return Customer::factory()->create(['customer_group_id' => $grupo->id]);
}

function rt03_reindexar(Product ...$productos): void
{
    app(IndexadorDePrecios::class)->reindexBatch(collect($productos)->map(fn (Product $p) => $p->fresh()));
}

/** @param  array<int, array<int, float>>  $preciosPorGrupo  customer_group_id => [qty => precio fijo] */
function rt03_simple(array $preciosPorGrupo, float $base = RT03_BASE): Product
{
    $producto = (new ProductFaker([
        'attributes' => [5 => 'new', 6 => 'featured', 11 => 'price', 26 => 'guest_checkout'],
        'attribute_value' => [
            'new' => ['boolean_value' => true],
            'featured' => ['boolean_value' => true],
            'price' => ['float_value' => $base],
            'guest_checkout' => ['boolean_value' => true],
        ],
    ]))->getSimpleProductFactory()->create();

    rt03_preciosDeGrupo($producto, $preciosPorGrupo, 'fixed');
    rt03_reindexar($producto);

    return $producto->fresh();
}

/** @param  array<int, array<int, float>>  $preciosPorGrupo */
function rt03_preciosDeGrupo(Product $producto, array $preciosPorGrupo, string $tipo): void
{
    foreach ($preciosPorGrupo as $grupoId => $porCantidad) {
        foreach ($porCantidad as $qty => $valor) {
            ProductCustomerGroupPrice::factory()->create([
                'product_id' => $producto->id, 'customer_group_id' => $grupoId,
                'qty' => $qty, 'value_type' => $tipo, 'value' => $valor,
            ]);
        }
    }
}

function rt03_admin(): Admin
{
    $rol = Role::factory()->create(['name' => 'RT03 rol '.uniqid(), 'permission_type' => 'all', 'permissions' => null]);
    $admin = Admin::factory()->create(['role_id' => $rol->id]);

    auth()->guard('admin')->login($admin);

    return $admin;
}

/** Cliente en la sesion (lo que hace "Iniciar sesion como cliente"), o ninguno. */
function rt03_sesionCliente(?Customer $cliente): void
{
    if (auth()->guard('customer')->check()) {
        auth()->guard('customer')->logout();
    }

    if ($cliente) {
        auth()->guard('customer')->login($cliente->fresh());
    }
}

function rt03_carritoAdmin(Customer $cliente): CartModel
{
    $respuesta = postJson(route('admin.sales.cart.store'), ['customer_id' => $cliente->id])->assertOk();

    return CartModel::query()->findOrFail($respuesta->json('data.id'));
}

function rt03_agregar(CartModel $carrito, Product $producto, int $qty = 1, array $extra = []): void
{
    postJson(route('admin.sales.cart.items.store', $carrito->id), array_merge([
        'product_id' => $producto->id,
        'quantity' => $qty,
    ], $extra))->assertOk()->assertJsonPath('data.id', $carrito->id);
}

/** Recalculo por el PANEL: PUT items con las mismas cantidades (updateItems -> collectTotals). */
function rt03_recalcularPanel(CartModel $carrito): CartModel
{
    $cantidades = CartItem::query()->where('cart_id', $carrito->id)->whereNull('parent_id')->pluck('quantity', 'id')->all();

    putJson(route('admin.sales.cart.items.update', $carrito->id), ['qty' => $cantidades])->assertOk();

    return $carrito->fresh();
}

function rt03_precio(CartModel $carrito, Product $producto): float
{
    return (float) CartItem::query()->where('cart_id', $carrito->id)
        ->where('product_id', $producto->id)->whereNull('parent_id')->value('price');
}

beforeEach(function () {
    $this->grupoA = rt03_grupo('A');
    $this->grupoB = rt03_grupo('B');
    $this->invitado = core()->getGuestCustomerGroup();
    $this->clienteA = rt03_cliente($this->grupoA);
    $this->clienteB = rt03_cliente($this->grupoB);

    rt03_sesionCliente(null);
    rt03_admin();
});

test('premisa - en una peticion administrativa real el guard por defecto sigue siendo customer', function () {
    expect(config('auth.defaults.guard'))->toBe('customer')
        ->and(auth()->getDefaultDriver())->toBe('customer')
        ->and(auth()->guard('admin')->check())->toBeTrue()
        ->and((int) app(CustomerRepository::class)->getCurrentGroup()->id)->toBe((int) $this->invitado->id);
});

test('T1 - panel puro: el carrito de A se precia con el grupo A', function () {
    $producto = rt03_simple([$this->grupoA->id => [1 => RT03_A]]);
    $carrito = rt03_carritoAdmin($this->clienteA);

    rt03_agregar($carrito, $producto);

    expect(rt03_precio($carrito, $producto))->toBe(RT03_A);
});

test('T2 - cliente A en sesion y carrito de B: gana el cliente del carrito (B)', function () {
    $producto = rt03_simple([$this->grupoA->id => [1 => RT03_A], $this->grupoB->id => [1 => RT03_B]]);
    rt03_sesionCliente($this->clienteA);
    $carrito = rt03_carritoAdmin($this->clienteB);

    rt03_agregar($carrito, $producto);

    expect(rt03_precio($carrito, $producto))->toBe(RT03_B);
});

test('T3 - cambiar solo el cliente de la sesion no cambia el precio de un carrito de A', function () {
    $producto = rt03_simple([$this->grupoA->id => [1 => RT03_A], $this->grupoB->id => [1 => RT03_B]]);
    rt03_sesionCliente($this->clienteA);
    $carrito = rt03_carritoAdmin($this->clienteA);
    rt03_agregar($carrito, $producto);

    rt03_sesionCliente($this->clienteB);

    expect(rt03_precio(rt03_recalcularPanel($carrito), $producto))->toBe(RT03_A);
});

test('T4 REQUISITO FUTURO FASE 03 (no es flujo actual de Bagisto) - con cart.customer_id = B, el recalculo lleva TODOS los items a B', function () {
    /*
     * Bagisto no cambia el cliente de un carrito existente: elegir otro cliente crea
     * otro carrito (Admin\...\Sales\CartController::store). Si VentasAsesor permite
     * cambiar el cliente de un borrador (Fase 03), debera actualizar la identidad y
     * recalcular todo. Aqui se comprueba que, una vez cambiado el cliente, el
     * mecanismo de RT-03B no deja sobrevivir ningun precio del cliente anterior.
     */
    $p1 = rt03_simple([$this->grupoA->id => [1 => RT03_A], $this->grupoB->id => [1 => RT03_B]]);
    $p2 = rt03_simple([$this->grupoA->id => [1 => RT03_A - 400], $this->grupoB->id => [1 => RT03_B - 400]]);
    rt03_sesionCliente($this->clienteA);
    $carrito = rt03_carritoAdmin($this->clienteA);
    rt03_agregar($carrito, $p1, 2);
    rt03_agregar($carrito, $p2, 1);

    DB::table('cart')->where('id', $carrito->id)->update(['customer_id' => $this->clienteB->id]);

    $carrito = rt03_recalcularPanel($carrito);

    expect([rt03_precio($carrito, $p1), rt03_precio($carrito, $p2)])->toBe([RT03_B, RT03_B - 400])
        ->and((float) $carrito->sub_total)->toBe(2 * RT03_B + (RT03_B - 400))
        ->and((float) $carrito->items_qty)->toBe(3.0);
});

test('T5 / R - configurable: la variante se precia con el grupo del cliente del carrito', function () {
    $configurable = (new ProductFaker([
        'attributes' => [5 => 'new', 6 => 'featured', 11 => 'price', 26 => 'guest_checkout'],
        'attribute_value' => [
            'new' => ['boolean_value' => true], 'featured' => ['boolean_value' => true],
            'price' => ['float_value' => RT03_BASE], 'guest_checkout' => ['boolean_value' => true],
        ],
    ]))->getConfigurableProductFactory()->create();

    $variante = $configurable->variants()->first();
    $base = (float) $variante->price;

    rt03_preciosDeGrupo($variante, [$this->grupoA->id => [1 => 10], $this->grupoB->id => [1 => 30]], 'discount');
    rt03_reindexar($variante, $configurable);

    rt03_sesionCliente($this->clienteA);
    $carrito = rt03_carritoAdmin($this->clienteB);
    rt03_agregar($carrito, $configurable, 1, ['selected_configurable_option' => $variante->id]);

    expect(round(rt03_precio($carrito, $configurable), 2))->toBe(round($base * 0.7, 2));
});

test('T6 / S - precio por cantidad: al subir la cantidad se aplica el tier del grupo del cliente del carrito', function () {
    $producto = rt03_simple([
        $this->grupoA->id => [1 => RT03_A, 3 => 7000],
        $this->grupoB->id => [1 => RT03_B, 3 => 6500],
    ]);
    rt03_sesionCliente($this->clienteA);
    $carrito = rt03_carritoAdmin($this->clienteB);
    rt03_agregar($carrito, $producto, 1);

    $item = CartItem::query()->where('cart_id', $carrito->id)->firstOrFail();
    putJson(route('admin.sales.cart.items.update', $carrito->id), ['qty' => [$item->id => 3]])->assertOk();

    expect((int) $item->fresh()->quantity)->toBe(3)
        ->and(rt03_precio($carrito, $producto))->toBe(6500.0);
});

test('T7 / T - regla de carrito del grupo B: se aplica al carrito de B aunque la sesion sea A, y no al de A', function () {
    $producto = rt03_simple([]);

    CartRule::factory()->afterCreating(function (CartRule $regla) {
        $regla->cart_rule_customer_groups()->sync([$this->grupoB->id]);
        $regla->cart_rule_channels()->sync([core()->getDefaultChannel()->id]);
    })->create([
        'name' => 'RT03 solo grupo B', 'description' => 'RT03', 'action_type' => 'by_fixed',
        'discount_amount' => 100, 'usage_per_customer' => 0, 'uses_per_coupon' => 0,
        'condition_type' => 1, 'status' => 1, 'discount_quantity' => 0, 'apply_to_shipping' => 0,
        'use_auto_generation' => 0, 'times_used' => 0, 'coupon_type' => 0, 'end_other_rules' => 0,
        'uses_attribute_conditions' => 0, 'discount_step' => 0, 'free_shipping' => 0, 'sort_order' => 0,
        'conditions' => [], 'starts_from' => null, 'ends_till' => null,
    ]);

    rt03_sesionCliente($this->clienteA);
    $carritoB = rt03_carritoAdmin($this->clienteB);
    rt03_agregar($carritoB, $producto);
    $carritoA = rt03_carritoAdmin($this->clienteA);
    rt03_agregar($carritoA, $producto);

    expect((float) $carritoB->fresh()->discount_amount)->toBe(100.0)
        ->and((float) $carritoB->fresh()->grand_total)->toBe(RT03_BASE - 100)
        ->and((float) $carritoA->fresh()->discount_amount)->toBe(0.0);
});

test('T8 / U - el collectTotals de OrderController::store usa el grupo del cliente del carrito', function () {
    $producto = rt03_simple([$this->grupoA->id => [1 => RT03_A], $this->grupoB->id => [1 => RT03_B]]);
    rt03_sesionCliente($this->clienteB);
    $carrito = rt03_carritoAdmin($this->clienteB);
    rt03_agregar($carrito, $producto);
    $pedidosAntes = Order::query()->count();

    // Otra pestaña hace "Iniciar sesion como" A; se pulsa "Crear pedido" en el carrito de B.
    rt03_sesionCliente($this->clienteA);

    // Sin direccion: store hace collectTotals (l.89) y luego validateOrder falla (400), sin pedido.
    postJson(route('admin.sales.orders.store', $carrito->id))->assertStatus(400);

    expect(rt03_precio($carrito, $producto))->toBe(RT03_B)
        ->and(Order::query()->count())->toBe($pedidosAntes);
});

test('T9 / N - storefront sin regresion: el cliente autenticado se precia con su propio grupo', function () {
    $producto = rt03_simple([$this->grupoA->id => [1 => RT03_A]]);
    auth()->guard('admin')->logout();
    rt03_sesionCliente($this->clienteA);

    postJson(route('shop.api.checkout.cart.store'), ['product_id' => $producto->id, 'quantity' => 1])->assertOk();

    expect(app(ContextoPrecioComercial::class)->activo())->toBeFalse()
        ->and((int) app(CustomerRepository::class)->getCurrentGroup()->id)->toBe((int) $this->grupoA->id)
        ->and((float) CartItem::query()->where('product_id', $producto->id)->value('price'))->toBe(RT03_A);
});

test('T10 / H - cambio de grupo del mismo cliente: el siguiente recalculo usa el grupo nuevo, con o sin sesion', function () {
    $producto = rt03_simple([$this->grupoA->id => [1 => RT03_A], $this->grupoB->id => [1 => RT03_B]]);
    $carrito = rt03_carritoAdmin($this->clienteA);
    rt03_agregar($carrito, $producto);

    expect(rt03_precio($carrito, $producto))->toBe(RT03_A);

    $this->clienteA->update(['customer_group_id' => $this->grupoB->id]);

    expect(rt03_precio(rt03_recalcularPanel($carrito), $producto))->toBe(RT03_B);

    rt03_sesionCliente($this->clienteA);

    expect(rt03_precio(rt03_recalcularPanel($carrito), $producto))->toBe(RT03_B);
});

test('MEZCLA - un carrito con precios de dos grupos queda normalizado al grupo del cliente en el siguiente collectTotals', function () {
    $p1 = rt03_simple([$this->grupoA->id => [1 => RT03_A], $this->grupoB->id => [1 => RT03_B]]);
    $p2 = rt03_simple([$this->grupoA->id => [1 => RT03_A], $this->grupoB->id => [1 => RT03_B]]);
    $carrito = rt03_carritoAdmin($this->clienteA);
    rt03_agregar($carrito, $p1);
    rt03_agregar($carrito, $p2);

    // Estado inconsistente simulado: el item 2 guardado con el precio del grupo B.
    CartItem::query()->where('cart_id', $carrito->id)->where('product_id', $p2->id)
        ->update(['price' => RT03_B, 'base_price' => RT03_B, 'base_price_incl_tax' => RT03_B]);

    rt03_sesionCliente($this->clienteB);
    $carrito = rt03_recalcularPanel($carrito);

    expect([rt03_precio($carrito, $p1), rt03_precio($carrito, $p2)])->toBe([RT03_A, RT03_A]);
});

test('W - carrito del cliente desde el admin: quitar un item recalcula con el grupo del cliente dueño', function () {
    $p1 = rt03_simple([$this->grupoA->id => [1 => RT03_A], $this->grupoB->id => [1 => RT03_B]]);
    $p2 = rt03_simple([$this->grupoA->id => [1 => RT03_A], $this->grupoB->id => [1 => RT03_B]]);
    $carrito = rt03_carritoAdmin($this->clienteB);
    rt03_agregar($carrito, $p1);
    rt03_agregar($carrito, $p2);
    $item2 = CartItem::query()->where('cart_id', $carrito->id)->where('product_id', $p2->id)->firstOrFail();

    rt03_sesionCliente($this->clienteA);

    postJson(route('admin.customers.customers.cart.items.delete', $this->clienteB->id), ['item_id' => $item2->id, '_method' => 'DELETE'])
        ->assertOk();

    expect(rt03_precio($carrito, $p1))->toBe(RT03_B)
        ->and(CartItem::query()->whereKey($item2->id)->exists())->toBeFalse();
});

test('W - carrito del cliente desde el admin: un item de un carrito ajeno se rechaza (fail closed, 409)', function () {
    $producto = rt03_simple([]);
    $carritoB = rt03_carritoAdmin($this->clienteB);
    rt03_agregar($carritoB, $producto);
    $item = CartItem::query()->where('cart_id', $carritoB->id)->firstOrFail();

    postJson(route('admin.customers.customers.cart.items.delete', $this->clienteA->id), ['item_id' => $item->id, '_method' => 'DELETE'])
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'cliente_no_coincide');

    expect(CartItem::query()->whereKey($item->id)->exists())->toBeTrue();
});

test('GET del carrito no reprecia ni necesita contexto', function () {
    $producto = rt03_simple([$this->grupoA->id => [1 => RT03_A]]);
    $carrito = rt03_carritoAdmin($this->clienteA);
    rt03_agregar($carrito, $producto);

    getJson(route('admin.sales.cart.index', $carrito->id))->assertOk();

    expect(rt03_precio($carrito, $producto))->toBe(RT03_A)
        ->and(app(ContextoPrecioComercial::class)->activo())->toBeFalse();
});

test('el servicio del futuro usa durante() sin rutas: el carrito se recalcula con su cliente', function () {
    $producto = rt03_simple([$this->grupoA->id => [1 => RT03_A], $this->grupoB->id => [1 => RT03_B]]);
    $carrito = rt03_carritoAdmin($this->clienteB);
    rt03_agregar($carrito, $producto);
    CartItem::query()->where('cart_id', $carrito->id)->update(['price' => 1, 'base_price' => 1, 'base_price_incl_tax' => 1]);
    rt03_sesionCliente($this->clienteA);

    app(ContextoPrecioComercial::class)->durante($carrito->id, $this->clienteB->id, function () use ($carrito) {
        Cart::setCart($carrito->fresh());
        Cart::collectTotals();
    });

    expect(rt03_precio($carrito, $producto))->toBe(RT03_B);
});

test('CartRule - el helper se crea de nuevo en cada collectTotals: su cache no cruza operaciones', function () {
    $producto = rt03_simple([]);
    $carrito = rt03_carritoAdmin($this->clienteA);
    rt03_agregar($carrito, $producto);

    $creados = 0;
    app()->resolving(Webkul\CartRule\Helpers\CartRule::class, function () use (&$creados) {
        $creados++;
    });

    Cart::setCart($carrito->fresh());
    Cart::collectTotals();
    Cart::collectTotals();

    expect($creados)->toBe(2);
});
