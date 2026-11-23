<?php

use ByLopez\VentasAsesor\Models\ClienteAcceso;
use ByLopez\VentasAsesor\Models\ClienteAccesoEvento;
use ByLopez\VentasAsesor\Services\Clientes\ClienteAccesoService;
use ByLopez\VentasAsesor\Support\Autoservicio\RolClienteFrecuente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Customer\Models\CustomerGroup;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

require_once __DIR__.'/../helpersFase03f.php';

uses(TestCase::class);

/* P1 (doc. 33 §4): acceso de clientes al aplicativo desde la ficha del cliente. Solo base exclusiva. */

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();
    Role::query()->where('name', RolClienteFrecuente::NOMBRE)->delete();

    $this->gestor = f03b_admin('custom', [ClienteAccesoService::PERMISO]);
    $this->asesor = f03b_admin('custom', ['ventas_asesor', 'ventas_asesor.pedidos.create']);
    $this->grupo = f03b_grupo('G');
    $this->cliente = f03b_cliente($this->grupo, ['first_name' => 'Rita', 'last_name' => 'Web', 'email' => 'rita.web.'.uniqid().'@example.test']);

    $this->url = fn (string $ruta, array $extra = []) => route('admin.ventas_asesor.clientes_acceso.'.$ruta, ['cliente' => $this->cliente->id] + $extra);
});

test('P1 habilitar crea el usuario con el rol seguro, sin contrasena en claro, y lo vincula con auditoria', function () {
    $r = $this->actingAs($this->gestor, 'admin')->postJson(($this->url)('crear_usuario'), [])->assertOk()->json();

    $acceso = ClienteAcceso::query()->where('customer_id', $this->cliente->id)->firstOrFail();
    $admin = Admin::query()->with('role')->findOrFail($acceso->admin_id);

    expect($r['estado'])->toBe('activo')
        ->and($admin->email)->toBe(strtolower($this->cliente->email))
        ->and($admin->role->name)->toBe(RolClienteFrecuente::NOMBRE)
        ->and(app(RolClienteFrecuente::class)->esSeguro($admin->role))->toBeTrue()
        ->and((bool) $admin->status)->toBeTrue()
        ->and($acceso->estado)->toBe('activo')
        // La contrasena no es la del cliente de la tienda ni nada conocido, y no viaja en la respuesta.
        ->and(Hash::check((string) $this->cliente->password, $admin->password))->toBeFalse()
        ->and(json_encode($r))->not->toContain('password')
        ->and(ClienteAccesoEvento::query()->where('customer_id', $this->cliente->id)->pluck('accion')->all())->toContain('crear_usuario');
});

test('P1 sin la clave de administrar acceso no se puede ver ni habilitar (el Bouncer lo niega) y no se crea nada', function () {
    $admins = Admin::query()->count();

    $this->actingAs($this->asesor, 'admin')->getJson(($this->url)('mostrar'))->assertStatus(401);
    $this->actingAs($this->asesor, 'admin')->postJson(($this->url)('crear_usuario'), [])->assertStatus(401);

    expect(Admin::query()->count())->toBe($admins)->and(ClienteAcceso::query()->count())->toBe(0);
});

test('P1 un cliente con acceso no admite otro (V1) y no se crea un segundo usuario', function () {
    $this->actingAs($this->gestor, 'admin')->postJson(($this->url)('crear_usuario'), [])->assertOk();
    $admins = Admin::query()->count();

    $this->actingAs($this->gestor, 'admin')->postJson(($this->url)('crear_usuario'), ['email' => 'otro@example.test'])
        ->assertStatus(409)->assertJsonPath('codigo', 'cliente_ya_tiene_acceso');

    expect(Admin::query()->count())->toBe($admins);
});

test('P1 correo ya usado por otro usuario: se rechaza y no queda nada a medias', function () {
    $this->actingAs($this->gestor, 'admin')
        ->postJson(($this->url)('crear_usuario'), ['email' => $this->asesor->email])
        ->assertStatus(409)->assertJsonPath('codigo', 'correo_en_uso');

    expect(ClienteAcceso::query()->count())->toBe(0)->and(Role::query()->where('name', RolClienteFrecuente::NOMBRE)->count())->toBeLessThanOrEqual(1);
});

test('P1 cliente sin correo ni correo indicado se rechaza; cliente sin grupo apto tambien', function () {
    $sinCorreo = f03b_cliente($this->grupo, ['email' => null]);
    $this->actingAs($this->gestor, 'admin')->postJson(route('admin.ventas_asesor.clientes_acceso.crear_usuario', ['cliente' => $sinCorreo->id]), [])
        ->assertStatus(422)->assertJsonPath('codigo', 'correo_invalido');

    $invitado = CustomerGroup::query()->where('code', 'guest')->first();
    $enInvitado = f03b_cliente($this->grupo, ['email' => 'inv.'.uniqid().'@example.test']);
    DB::table('customers')->where('id', $enInvitado->id)->update(['customer_group_id' => $invitado->id]);
    $this->actingAs($this->gestor, 'admin')->postJson(route('admin.ventas_asesor.clientes_acceso.crear_usuario', ['cliente' => $enInvitado->id]), [])
        ->assertStatus(422)->assertJsonPath('codigo', 'cliente_sin_grupo_apto');
});

