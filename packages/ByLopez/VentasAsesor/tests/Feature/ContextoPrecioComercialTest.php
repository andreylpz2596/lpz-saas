<?php

use ByLopez\VentasAsesor\Exceptions\ContextoPrecioComercialInvalidoException;
use ByLopez\VentasAsesor\Listeners\FijarContextoPrecioEnRutasDelPanel;
use ByLopez\VentasAsesor\Support\ContextoPrecioComercial;
use ByLopez\VentasAsesor\Support\CustomerRepositoryConContextoComercial;
use ByLopez\VentasAsesor\Support\RutasDePrecioDelPanel;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\CartRule\Helpers\CartRule as CartRuleHelper;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\Cart as CartModel;
use Webkul\Customer\Models\Customer;
use Webkul\Customer\Models\CustomerGroup;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Type\Configurable;
use Webkul\Product\Type\Simple;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| RT-03B — ContextoPrecioComercial, binding y adaptador del panel
|--------------------------------------------------------------------------
| Solo sobre lpz_saas_testing.
*/

function cpc_grupo(): CustomerGroup
{
    return CustomerGroup::factory()->create(['code' => 'cpc_'.uniqid(), 'name' => 'CPC', 'is_user_defined' => 1]);
}

function cpc_carrito(Customer $cliente): CartModel
{
    return CartModel::factory()->create([
        'customer_id' => $cliente->id, 'customer_email' => $cliente->email,
        'customer_first_name' => $cliente->first_name, 'customer_last_name' => $cliente->last_name,
        'is_guest' => 0, 'is_active' => 0,
    ]);
}

function cpc_contexto(): ContextoPrecioComercial
{
    return app(ContextoPrecioComercial::class);
}

function cpc_grupoEfectivo(): int
{
    return (int) app(CustomerRepository::class)->getCurrentGroup()->id;
}

function cpc_rutaCoincidente(string $nombre, array $parametros, array $entrada = []): RouteMatched
{
    $peticion = Request::create('/cpc', 'POST', $entrada);
    $ruta = new Route(['POST'], '/cpc/{id?}/{cartId?}', ['as' => $nombre]);
    $ruta->bind($peticion);

    foreach ($parametros as $clave => $valor) {
        $ruta->setParameter($clave, $valor);
    }

    return new RouteMatched($ruta, $peticion);
}

beforeEach(function () {
    $this->grupoA = cpc_grupo();
    $this->grupoB = cpc_grupo();
    $this->clienteA = Customer::factory()->create(['customer_group_id' => $this->grupoA->id]);
    $this->clienteB = Customer::factory()->create(['customer_group_id' => $this->grupoB->id]);
    $this->carritoA = cpc_carrito($this->clienteA);
    $this->carritoB = cpc_carrito($this->clienteB);
    $this->invitado = (int) core()->getGuestCustomerGroup()->id;
});

test('binding - todos los consumidores del core reciben el repositorio con contexto (sin tocar Webkul)', function () {
    $propiedad = fn (object $objeto) => (fn () => $this->customerRepository)->call($objeto);

    expect(app(CustomerRepository::class))->toBeInstanceOf(CustomerRepositoryConContextoComercial::class)
        ->and($propiedad(app(Simple::class)))->toBeInstanceOf(CustomerRepositoryConContextoComercial::class)
        ->and($propiedad(app(Configurable::class)))->toBeInstanceOf(CustomerRepositoryConContextoComercial::class)
        ->and($propiedad(app(CartRuleHelper::class)))->toBeInstanceOf(CustomerRepositoryConContextoComercial::class)
        ->and($propiedad(app(ProductRepository::class)))->toBeInstanceOf(CustomerRepositoryConContextoComercial::class);
});

test('A - sin contexto el repositorio conserva exactamente el comportamiento del core', function () {
    $core = fn () => (int) (auth()->guard()->user()?->group ?? core()->getGuestCustomerGroup())->id;

    expect(cpc_contexto()->activo())->toBeFalse()
        ->and(cpc_grupoEfectivo())->toBe($core())->toBe($this->invitado);

    auth()->guard('customer')->login($this->clienteB);

    expect(cpc_grupoEfectivo())->toBe($core())->toBe((int) $this->grupoB->id);
});

