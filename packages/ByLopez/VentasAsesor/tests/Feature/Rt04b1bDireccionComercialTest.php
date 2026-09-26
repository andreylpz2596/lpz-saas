<?php

use ByLopez\VentasAsesor\DataObjects\DireccionComercial;
use ByLopez\VentasAsesor\Exceptions\DatosClienteComercialInvalidosException;
use ByLopez\VentasAsesor\Services\Clientes\GuardarDireccionClienteComercialService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\Cart as CartModel;
use Webkul\Checkout\Models\CartAddress;
use Webkul\Customer\Models\Customer;
use Webkul\Customer\Models\CustomerAddress;

require_once __DIR__.'/../helpersRt04b1b.php';

uses(TestCase::class);

/*
| RT-04B1b — GuardarDireccionClienteComercialService. Solo lpz_saas_testing.
*/

function rt04b1b_direccion(array $c = []): DireccionComercial
{
    $d = $c + ['nombre' => 'Aurora', 'apellido' => 'Ejemplo', 'lineas' => ['Calle ficticia 1', 'Apto 2'],
        'ciudad' => 'Bogota', 'departamento' => 'DC', 'pais' => 'co', 'postal' => '110111',
        'telefono' => '300 555 0101', 'email' => null, 'predeterminada' => false];

    return new DireccionComercial($d['nombre'], $d['apellido'], $d['lineas'], $d['ciudad'], $d['departamento'],
        $d['pais'], $d['postal'], $d['telefono'], $d['email'], null, $d['predeterminada']);
}

beforeEach(function () {
    $this->servicio = app(GuardarDireccionClienteComercialService::class);
    $this->cliente = Customer::factory()->create(['phone' => '+573009998877', 'email' => null]);
});

test('crear: direccion de cliente (address_type customer) con email NULL y el telefono indicado en E.164', function () {
    $dir = $this->servicio->guardar($this->cliente->id, rt04b1b_direccion(['telefono' => '0057 311 222 3344']));
    $fila = DB::table('addresses')->where('id', $dir->id)->first();

    expect($fila->address_type)->toBe(CustomerAddress::ADDRESS_TYPE)
        ->and((int) $fila->customer_id)->toBe($this->cliente->id)
        ->and($fila->email)->toBeNull()
        ->and($fila->phone)->toBe('+573112223344')        // no el principal del cliente
        ->and($fila->address)->toBe('Calle ficticia 1'.PHP_EOL.'Apto 2')
        ->and($fila->country)->toBe('CO')
        ->and((int) $fila->default_address)->toBe(0);
});

test('actualizar: solo una direccion del mismo cliente; la de otro cliente se rechaza', function () {
    $dir = $this->servicio->guardar($this->cliente->id, rt04b1b_direccion());
    $otro = Customer::factory()->create();

    $actualizada = $this->servicio->guardar($this->cliente->id, rt04b1b_direccion(['ciudad' => 'Medellin', 'email' => 'dir@example.test']), $dir->id);

    expect($actualizada->id)->toBe($dir->id)
        ->and($actualizada->city)->toBe('Medellin')
        ->and($actualizada->email)->toBe('dir@example.test')
        ->and(fn () => $this->servicio->guardar($otro->id, rt04b1b_direccion(), $dir->id))
        ->toThrow(DatosClienteComercialInvalidosException::class);
});

test('predeterminada: desmarca la anterior SOLO de ese cliente', function () {
    $otro = Customer::factory()->create();
    $ajena = $this->servicio->guardar($otro->id, rt04b1b_direccion(['predeterminada' => true]));
    $primera = $this->servicio->guardar($this->cliente->id, rt04b1b_direccion(['predeterminada' => true]));

    $segunda = $this->servicio->guardar($this->cliente->id, rt04b1b_direccion(['predeterminada' => true]));

    expect((bool) $primera->fresh()->default_address)->toBeFalse()
        ->and((bool) $segunda->fresh()->default_address)->toBeTrue()
        ->and((bool) $ajena->fresh()->default_address)->toBeTrue();
});

test('validacion: campos requeridos, postcode con la regla del core, telefono y email, con codigos por campo', function () {
    try {
        $this->servicio->guardar($this->cliente->id, rt04b1b_direccion([
            'lineas' => ['  '], 'ciudad' => '', 'postal' => '#11', 'telefono' => '2345678', 'email' => 'mal',
        ]));
        $this->fail('Debio rechazarse');
    } catch (DatosClienteComercialInvalidosException $e) {
        expect($e->errores)->toBe([
            'ciudad' => 'requerido', 'lineas' => 'requerido', 'codigo_postal' => 'formato_invalido',
            'telefono' => 'telefono_no_normalizable', 'email' => 'email_invalido',
        ]);
    }

    expect(CustomerAddress::query()->where('customer_id', $this->cliente->id)->count())->toBe(0);
});

test('eventos del core: customer.addresses.create.* y update.* (mismos que el panel)', function () {
    Event::fake(['customer.addresses.create.before', 'customer.addresses.create.after',
        'customer.addresses.update.before', 'customer.addresses.update.after']);

    $dir = $this->servicio->guardar($this->cliente->id, rt04b1b_direccion());
    $this->servicio->guardar($this->cliente->id, rt04b1b_direccion(), $dir->id);

    foreach (['create.before', 'create.after', 'update.before', 'update.after'] as $e) {
        Event::assertDispatchedTimes('customer.addresses.'.$e, 1);
    }
});

test('compatibilidad Bagisto: la direccion guardada sirve tal cual como origen de Cart::saveAddresses (email NULL incluido)', function () {
    $dir = $this->servicio->guardar($this->cliente->id, rt04b1b_direccion());
    $carrito = CartModel::factory()->create(['customer_id' => $this->cliente->id, 'customer_email' => null, 'is_guest' => 0]);

    Cart::setCart($carrito);
    Cart::saveAddresses(['billing' => array_merge($dir->only([
        'first_name', 'last_name', 'email', 'country', 'state', 'city', 'postcode', 'phone',
    ]), ['address' => explode(PHP_EOL, $dir->address), 'address_type' => 'customer', 'id' => $dir->id, 'use_for_shipping' => true])]);

    $billing = CartAddress::query()->where('cart_id', $carrito->id)->where('address_type', CartAddress::ADDRESS_TYPE_BILLING)->sole();

    expect($billing->parent_address_id)->toBe($dir->id)
        ->and($billing->phone)->toBe($dir->phone)
        ->and($billing->email)->toBeNull()
        ->and($carrito->fresh()->customer_email)->toBeNull();   // NULL, nunca ""
});
