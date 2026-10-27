<?php

use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoAtribucion;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\OrigenPropiedadComercial;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Exceptions\AsesorInvalidoException as Rechazo;
use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException;
use ByLopez\VentasAsesor\Models\Asesor;
use ByLopez\VentasAsesor\Services\Asesores\AsesorActualService;
use ByLopez\VentasAsesor\Services\Asesores\GuardarAsesorService;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\Sales\Models\Order;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

require_once __DIR__.'/../helpersFase03b.php';

uses(TestCase::class);

/*
| ASESOR-01 · identidad del asesor (Doc. 05 §43). Pruebas pequenas: asociacion usuario ↔ asesor, asesor en sesion,
| falla cerrado sin asociacion, view_own (solo la ficha propia) y view_all (todas).
*/

const AS01_PERMISOS_ASESOR = [
    'ventas_asesor', 'ventas_asesor.operar_como_asesor', 'ventas_asesor.pedidos', 'ventas_asesor.pedidos.create',
    'ventas_asesor.pedidos.view_own', 'ventas_asesor.asesores', 'ventas_asesor.asesores.view_own',
];

/** Usuario de Bagisto SIN perfil de asesor (a diferencia de f03b_admin, que lo crea si hay marcador). */
function as01_usuario(array $permisos, string $nombre = 'Usuario AS01'): Admin
{
    $rol = Role::factory()->create(['name' => 'AS01 '.uniqid(), 'permission_type' => 'custom', 'permissions' => $permisos]);

    return Admin::factory()->create(['role_id' => $rol->id, 'name' => $nombre, 'status' => 1]);
}

test('alta: asocia un usuario elegible por ACL; un usuario no puede tener dos asesores; sin el marcador no es elegible; el usuario asociado es inmutable', function () {
    $admin = f03b_admin('all');
    $guardar = app(GuardarAsesorService::class);
    $usuario = as01_usuario(AS01_PERMISOS_ASESOR, 'Laura Ventas');

    $asesor = $guardar->crear($admin, ['admin_id' => $usuario->id, 'nombre' => 'Laura', 'telefono' => '300 123 4567']);

    expect($asesor->admin_id)->toBe((int) $usuario->id)->and($asesor->activo)->toBeTrue()->and($asesor->creado_por_admin_id)->toBe((int) $admin->id);

    expect(fn () => $guardar->crear($admin, ['admin_id' => $usuario->id, 'nombre' => 'Otra']))
        ->toThrow(fn (Rechazo $e) => $e->codigo === Rechazo::USUARIO_YA_ASOCIADO);

    expect(fn () => $guardar->crear($admin, ['admin_id' => as01_usuario(['ventas_asesor'])->id, 'nombre' => 'Sin marcador']))
        ->toThrow(fn (Rechazo $e) => $e->codigo === Rechazo::USUARIO_NO_ELEGIBLE);

    expect(fn () => $guardar->crear(as01_usuario(AS01_PERMISOS_ASESOR), ['admin_id' => $usuario->id, 'nombre' => 'X']))
        ->toThrow(fn (Rechazo $e) => $e->codigo === Rechazo::SIN_PERMISO);

    // Editar solo cambia nombre y telefono; el usuario asociado no se toca.
    $guardar->actualizar($admin, $asesor->id, ['nombre' => 'Laura M.', 'telefono' => '', 'admin_id' => 999]);

    expect($asesor->fresh()->nombre)->toBe('Laura M.')->and($asesor->fresh()->admin_id)->toBe((int) $usuario->id)
        ->and(Asesor::query()->where('admin_id', $usuario->id)->count())->toBe(1);
});

test('asesor en sesion: resuelve el perfil activo; inactivo no resuelve y falla cerrado al vender; sin perfil tambien; el administrador `all` no lo necesita', function () {
    $admin = f03b_admin('all');
    $usuario = as01_usuario(AS01_PERMISOS_ASESOR);
    $sinPerfil = as01_usuario(AS01_PERMISOS_ASESOR, 'Sin Perfil');
    $asesor = app(GuardarAsesorService::class)->crear($admin, ['admin_id' => $usuario->id, 'nombre' => 'Andres']);

    expect(app(AsesorActualService::class)->asesorDe($usuario)?->id)->toBe($asesor->id)
        ->and(app(AsesorActualService::class)->operable($usuario))->toBeTrue()
        ->and(app(AsesorActualService::class)->operable($admin))->toBeTrue()          // D-27: el `all` no requiere perfil
        ->and(app(AsesorActualService::class)->operable($sinPerfil))->toBeFalse();

    // Sin perfil: Nueva venta rechaza con `asesor_sin_asociacion` (no se degrada a «sin asignar» ni a «empresa»).
    expect(fn () => app(BorradorVentaService::class)->crear(999999, $sinPerfil, CanalVenta::WHATSAPP))
        ->toThrow(fn (BorradorVentaInvalidoException $e) => $e->codigo === BorradorVentaInvalidoException::ASESOR_SIN_ASOCIACION
            && str_contains($e->getMessage(), 'no tiene un asesor comercial asociado'));

    // Con perfil activo pasa la puerta (falla despues por el cliente inexistente).
    expect(fn () => app(BorradorVentaService::class)->crear(999999, $usuario, CanalVenta::WHATSAPP))
        ->toThrow(fn (BorradorVentaInvalidoException $e) => $e->codigo === BorradorVentaInvalidoException::CLIENTE_INEXISTENTE);

    // Inactivo: deja de resolver y vuelve a fallar cerrado.
    app(GuardarAsesorService::class)->cambiarEstado($admin, $asesor->id, false);

    expect(app(AsesorActualService::class)->asesorDe($usuario))->toBeNull()
        ->and(fn () => app(BorradorVentaService::class)->crear(999999, $usuario, CanalVenta::WHATSAPP))
        ->toThrow(fn (BorradorVentaInvalidoException $e) => $e->codigo === BorradorVentaInvalidoException::ASESOR_SIN_ASOCIACION);

    // El aviso es visible en Inicio.
    $this->actingAs($sinPerfil, 'admin')->get(route('admin.ventas_asesor.inicio.index'))->assertOk()->assertSee('data-asesor-sin-asociacion', false);
});

