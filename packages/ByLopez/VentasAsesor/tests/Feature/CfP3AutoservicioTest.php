<?php

use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Models\ClienteAcceso;
use ByLopez\VentasAsesor\Services\Clientes\ClienteAccesoService;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Autoservicio\RolClienteFrecuente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

require_once __DIR__.'/../helpersFase03f.php';
require_once __DIR__.'/../helpersGeo.php';

uses(TestCase::class);

/* P3 (doc. 33 §6-7, §14): Nueva venta en autoservicio. HTTP directo, dos clientes y un asesor sinteticos. Base exclusiva. */

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();
    geo_cargar();
    Role::query()->where('name', RolClienteFrecuente::NOMBRE)->delete();

    $this->gestor = f03b_admin('custom', [ClienteAccesoService::PERMISO]);
    $this->asesor = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_MODULO, BorradorVentaService::PERMISO]);
    $grupo = f03b_grupo('G');
    $this->clienteA = f03b_cliente($grupo, ['first_name' => 'Ana', 'last_name' => 'Uno', 'email' => 'a.'.uniqid().'@example.test']);
    $this->clienteB = f03b_cliente($grupo, ['first_name' => 'Beto', 'last_name' => 'Dos', 'email' => 'b.'.uniqid().'@example.test']);

    $habilitar = fn ($c) => Admin::query()->find(app(ClienteAccesoService::class)->crearUsuario($c->id, $this->gestor)['usuario']['id']);
    $this->userA = $habilitar($this->clienteA);
    $this->userB = $habilitar($this->clienteB);

    $this->configurable = f03b_configurable(10000.0);
    $this->variantes = $this->configurable->variants()->get();
    [$this->v1, $this->v2, $this->v3] = $this->variantes->take(3)->values()->all();
    f03b_stock($this->v1, 50);
    f03b_stock($this->v2, 3);
    f03b_stock($this->v3, 0);
    foreach ($this->variantes->slice(3) as $v) {
        f03b_stock($v, 50);
    }
});

function cf3_ruta(string $n, array $p = []): string
{
    return route('admin.autoservicio.'.$n, $p);
}

function cf3_crear($t, $user, array $cantidades, array $extra = [])
{
    return $t->actingAs($user, 'admin')->postJson(cf3_ruta('iniciar_con_tallas'), array_merge([
        'customer_id' => $t->clienteB->id, // intento de elegir otro cliente: debe ignorarse
        'canal' => 'tienda_fisica',
        'referencia' => (string) Illuminate\Support\Str::uuid(),
        'producto_id' => $t->configurable->id,
        'cantidades' => $cantidades,
    ], $extra));
}

test('P3 el cliente sale del vinculo: el customer_id de la peticion se ignora y el canal es ecommerce', function () {
    $r = cf3_crear($this, $this->userA, [$this->v1->id => 2])->assertOk()->json();

    $borrador = BorradorVenta::query()->findOrFail($r['borrador']['id']);

    expect(DB::table('cart')->where('id', $borrador->cart_id)->value('customer_id'))->toBe($this->clienteA->id)
        ->and($borrador->canal_venta->value)->toBe('ecommerce')
        ->and($borrador->propietario_admin_id)->toBe($this->userA->id);
});