test('P1 vincular: un asesor u otro rol NUNCA se convierte en usuario de autoservicio ni se le cambia el rol', function () {
    $rolPrevio = $this->asesor->role_id;

    $this->actingAs($this->gestor, 'admin')->postJson(($this->url)('vincular'), ['admin_id' => $this->asesor->id])
        ->assertStatus(422)->assertJsonPath('codigo', 'usuario_con_otro_rol');

    expect(Admin::query()->find($this->asesor->id)->role_id)->toBe($rolPrevio)->and(ClienteAcceso::query()->count())->toBe(0);
});

test('P1 vincular un usuario de autoservicio libre funciona; uno ya ligado a otro cliente se rechaza', function () {
    $rol = app(RolClienteFrecuente::class)->asegurar();
    $libre = Admin::factory()->create(['role_id' => $rol->id, 'status' => 0]);

    $this->actingAs($this->gestor, 'admin')->postJson(($this->url)('vincular'), ['admin_id' => $libre->id])->assertOk()->assertJsonPath('estado', 'activo');
    expect((bool) Admin::query()->find($libre->id)->status)->toBeTrue();

    $otro = f03b_cliente($this->grupo, ['email' => 'otro.'.uniqid().'@example.test']);
    $this->actingAs($this->gestor, 'admin')
        ->postJson(route('admin.ventas_asesor.clientes_acceso.vincular', ['cliente' => $otro->id]), ['admin_id' => $libre->id])
        ->assertStatus(409);
});

test('P1 suspender apaga al usuario, reactivar lo enciende y desvincular exige motivo y conserva el historial', function () {
    $r = $this->actingAs($this->gestor, 'admin')->postJson(($this->url)('crear_usuario'), [])->assertOk()->json();
    $adminId = $r['usuario']['id'];

    $this->actingAs($this->gestor, 'admin')->postJson(($this->url)('suspender'), ['motivo' => 'x'])->assertStatus(422);
    $this->actingAs($this->gestor, 'admin')->postJson(($this->url)('suspender'), ['motivo' => 'Pagos pendientes'])->assertOk()->assertJsonPath('estado', 'suspendido');
    expect((bool) Admin::query()->find($adminId)->status)->toBeFalse();

    $this->actingAs($this->gestor, 'admin')->postJson(($this->url)('suspender'), ['motivo' => 'otra vez'])->assertStatus(409);

    $this->actingAs($this->gestor, 'admin')->postJson(($this->url)('reactivar'))->assertOk()->assertJsonPath('estado', 'activo');
    expect((bool) Admin::query()->find($adminId)->status)->toBeTrue();

    $this->actingAs($this->gestor, 'admin')->postJson(($this->url)('desvincular'), [])->assertStatus(422);
    $this->actingAs($this->gestor, 'admin')->postJson(($this->url)('desvincular'), ['motivo' => 'Ya no compra'])->assertOk()->assertJsonPath('estado', 'sin_acceso');

    $fila = ClienteAcceso::query()->where('customer_id', $this->cliente->id)->first();
    expect($fila->estado)->toBe('desvinculado')->and($fila->desvinculado_motivo)->toBe('Ya no compra')
        ->and((bool) Admin::query()->find($adminId)->status)->toBeFalse()
        ->and(ClienteAccesoEvento::query()->where('customer_id', $this->cliente->id)->pluck('accion')->all())
        ->toContain('suspender', 'reactivar', 'desvincular');
});

test('P1 el resumen cuenta activos, suspendidos y sin acceso', function () {
    $this->actingAs($this->gestor, 'admin')->postJson(($this->url)('crear_usuario'), [])->assertOk();
    $otro = f03b_cliente($this->grupo, ['email' => 'dos.'.uniqid().'@example.test']);
    $this->actingAs($this->gestor, 'admin')->postJson(route('admin.ventas_asesor.clientes_acceso.crear_usuario', ['cliente' => $otro->id]), [])->assertOk();
    $this->actingAs($this->gestor, 'admin')->postJson(route('admin.ventas_asesor.clientes_acceso.suspender', ['cliente' => $otro->id]), ['motivo' => 'Prueba'])->assertOk();

    $r = $this->actingAs($this->gestor, 'admin')->getJson(($this->url)('mostrar'))->assertOk()->json('resumen');

    expect($r['activos'])->toBe(1)->and($r['suspendidos'])->toBe(1)->and($r['sin_acceso'])->toBeGreaterThanOrEqual(0);
});