test('B, C y D - contexto valido: gana el cliente del carrito, con o sin cliente en sesion', function () {
    cpc_contexto()->durante($this->carritoA->id, $this->clienteA->id, fn () => expect(cpc_grupoEfectivo())->toBe((int) $this->grupoA->id));

    auth()->guard('customer')->login($this->clienteB);

    cpc_contexto()->durante($this->carritoA->id, $this->clienteA->id, fn () => expect(cpc_grupoEfectivo())->toBe((int) $this->grupoA->id));
});

test('E y F - al terminar durante() (tambien si lanza) vuelve el comportamiento del core', function () {
    cpc_contexto()->durante($this->carritoA->id, $this->clienteA->id, fn () => null);

    expect(cpc_contexto()->activo())->toBeFalse()->and(cpc_grupoEfectivo())->toBe($this->invitado);

    expect(fn () => cpc_contexto()->durante($this->carritoA->id, $this->clienteA->id, function () {
        throw new RuntimeException('falla dentro de la operacion');
    }))->toThrow(RuntimeException::class, 'falla dentro de la operacion');

    expect(cpc_contexto()->activo())->toBeFalse()->and(cpc_grupoEfectivo())->toBe($this->invitado);
});

test('G - contextos anidados se restauran en orden', function () {
    cpc_contexto()->durante($this->carritoA->id, $this->clienteA->id, function () {
        expect(cpc_grupoEfectivo())->toBe((int) $this->grupoA->id);

        cpc_contexto()->durante($this->carritoB->id, $this->clienteB->id, fn () => expect(cpc_grupoEfectivo())->toBe((int) $this->grupoB->id));

        expect(cpc_grupoEfectivo())->toBe((int) $this->grupoA->id);
    });

    expect(cpc_contexto()->activo())->toBeFalse();
});