test('P3 un cliente no ve ni toca el borrador de otro cliente ni el de un asesor', function () {
    $mio = cf3_crear($this, $this->userA, [$this->v1->id => 1])->assertOk()->json('borrador.id');
    $deAsesor = $this->actingAs($this->asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.iniciar'), ['customer_id' => $this->clienteA->id, 'canal' => 'whatsapp'])->json('borrador.id');

    // B sobre el borrador de A; A sobre el del asesor (aunque sea de su mismo cliente).
    $this->actingAs($this->userB, 'admin')->getJson(cf3_ruta('estado', ['borrador' => $mio]))->assertStatus(409);
    $this->actingAs($this->userB, 'admin')->postJson(cf3_ruta('tallas', ['borrador' => $mio]), ['producto_id' => $this->configurable->id, 'cantidades' => [$this->v1->id => 9]])->assertStatus(409);
    $this->actingAs($this->userB, 'admin')->postJson(cf3_ruta('eliminar', ['borrador' => $mio]))->assertStatus(409);
    $this->actingAs($this->userA, 'admin')->getJson(cf3_ruta('estado', ['borrador' => $deAsesor]))->assertStatus(409);

    // La lista de A solo trae lo suyo.
    $ids = collect($this->actingAs($this->userA, 'admin')->getJson(cf3_ruta('en_curso'))->assertOk()->json())->pluck('id')->all();
    expect($ids)->toBe([$mio])
        ->and(collect($this->actingAs($this->userB, 'admin')->getJson(cf3_ruta('en_curso'))->json())->count())->toBe(0);
});

test('P3 matrices: el customer_id de la URL se ignora y un borrador ajeno se rechaza', function () {
    $deB = cf3_crear($this, $this->userB, [$this->v1->id => 1])->assertOk()->json('borrador.id');
    $url = cf3_ruta('productos.matriz', ['producto' => $this->configurable->id]);

    $this->actingAs($this->userA, 'admin')->getJson($url.'?customer_id='.$this->clienteB->id)->assertOk();
    $this->actingAs($this->userA, 'admin')->getJson($url.'?borrador_id='.$deB)->assertStatus(409);
});

test('P3 inventario: agotado, exacto hasta 10 y «Mas de 10»; nunca la cifra real, produccion ni grupo', function () {
    $r = $this->actingAs($this->userA, 'admin')
        ->getJson(cf3_ruta('productos.matriz', ['producto' => $this->configurable->id]))->assertOk();
    $json = $r->getContent();

    $porId = collect($r->json('celdas'))->keyBy('variante.productId');

    expect($porId[$this->v1->id]['variante']['disponibilidad']['nivel'])->toBe('mas_de_10')
        ->and($porId[$this->v1->id]['variante']['stockVendible'])->toBe(11)
        ->and($porId[$this->v2->id]['variante']['disponibilidad']['cantidad'])->toBe(3)
        ->and($porId[$this->v3->id]['variante']['disponibilidad']['nivel'])->toBe('agotado')
        ->and($json)->not->toContain('"stockVendible":50')
        ->and($json)->not->toContain('abastecimiento')
        ->and($json)->not->toContain('"senal"')
        ->and($json)->not->toContain('grupo');
});

test('P3 el estado de la venta no revela stock exacto ni grupo ni produccion', function () {
    $id = cf3_crear($this, $this->userA, [$this->v1->id => 2, $this->v2->id => 3])->assertOk()->json('borrador.id');
    $json = $this->actingAs($this->userA, 'admin')->getJson(cf3_ruta('estado', ['borrador' => $id]))->assertOk()->getContent();

    foreach (['"stockVendible":50', 'abastecimiento', 'porFabricar', 'grupo_nombre', 'grupo_override', 'propietarioAdminId'] as $prohibido) {
        expect($json)->not->toContain($prohibido);
    }
});

test('P3 pedir mas de lo disponible rechaza con mensaje neutro (sin cifras ni causas internas)', function () {
    $r = cf3_crear($this, $this->userA, [$this->v2->id => 9999]);
    $r->assertStatus(422);

    expect($r->json('mensaje'))->toBe('No hay suficientes unidades disponibles de esta talla.')
        ->and($r->getContent())->not->toContain('9999')->not->toContain('"variante_id"');
    expect(BorradorVenta::query()->where('propietario_admin_id', $this->userA->id)->count())->toBe(0);
});

test('P3 dos pestanas: referencias distintas crean ventas independientes; el reintento no duplica', function () {
    $ref = (string) Illuminate\Support\Str::uuid();
    $a1 = cf3_crear($this, $this->userA, [$this->v1->id => 1], ['referencia' => $ref])->assertOk()->json('borrador.id');
    $a2 = cf3_crear($this, $this->userA, [$this->v1->id => 1], ['referencia' => $ref])->assertOk()->json('borrador.id');
    $b = cf3_crear($this, $this->userA, [$this->v1->id => 2])->assertOk()->json('borrador.id');

    expect($a2)->toBe($a1)->and($b)->not->toBe($a1)
        ->and(BorradorVenta::query()->where('propietario_admin_id', $this->userA->id)->count())->toBe(2);
});

test('P3 acceso suspendido o desvinculado: no se opera (el Bouncer lo saca o el vinculo falla cerrado)', function () {
    $svc = app(ClienteAccesoService::class);
    $svc->suspender($this->clienteA->id, $this->gestor, 'Prueba de suspension');
    $suspendido = $this->actingAs(Admin::query()->find($this->userA->id), 'admin')->getJson(cf3_ruta('estado_inicial'));
    expect($suspendido->getStatusCode())->toBeIn([302, 401])->and($suspendido->getContent())->not->toContain('accesos_rapidos');

    $svc->reactivar($this->clienteA->id, $this->gestor);
    $this->actingAs(Admin::query()->find($this->userA->id), 'admin')->getJson(cf3_ruta('estado_inicial'))->assertOk();

    // Vinculo caido pero usuario activo (estado inconsistente): el servicio falla cerrado.
    ClienteAcceso::query()->where('admin_id', $this->userA->id)->update(['estado' => 'desvinculado']);
    $this->actingAs(Admin::query()->find($this->userA->id), 'admin')->getJson(cf3_ruta('estado_inicial'))->assertStatus(403)->assertJsonPath('codigo', 'sin_vinculo');
    $this->actingAs(Admin::query()->find($this->userA->id), 'admin')->postJson(cf3_ruta('iniciar_con_tallas'), [
        'canal' => 'x', 'referencia' => 'abcdefgh1', 'producto_id' => $this->configurable->id, 'cantidades' => [$this->v1->id => 1],
    ])->assertStatus(403);
});

test('P3 el cliente no llega a nada administrativo: dashboard, Nueva venta de asesores, cartera, logistica, catalogo', function () {
    $rutas = [
        ['GET', route('admin.dashboard.index')],
        ['GET', route('admin.ventas_asesor.inicio.index')],
        ['GET', route('admin.ventas_asesor.nueva_venta.pantalla')],
        ['POST', route('admin.ventas_asesor.nueva_venta.iniciar'), ['customer_id' => $this->clienteB->id, 'canal' => 'whatsapp']],
        ['POST', route('admin.ventas_asesor.nueva_venta.clientes.buscar'), ['telefono' => '3001112233']],
        ['GET', route('admin.ventas_asesor.nueva_venta.clientes.direcciones', ['cliente' => $this->clienteB->id])],
        ['GET', route('admin.ventas_asesor.cartera.index')],
        ['GET', route('admin.ventas_asesor.pedidos.index')],
        ['GET', route('admin.ventas_asesor.costos.index')],
        ['GET', route('admin.catalog.products.index')],
        ['GET', route('admin.customers.customers.index')],
        ['GET', route('admin.sales.orders.index')],
        ['GET', route('admin.logistica.despachos.index')],
        ['POST', route('admin.ventas_asesor.clientes_acceso.crear_usuario', ['cliente' => $this->clienteB->id]), []],
    ];

    foreach ($rutas as $r) {
        $resp = $this->actingAs($this->userA, 'admin')->json($r[0], $r[1], $r[2] ?? []);
        expect($resp->getStatusCode())->toBeIn([401, 403, 404, 302], $r[0].' '.$r[1]);
    }
});

test('P3 el rol del cliente es el de autoservicio y no tiene ninguna clave administrativa', function () {
    $rol = Admin::query()->with('role')->find($this->userA->id)->role;

    expect($rol->name)->toBe(RolClienteFrecuente::NOMBRE)
        ->and(app(RolClienteFrecuente::class)->esSeguro($rol))->toBeTrue();
});

test('P3 el login del cliente lo lleva a su pantalla de compra (la hoja de redireccion tiene ruta en texto)', function () {
    Admin::query()->whereKey($this->userA->id)->update(['password' => bcrypt('Clave-Prueba-2026')]);

    $r = $this->post(route('admin.session.store'), ['email' => $this->userA->email, 'password' => 'Clave-Prueba-2026']);

    expect($r->getStatusCode())->toBe(302)->and($r->headers->get('Location'))->toBe(route('admin.autoservicio.pantalla'));
    $this->get(route('admin.autoservicio.pantalla'))->assertOk();
});

test('P3 un usuario con el rol pero sin la hoja comprar no opera aunque tenga el grupo', function () {
    $rol = Role::factory()->create(['name' => 'Parcial '.uniqid(), 'permission_type' => 'custom', 'permissions' => ['autoservicio']]);
    DB::table('admins')->where('id', $this->userA->id)->update(['role_id' => $rol->id]);

    $this->actingAs(Admin::query()->find($this->userA->id), 'admin')->getJson(cf3_ruta('estado_inicial'))->assertStatus(403);
});

test('P3 rutas nativas de Bagisto sin ACL (notificaciones con pedidos de todos, cuenta, ayuda): cerradas al cliente, abiertas al asesor', function () {
    foreach ([route('admin.notification.get_notification'), route('admin.notification.index'), route('admin.account.edit'), route('admin.help.index')] as $url) {
        $this->actingAs($this->userA, 'admin')->getJson($url)->assertStatus(403);
    }

    // El asesor no cambia: el middleware solo restringe a usuarios de autoservicio.
    $this->actingAs(f03b_admin('all'), 'admin')->get(route('admin.notification.index'))->assertStatus(200);

    // Cerrar sesion si puede.
    expect($this->actingAs($this->userA, 'admin')->delete(route('admin.session.destroy'))->getStatusCode())->toBeIn([200, 302]);
});
