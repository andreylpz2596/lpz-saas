<?php

use ByLopez\Logistica\Services\Despachos\ConsultaDespachoService;
use ByLopez\VentasAsesor\Services\Clientes\ClienteAccesoService;
use ByLopez\VentasAsesor\Support\Autoservicio\RolClienteFrecuente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Customer\Models\CustomerAddress;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

require_once __DIR__.'/../helpersFase03f.php';
require_once __DIR__.'/../helpersGeo.php';

uses(TestCase::class);

/* P6 (doc. 33 §16): el pedido del cliente llega a Bodega por el flujo existente; los permisos de despacho no cambian. Base exclusiva. */

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();
    geo_cargar();
    Role::query()->where('name', RolClienteFrecuente::NOMBRE)->delete();

    $this->gestor = f03b_admin('custom', [ClienteAccesoService::PERMISO]);
    $this->cliente = f03b_cliente(f03b_grupo('G'), ['first_name' => 'Ana', 'last_name' => 'Bodega', 'email' => 'bod.'.uniqid().'@example.test']);
    CustomerAddress::factory()->create(['customer_id' => $this->cliente->id, 'address_type' => 'customer', 'default_address' => 1, 'first_name' => 'Ana', 'last_name' => 'Bodega', 'phone' => '3001234567', 'address' => 'Calle 1']);
    $this->user = Admin::query()->find(app(ClienteAccesoService::class)->crearUsuario($this->cliente->id, $this->gestor)['usuario']['id']);

    $this->configurable = f03b_configurable(10000.0);
    foreach ($this->configurable->variants()->get() as $v) {
        f03b_stock($v, 50);
    }
    $this->v = $this->configurable->variants()->get()->first();
});

function cf6_pedido($t): int
{
    $id = $t->actingAs($t->user, 'admin')->postJson(route('admin.autoservicio.iniciar_con_tallas'), [
        'referencia' => (string) Illuminate\Support\Str::uuid(), 'producto_id' => $t->configurable->id, 'cantidades' => [$t->v->id => 3],
    ])->assertOk()->json('borrador.id');
    $total = $t->actingAs($t->user, 'admin')->postJson(route('admin.autoservicio.preparar', ['borrador' => $id]))->json('total_esperado');

    return $t->actingAs($t->user, 'admin')->postJson(route('admin.autoservicio.confirmar', ['borrador' => $id]), ['total_esperado' => $total])->assertOk()->json('order_id');
}

test('P6 el pedido de autoservicio aparece en Despachos (Bodega) como recogida pendiente, sin depender de asesor', function () {
    $orderId = cf6_pedido($this);

    $numero = (string) DB::table('orders')->where('id', $orderId)->value('increment_id');
    $filas = app(ConsultaDespachoService::class)->listar(['estado' => 'por_despachar', 'tipo' => 'recogida', 'q' => $numero], 1);
    $ids = collect($filas->items())->map(fn ($f) => (int) $f->id)->all();

    expect($ids)->toContain($orderId);
});

test('P6 Bodega abre la pantalla de despachos y el contexto del pedido (recogida) sin errores', function () {
    $orderId = cf6_pedido($this);
    $bodega = f03b_admin('all');

    $this->actingAs($bodega, 'admin')->get(route('admin.logistica.despachos.index'))->assertOk();
    $r = $this->actingAs($bodega, 'admin')->getJson(route('admin.logistica.despachos.contexto', ['pedido' => $orderId]))->assertOk();

    expect($r->json('contexto.pedido.es_recogida'))->toBeTrue()->and($r->json('contexto.modo'))->toBe('entregar_bodega');
});

test('P6 el pedido llega con reserva de stock como cualquier otro y sin recaudo', function () {
    $orderId = cf6_pedido($this);

    expect(DB::table('order_items')->where('order_id', $orderId)->whereNull('parent_id')->sum('qty_ordered'))->toEqual(3)
        ->and(DB::table('bylopez_va_pagos')->where('customer_id', $this->cliente->id)->count())->toBe(0)
        ->and(DB::table('bylopez_va_order_attributions')->where('order_id', $orderId)->value('commercial_owner_type'))->toBe('company');
});

test('P6 el cliente de autoservicio no puede despachar ni ver Logistica', function () {
    $orderId = cf6_pedido($this);

    foreach ([
        ['GET', route('admin.logistica.despachos.index')],
        ['GET', route('admin.logistica.despachos.contexto', ['pedido' => $orderId])],
        ['POST', route('admin.logistica.despachos.despachar', ['pedido' => $orderId])],
        ['POST', route('admin.logistica.despachos.entregar_bodega', ['pedido' => $orderId])],
    ] as $r) {
        expect($this->actingAs($this->user, 'admin')->json($r[0], $r[1], [])->getStatusCode())->toBeIn([401, 403]);
    }
});
