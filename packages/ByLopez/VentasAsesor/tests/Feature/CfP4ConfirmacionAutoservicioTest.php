<?php

use ByLopez\VentasAsesor\Models\AtribucionPedido;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Services\Clientes\ClienteAccesoService;
use ByLopez\VentasAsesor\Support\Autoservicio\RolClienteFrecuente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Customer\Models\CustomerAddress;
use Webkul\Sales\Models\Order;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

require_once __DIR__.'/../helpersFase03f.php';
require_once __DIR__.'/../helpersGeo.php';

uses(TestCase::class);

/* P4 (doc. 33 §11): confirmacion del cliente: recogida + transferencia, EMPRESA, sin dinero, idempotente. Base exclusiva. */

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();
    geo_cargar();
    Role::query()->where('name', RolClienteFrecuente::NOMBRE)->delete();

    $this->gestor = f03b_admin('custom', [ClienteAccesoService::PERMISO]);
    $grupo = f03b_grupo('G');
    $this->cliente = f03b_cliente($grupo, ['first_name' => 'Ana', 'last_name' => 'Compra', 'email' => 'c.'.uniqid().'@example.test']);
    CustomerAddress::factory()->create([
        'customer_id' => $this->cliente->id, 'address_type' => 'customer', 'default_address' => 1,
        'first_name' => 'Ana', 'last_name' => 'Compra', 'phone' => '3001234567', 'address' => 'Calle 1 # 2-3',
    ]);
    $this->user = Admin::query()->find(app(ClienteAccesoService::class)->crearUsuario($this->cliente->id, $this->gestor)['usuario']['id']);

    $this->configurable = f03b_configurable(10000.0);
    $this->variantes = $this->configurable->variants()->get();
    foreach ($this->variantes as $v) {
        f03b_stock($v, 50);
    }
    $this->v = $this->variantes->first();
});

function cf4_venta($t, ?int $cant = 2): int
{
    return $t->actingAs($t->user, 'admin')->postJson(route('admin.autoservicio.iniciar_con_tallas'), [
        'referencia' => (string) Illuminate\Support\Str::uuid(),
        'producto_id' => $t->configurable->id,
        'cantidades' => [$t->v->id => $cant],
    ])->assertOk()->json('borrador.id');
}

test('P4 preparar aplica recogida y transferencia sin que el cliente elija nada y devuelve el total', function () {
    $id = cf4_venta($this);
    $estado = $this->actingAs($this->user, 'admin')->postJson(route('admin.autoservicio.preparar', ['borrador' => $id]))->assertOk()->json();

    expect($estado['total_esperado'])->toBeGreaterThan(0)
        ->and($estado['entrega']['metodo_seleccionado']['codigo'])->toBe('pickup')
        ->and($estado['pago']['metodo_seleccionado']['codigo'])->toBe('moneytransfer')
        ->and($estado['confirmable'])->toBeTrue();
});

test('P4 confirmar crea UN pedido del cliente, EMPRESA, recogida, transferencia y SIN recaudo registrado', function () {
    $id = cf4_venta($this);
    $total = $this->actingAs($this->user, 'admin')->postJson(route('admin.autoservicio.preparar', ['borrador' => $id]))->json('total_esperado');

    $r = $this->actingAs($this->user, 'admin')->postJson(route('admin.autoservicio.confirmar', ['borrador' => $id]), ['total_esperado' => $total])->assertOk()->json();

    $orden = Order::query()->findOrFail($r['order_id']);
    $atrib = AtribucionPedido::query()->where('order_id', $orden->id)->firstOrFail();

    expect((int) $orden->customer_id)->toBe($this->cliente->id)
        ->and($orden->shipping_method)->toContain('pickup')
        ->and(DB::table('order_payment')->where('order_id', $orden->id)->value('method'))->toBe('moneytransfer')
        ->and($atrib->commercial_owner_type->value)->toBe('company')
        ->and($atrib->advisor_id)->toBeNull()
        ->and($atrib->sales_channel->value)->toBe('ecommerce')
        ->and(DB::table('bylopez_va_pagos')->where('customer_id', $this->cliente->id)->count())->toBe(0)
        ->and(array_keys($r))->toEqualCanonicalizing(['order_id', 'numero', 'total', 'moneda', 'entrega', 'pago', 'estado_pago', 'ya_existia'])
        ->and(BorradorVenta::query()->find($id)->estado->value)->toBe('confirmado');
});