test('view_own ve solo su ficha (nunca un listado ni otra ficha); sin perfil ve el aviso; view_all ve todos con metricas; el asesor no gestiona', function () {
    $admin = f03b_admin('all');
    $guardar = app(GuardarAsesorService::class);
    $usuarioA = as01_usuario(AS01_PERMISOS_ASESOR, 'Asesora A');
    $usuarioB = as01_usuario(AS01_PERMISOS_ASESOR, 'Asesor B');
    $a = $guardar->crear($admin, ['admin_id' => $usuarioA->id, 'nombre' => 'Asesora A']);
    $b = $guardar->crear($admin, ['admin_id' => $usuarioB->id, 'nombre' => 'Asesor B']);

    // Una venta de hoy atribuida a A (la atribucion guarda el id del usuario = identidad del asesor).
    $pedido = Order::factory()->create(['status' => 'pending', 'grand_total' => 50000, 'base_grand_total' => 50000, 'order_currency_code' => 'COP', 'base_currency_code' => 'COP']);
    DB::table('bylopez_va_order_attributions')->insert([
        'order_id' => $pedido->id, 'advisor_id' => $usuarioA->id, 'advisor_name_snapshot' => 'Asesora A',
        'commercial_owner_type' => TipoPropiedadComercial::ASESOR->value, 'commercial_owner_source' => OrigenPropiedadComercial::AUTO_ASESOR->value,
        'sales_channel' => CanalVenta::WHATSAPP->value, 'creation_source' => MecanismoCreacion::VENTAS_ASESOR->value,
        'attribution_status' => EstadoAtribucion::COMPLETA->value, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // view_own: siempre su propia ficha, aunque pida otra.
    $this->actingAs($usuarioA, 'admin')->get(route('admin.ventas_asesor.asesores.index', ['asesor' => $b->id]))->assertOk()
        ->assertViewIs('ventasasesor::admin.asesores.detalle')
        ->assertViewHas('asesor', fn ($asesor) => (int) $asesor->id === $a->id)
        ->assertViewHas('metricas', fn ($m) => $m['ventas_hoy'] === 1 && $m['monto_hoy'] === 50000.0)
        ->assertDontSee('Asesor B');

    // Sin perfil: aviso y nada mas.
    $this->actingAs(as01_usuario(AS01_PERMISOS_ASESOR), 'admin')->get(route('admin.ventas_asesor.asesores.index'))->assertOk()
        ->assertViewIs('ventasasesor::admin.asesores.sin-perfil')->assertSee('data-asesor-sin-asociacion', false);

    // El asesor no crea ni edita (el Bouncer corta: la ruta es de la hoja `manage`).
    $this->actingAs($usuarioA, 'admin')->post(route('admin.ventas_asesor.asesores.store'), ['admin_id' => $usuarioB->id, 'nombre' => 'X'])->assertUnauthorized();

    // view_all (administrador): listado con los dos y sus metricas por lote; abre cualquier ficha.
    $this->actingAs($admin, 'admin')->get(route('admin.ventas_asesor.asesores.index'))->assertOk()
        ->assertViewIs('ventasasesor::admin.asesores.index')
        ->assertViewHas('asesores', fn ($p) => $p->total() >= 2 && $p->getCollection()->firstWhere('id', $a->id)->metricas['ventas_hoy'] === 1)
        ->assertSee('Asesora A')->assertSee('Asesor B');

    $this->actingAs($admin, 'admin')->get(route('admin.ventas_asesor.asesores.index', ['asesor' => $b->id]))->assertOk()
        ->assertViewHas('asesor', fn ($asesor) => (int) $asesor->id === $b->id);
});