test('H e I - el grupo se resuelve una vez por operacion y se relee en la siguiente', function () {
    cpc_contexto()->durante($this->carritoA->id, $this->clienteA->id, function () {
        expect(cpc_grupoEfectivo())->toBe((int) $this->grupoA->id);

        // Cambio a mitad de la operacion: la operacion en curso sigue siendo coherente (A).
        $this->clienteA->update(['customer_group_id' => $this->grupoB->id]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        expect(cpc_grupoEfectivo())->toBe((int) $this->grupoA->id);
        expect(DB::getQueryLog())->toBe([]);   // sin consultas: grupo cacheado en la operacion
        DB::disableQueryLog();
    });

    // Operacion nueva: lee el grupo actual (B).
    cpc_contexto()->durante($this->carritoA->id, $this->clienteA->id, fn () => expect(cpc_grupoEfectivo())->toBe((int) $this->grupoB->id));
});

test('J, K, L - contexto explicito inconsistente: fail closed, nunca sesion ni invitado', function (string $caso, string $codigo) {
    auth()->guard('customer')->login($this->clienteB);

    [$cartId, $customerId] = match ($caso) {
        'cart.customer_id distinto' => [$this->carritoA->id, $this->clienteB->id],
        'carrito inexistente' => [999999999, $this->clienteA->id],
        'cliente inexistente' => [$this->carritoA->id, 999999999],
        'carrito sin cliente' => [CartModel::factory()->create(['customer_id' => null, 'is_guest' => 1])->id, $this->clienteA->id],
    };

    $ejecutada = false;

    expect(fn () => cpc_contexto()->durante($cartId, $customerId, function () use (&$ejecutada) {
        $ejecutada = true;
    }))->toThrow(fn (ContextoPrecioComercialInvalidoException $e) => expect($e->codigo)->toBe($codigo));

    expect($ejecutada)->toBeFalse()->and(cpc_contexto()->activo())->toBeFalse();
})->with([
    ['cart.customer_id distinto', ContextoPrecioComercialInvalidoException::CLIENTE_NO_COINCIDE],
    ['carrito inexistente', ContextoPrecioComercialInvalidoException::CARRITO_INEXISTENTE],
    ['cliente inexistente', ContextoPrecioComercialInvalidoException::CLIENTE_NO_COINCIDE],
    ['carrito sin cliente', ContextoPrecioComercialInvalidoException::CARRITO_SIN_CLIENTE],
]);

test('L - cliente borrado despues de declarar el contexto: fail closed al resolver el grupo', function () {
    expect(fn () => cpc_contexto()->durante($this->carritoA->id, $this->clienteA->id, function () {
        DB::table('customers')->where('id', $this->clienteA->id)->delete();

        return cpc_grupoEfectivo();
    }))->toThrow(fn (ContextoPrecioComercialInvalidoException $e) => expect($e->codigo)->toBe(ContextoPrecioComercialInvalidoException::CLIENTE_INEXISTENTE));
});

test('M - cliente sin grupo (o con grupo borrado): grupo invitado, como el core', function () {
    DB::table('customers')->where('id', $this->clienteA->id)->update(['customer_group_id' => null]);

    cpc_contexto()->durante($this->carritoA->id, $this->clienteA->id, fn () => expect(cpc_grupoEfectivo())->toBe($this->invitado));
});

test('jobs - el contexto no depende de sesion, ruta ni del carrito residual del facade', function () {
    Cart::setCart($this->carritoB);   // carrito residual de "otro job"
    auth()->guard('customer')->login($this->clienteB);

    cpc_contexto()->durante($this->carritoA->id, $this->clienteA->id, fn () => expect(cpc_grupoEfectivo())->toBe((int) $this->grupoA->id));

    // Sin contexto no se lee el carrito residual: manda el core (sesion B).
    expect(cpc_grupoEfectivo())->toBe((int) $this->grupoB->id);
});

test('P y Q - adaptador: solo las rutas exactas fijan contexto; el resto (y rutas futuras) no', function () {
    $adaptador = new FijarContextoPrecioEnRutasDelPanel;

    foreach (['admin.catalog.products.search', 'admin.sales.cart.index', 'admin.sales.orders.create', 'admin.sales.cart.store', 'admin.sales.cart.nueva_ruta', 'shop.checkout.cart.index'] as $ruta) {
        $adaptador->handle(cpc_rutaCoincidente($ruta, ['id' => $this->carritoA->id]));

        expect(cpc_contexto()->activo())->toBeFalse($ruta);
    }

    $adaptador->handle(cpc_rutaCoincidente('admin.sales.cart.items.store', ['id' => $this->carritoA->id]));
    expect(cpc_contexto()->identificacion())->toBe(['tipo' => 'carrito', 'cart_id' => $this->carritoA->id, 'customer_id' => $this->clienteA->id]);

    $adaptador->handle(cpc_rutaCoincidente('admin.sales.orders.store', ['cartId' => $this->carritoB->id]));
    expect(cpc_contexto()->identificacion())->toBe(['tipo' => 'carrito', 'cart_id' => $this->carritoB->id, 'customer_id' => $this->clienteB->id]);

    // Una peticion posterior a otra ruta empieza limpia; RequestHandled tambien limpia.
    $adaptador->handle(cpc_rutaCoincidente('admin.catalog.products.search', []));
    expect(cpc_contexto()->activo())->toBeFalse();

    $adaptador->handle(cpc_rutaCoincidente('admin.sales.cart.items.store', ['id' => $this->carritoA->id]));
    $adaptador->limpiar();
    expect(cpc_contexto()->activo())->toBeFalse();

    expect(RutasDePrecioDelPanel::todas())->toHaveCount(11)
        ->and(collect(RutasDePrecioDelPanel::todas())->filter(fn ($r) => str_contains($r, '*')))->toBeEmpty();
});

test('adaptador - carrito inexistente: no fija contexto (no hay nada que preciar); carrito sin cliente: 409', function () {
    $adaptador = new FijarContextoPrecioEnRutasDelPanel;

    $adaptador->handle(cpc_rutaCoincidente('admin.sales.cart.items.store', ['id' => 999999999]));
    expect(cpc_contexto()->activo())->toBeFalse();

    $sinCliente = CartModel::factory()->create(['customer_id' => null, 'is_guest' => 1]);

    expect(fn () => $adaptador->handle(cpc_rutaCoincidente('admin.sales.cart.items.store', ['id' => $sinCliente->id])))
        ->toThrow(ContextoPrecioComercialInvalidoException::class);
});

test('todas las rutas de la lista existen en el router actual', function () {
    $nombres = collect(app('router')->getRoutes()->getRoutes())->map->getName()->filter()->all();

    foreach (RutasDePrecioDelPanel::todas() as $ruta) {
        expect($nombres)->toContain($ruta);
    }
});