test('P4 confirmar dos veces (doble clic o reintento) devuelve el mismo pedido y no crea otro', function () {
    $id = cf4_venta($this);
    $total = $this->actingAs($this->user, 'admin')->postJson(route('admin.autoservicio.preparar', ['borrador' => $id]))->json('total_esperado');
    $ruta = route('admin.autoservicio.confirmar', ['borrador' => $id]);
    $antes = Order::query()->count();

    $a = $this->actingAs($this->user, 'admin')->postJson($ruta, ['total_esperado' => $total])->assertOk()->json();
    $rb = $this->actingAs($this->user, 'admin')->postJson($ruta, ['total_esperado' => $total]); $b = $rb->assertOk()->json();

    expect($b['order_id'])->toBe($a['order_id'])->and($b['ya_existia'])->toBeTrue()->and(Order::query()->count())->toBe($antes + 1);
});

test('P4 un total que no coincide se rechaza con mensaje comercial y no crea pedido', function () {
    $id = cf4_venta($this);
    $antes = Order::query()->count();

    $r = $this->actingAs($this->user, 'admin')->postJson(route('admin.autoservicio.confirmar', ['borrador' => $id]), ['total_esperado' => 1]);

    $r->assertStatus(422);
    expect($r->json('mensaje'))->toBe('El total de tu pedido cambió. Revisa el resumen y confirma de nuevo.')
        ->and(Order::query()->count())->toBe($antes);
});

test('P4 cliente sin direccion registrada: mensaje comercial y ningun pedido', function () {
    CustomerAddress::query()->where('customer_id', $this->cliente->id)->delete();
    $id = cf4_venta($this);
    $antes = Order::query()->count();

    $r = $this->actingAs($this->user, 'admin')->postJson(route('admin.autoservicio.confirmar', ['borrador' => $id]), ['total_esperado' => 100]);

    expect($r->getStatusCode())->toBeGreaterThanOrEqual(400)
        ->and($r->json('mensaje'))->toBe('Necesitamos una dirección para tu pedido. Agrégala con «Agregar mi dirección».')
        ->and(Order::query()->count())->toBe($antes);
});

test('P4 un cliente no confirma la venta de otro', function () {
    $id = cf4_venta($this);
    $otro = f03b_cliente(f03b_grupo('H'), ['email' => 'o.'.uniqid().'@example.test']);
    $userOtro = Admin::query()->find(app(ClienteAccesoService::class)->crearUsuario($otro->id, $this->gestor)['usuario']['id']);
    $antes = Order::query()->count();

    $this->actingAs($userOtro, 'admin')->postJson(route('admin.autoservicio.confirmar', ['borrador' => $id]), ['total_esperado' => 100])->assertStatus(409);
    $this->actingAs($userOtro, 'admin')->postJson(route('admin.autoservicio.preparar', ['borrador' => $id]))->assertStatus(409);

    expect(Order::query()->count())->toBe($antes);
});

test('P4 cliente sin direccion crea UNA el mismo (sin administrador) y el pedido queda confirmable', function () {
    CustomerAddress::query()->where('customer_id', $this->cliente->id)->delete();
    $id = cf4_venta($this);
    $datos = ['nombre' => 'Ana', 'apellido' => 'Compra', 'telefono' => '3001234567', 'lineas' => ['Calle 5 # 6-7'], 'municipio_codigo' => '15542', 'codigo_postal' => '152210'];

    $estado = $this->actingAs($this->user, "admin")->postJson(route("admin.autoservicio.direccion", ["borrador" => $id]), $datos)->assertOk()->json();

    $guardada = CustomerAddress::query()->where('customer_id', $this->cliente->id)->where('address_type', 'customer')->get();

    expect($guardada)->toHaveCount(1)
        ->and((int) $guardada->first()->default_address)->toBe(1)
        ->and($estado['confirmable'])->toBeTrue();

    // UNA sola: con una direccion ya registrada, otra se rechaza y no se guarda.
    $this->actingAs($this->user, 'admin')->postJson(route('admin.autoservicio.direccion', ['borrador' => $id]), $datos)->assertForbidden();
    expect(CustomerAddress::query()->where('customer_id', $this->cliente->id)->where('address_type', 'customer')->count())->toBe(1);
});

test('P4 la direccion del cliente: sin municipio se rechaza y la venta ajena no se toca', function () {
    CustomerAddress::query()->where('customer_id', $this->cliente->id)->delete();
    $id = cf4_venta($this);

    $this->actingAs($this->user, 'admin')->postJson(route('admin.autoservicio.direccion', ['borrador' => $id]), ['nombre' => 'Ana'])->assertStatus(422);
    $this->actingAs($this->user, 'admin')->postJson(route('admin.autoservicio.direccion', ['borrador' => 999999]), ['municipio_codigo' => '15542'])->assertStatus(404);

    expect(CustomerAddress::query()->where('customer_id', $this->cliente->id)->count())->toBe(0);
});
