<?php

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

/* P5 (doc. 33 §13): «Mis pedidos» solo de su cliente, con datos comerciales y nada interno. Base exclusiva. */

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();
    geo_cargar();
    Role::query()->where('name', RolClienteFrecuente::NOMBRE)->delete();

    $this->gestor = f03b_admin('custom', [ClienteAccesoService::PERMISO]);
    $this->configurable = f03b_configurable(10000.0);
    $this->v = $this->configurable->variants()->get()->first();
    foreach ($this->configurable->variants()->get() as $v) {
        f03b_stock($v, 50);
    }

    $nuevo = function (string $n) {
        $c = f03b_cliente(f03b_grupo('G'), ['first_name' => $n, 'last_name' => 'Pedidos', 'email' => strtolower($n).'.'.uniqid().'@example.test']);
        CustomerAddress::factory()->create(['customer_id' => $c->id, 'address_type' => 'customer', 'default_address' => 1, 'first_name' => $n, 'last_name' => 'Pedidos', 'phone' => '3001234567', 'address' => 'Calle 1']);

        return [$c, Admin::query()->find(app(ClienteAccesoService::class)->crearUsuario($c->id, $this->gestor)['usuario']['id'])];
    };
    [$this->clienteA, $this->userA] = $nuevo('Ana');
    [$this->clienteB, $this->userB] = $nuevo('Beto');
});

function cf5_pedido($t, $user): int
{
    $id = $t->actingAs($user, 'admin')->postJson(route('admin.autoservicio.iniciar_con_tallas'), [
        'referencia' => (string) Illuminate\Support\Str::uuid(), 'producto_id' => $t->configurable->id, 'cantidades' => [$t->v->id => 2],
    ])->assertOk()->json('borrador.id');
    $total = $t->actingAs($user, 'admin')->postJson(route('admin.autoservicio.preparar', ['borrador' => $id]))->json('total_esperado');

    return $t->actingAs($user, 'admin')->postJson(route('admin.autoservicio.confirmar', ['borrador' => $id]), ['total_esperado' => $total])->assertOk()->json('order_id');
}

test('P5 cada cliente ve solo sus pedidos; el de otro es un 404 indistinguible del inexistente', function () {
    $deA = cf5_pedido($this, $this->userA);
    $deB = cf5_pedido($this, $this->userB);

    $listaA = $this->actingAs($this->userA, 'admin')->getJson(route('admin.autoservicio.pedidos.data'))->assertOk()->json('data');
    expect(collect($listaA)->pluck('id')->all())->toBe([$deA]);

    $this->actingAs($this->userA, 'admin')->getJson(route('admin.autoservicio.pedidos.show', ['pedido' => $deA]))->assertOk();
    $this->actingAs($this->userA, 'admin')->getJson(route('admin.autoservicio.pedidos.show', ['pedido' => $deB]))->assertNotFound();
    $this->actingAs($this->userA, 'admin')->getJson(route('admin.autoservicio.pedidos.show', ['pedido' => 987654321]))->assertNotFound();
});

test('P5 el detalle trae prendas, estado comercial, despacho y pago pendiente sin datos internos', function () {
    $id = cf5_pedido($this, $this->userA);
    $r = $this->actingAs($this->userA, 'admin')->getJson(route('admin.autoservicio.pedidos.show', ['pedido' => $id]))->assertOk();
    $p = $r->json();

    expect($p['estado_pago'])->toBe('Pendiente')
        ->and($p['entrega'])->toBe('Recogida en tienda')
        ->and($p['prendas'])->toHaveCount(1)
        ->and($p['prendas'][0]['cantidad'])->toBe(2)
        ->and($p['unidades'])->toBe(2);

    foreach (['advisor', 'propietario', 'comision', 'costo', 'margen', 'cartera', 'saldo', 'commercial_owner', 'created_by'] as $interno) {
        expect(strtolower($r->getContent()))->not->toContain($interno);
    }
});

test('P5 el estado de pago sale de recaudos confirmados: un abono sin confirmar no cambia Pendiente', function () {
    $id = cf5_pedido($this, $this->userA);
    $r = $this->actingAs($this->userA, 'admin')->getJson(route('admin.autoservicio.pedidos.data'))->json('data.0');

    expect($r['estado_pago'])->toBe('Pendiente')->and(DB::table('bylopez_va_pagos')->where('customer_id', $this->clienteA->id)->count())->toBe(0);
});

test('P5 un usuario de autoservicio no abre el listado de pedidos de asesores ni el de otro cliente por URL directa', function () {
    $deB = cf5_pedido($this, $this->userB);

    foreach ([route('admin.ventas_asesor.pedidos.index'), route('admin.ventas_asesor.pedidos.index').'?pedido='.$deB, route('admin.sales.orders.view', ['id' => $deB])] as $url) {
        expect($this->actingAs($this->userA, 'admin')->get($url)->getStatusCode())->toBeIn([401, 403, 302, 404]);
    }
});

test('P5 la pantalla Mis pedidos carga para el cliente y no para un usuario sin vinculo', function () {
    $this->actingAs($this->userA, 'admin')->get(route('admin.autoservicio.pedidos.index'))->assertOk()->assertSee('Mis pedidos');

    DB::table('bylopez_va_clientes_acceso')->where('admin_id', $this->userA->id)->update(['estado' => 'desvinculado']);
    $this->actingAs($this->userA, 'admin')->getJson(route('admin.autoservicio.pedidos.data'))->assertStatus(403);
});

test('P5 el listado trae el tipo de entrega, se busca por numero y el detalle trae subtotal, envio y destino', function () {
    $id = cf5_pedido($this, $this->userA);
    $numero = DB::table('orders')->where('id', $id)->value('increment_id');
    $ruta = route('admin.autoservicio.pedidos.data');

    $fila = $this->actingAs($this->userA, 'admin')->getJson($ruta)->assertOk()->json('data.0');
    expect($fila['tipo_entrega'])->toBe('Recogida en tienda');

    expect($this->actingAs($this->userA, 'admin')->getJson($ruta.'?q='.urlencode(substr($numero, 0, 6)))->json('total'))->toBe(1)
        ->and($this->actingAs($this->userA, 'admin')->getJson($ruta.'?q=ZZZ-NO-EXISTE')->json('total'))->toBe(0)
        ->and($this->actingAs($this->userA, 'admin')->getJson($ruta.'?q=%25')->json('total'))->toBe(1);

    // El de otro cliente no sale ni buscando su numero exacto.
    $deB = cf5_pedido($this, $this->userB);
    $numeroB = DB::table('orders')->where('id', $deB)->value('increment_id');
    expect($this->actingAs($this->userA, 'admin')->getJson($ruta.'?q='.urlencode($numeroB))->json('total'))->toBe(0);

    $d = $this->actingAs($this->userA, 'admin')->getJson(route('admin.autoservicio.pedidos.show', ['pedido' => $id]))->assertOk()->json();
    expect($d['subtotal'])->toBeGreaterThan(0)->and((float) $d['envio'])->toBe(0.0)->and($d['destino'])->toBeNull()->and($d['metodo_envio'])->toBeNull();
});
