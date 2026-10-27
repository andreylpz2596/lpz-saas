<?php

use ByLopez\CorteConfeccion\DataGrids\OrdenConfeccionDataGrid;
use ByLopez\CorteConfeccion\DataGrids\TallerDataGrid;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use ByLopez\CorteConfeccion\Enums\PrioridadOrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\Rollo;
use ByLopez\CorteConfeccion\Services\TallerService;
use Illuminate\Support\Facades\Auth;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

function ais_role(string $nombre, array $permisos, string $permissionType = 'custom'): Role
{
    return Role::factory()->create([
        'name' => $nombre,
        'permission_type' => $permissionType,
        'permissions' => $permisos,
    ]);
}

function ais_admin(string $nombreRol, array $permisos = [], string $permissionType = 'custom'): Admin
{
    return Admin::factory()->create([
        'role_id' => ais_role($nombreRol, $permisos, $permissionType)->id,
    ]);
}

function ais_permisosTaller(array $extra = []): array
{
    return array_values(array_unique(array_merge([
        'corte_confeccion',
        'corte_confeccion.talleres',
        'corte_confeccion.ordenes_confeccion',
        'corte_confeccion.ordenes_confeccion.operar',
    ], $extra)));
}

function ais_crearTaller(Admin $usuario, string $nombre)
{
    Auth::guard('admin')->setUser(ais_admin('Admin AIS '.uniqid(), [], 'all'));

    return app(TallerService::class)->create([
        'nombre' => $nombre,
        'nit' => null,
        'contacto' => null,
        'telefono' => null,
        'direccion' => null,
        'estado' => 'activo',
        'observacion' => null,
        'usuario_id' => $usuario->id,
    ]);
}

function ais_crearOrden($taller, string $codigo): OrdenConfeccion
{
    $color = cc_test_color_option('Blanco AIS '.uniqid());

    $rollo = Rollo::query()->create([
        'codigo' => 'R-AIS-'.uniqid(),
        'proveedor' => 'Proveedor AIS',
        'color' => $color->admin_name,
        'color_option_id' => $color->id,
        'tipo_tela' => 'Tela AIS',
        'peso_inicial' => 100,
        'peso_disponible' => 100,
        'valor_kilo' => 20000,
        'costo_total' => 2000000,
        'fecha_compra' => now()->toDateString(),
        'estado' => EstadoRollo::Disponible->value,
        'observacion' => null,
    ]);

    $ordenCorte = OrdenCorte::query()->create([
        'codigo' => 'OC-AIS-'.uniqid(),
        'rollo_id' => $rollo->id,
        'color' => $color->admin_name,
        'genero_linea' => 'Nino',
        'fecha_entrega' => now()->toDateString(),
        'peso_entregado' => 10,
        'peso_total_rollos' => 10,
        'peso_sobrante_total' => 0,
        'merma_total' => 0,
        'peso_consumido_total' => 10,
        'total_piezas_cortadas' => 10,
        'peso_promedio_pieza' => 1,
        'estado' => EstadoOrdenCorte::Cerrado->value,
        'observacion' => null,
    ]);

    $orden = OrdenConfeccion::query()->create([
        'codigo' => $codigo,
        'orden_corte_id' => $ordenCorte->id,
        'taller_id' => $taller->id,
        'fecha_entrega' => now()->toDateString(),
        'estado' => EstadoOrdenConfeccion::Activa->value,
        'observacion' => null,
    ]);

    $producto = Product::factory()->simple()->create();

    $orden->detalles()->create([
        'product_id' => $producto->id,
        'producto_sku' => $producto->sku ?: 'SKU-AIS-'.uniqid(),
        'producto_nombre' => $producto->name ?: 'Producto AIS',
        'talla' => 'M',
        'cantidad_entregada' => 10,
        'prioridad' => PrioridadOrdenConfeccion::Normal->value,
    ]);

    return $orden->refresh()->load('detalles');
}

test('TALLER CONFECCION solo lista busca y visualiza su taller asociado', function () {
    $jairo = ais_admin('TALLER CONFECCION', ais_permisosTaller());
    $andrea = ais_admin('TALLER CONFECCION', ais_permisosTaller());

    $s4 = ais_crearTaller($jairo, 'SATELITE S4 AIS '.uniqid());
    $s2 = ais_crearTaller($andrea, 'SATELITE 2 AIS '.uniqid());

    Auth::guard('admin')->setUser($jairo);

    $nombres = app(TallerDataGrid::class)->prepareQueryBuilder()->pluck('nombre')->all();
    expect($nombres)->toContain($s4->nombre)->not->toContain($s2->nombre);

    $buscar = $this->actingAs($jairo, 'admin')
        ->getJson(route('admin.corte_confeccion.talleres.buscar', ['query' => 'SATELITE']))
        ->assertOk()
        ->json('data');
    $labels = collect($buscar)->pluck('label')->all();
    expect($labels)->toContain($s4->nombre)->not->toContain($s2->nombre);

    $this->actingAs($jairo, 'admin')->get(route('admin.corte_confeccion.talleres.show', $s4->id))->assertOk();
    $this->actingAs($jairo, 'admin')->get(route('admin.corte_confeccion.talleres.show', $s2->id))->assertForbidden();
});

test('TALLER CONFECCION sin taller asociado falla cerrado en talleres y ordenes', function () {
    $usuarioSinTaller = ais_admin('TALLER CONFECCION', ais_permisosTaller());
    $otroUsuario = ais_admin('TALLER CONFECCION', ais_permisosTaller());
    $tallerAjeno = ais_crearTaller($otroUsuario, 'Taller Ajeno AIS '.uniqid());
    $ordenAjena = ais_crearOrden($tallerAjeno, 'OF-AIS-'.uniqid());

    Auth::guard('admin')->setUser($usuarioSinTaller);

    expect(app(TallerDataGrid::class)->prepareQueryBuilder()->count())->toBe(0)
        ->and(app(OrdenConfeccionDataGrid::class)->prepareQueryBuilder()->count())->toBe(0);

    $this->actingAs($usuarioSinTaller, 'admin')
        ->getJson(route('admin.corte_confeccion.talleres.buscar', ['query' => 'AIS']))
        ->assertOk()
        ->assertJsonPath('data', []);

    $this->actingAs($usuarioSinTaller, 'admin')->get(route('admin.corte_confeccion.talleres.show', $tallerAjeno->id))->assertForbidden();
    $this->actingAs($usuarioSinTaller, 'admin')->post(route('admin.corte_confeccion.ordenes_confeccion.cancel', $ordenAjena->id))->assertForbidden();
});

test('TALLER CONFECCION no puede crear editar ni actualizar talleres aunque sean propios', function () {
    $jairo = ais_admin('TALLER CONFECCION', ais_permisosTaller());
    $s4 = ais_crearTaller($jairo, 'SATELITE S4 Mutacion AIS '.uniqid());
    $otro = ais_crearTaller(ais_admin('TALLER CONFECCION', ais_permisosTaller()), 'SATELITE 2 Mutacion AIS '.uniqid());

    $this->actingAs($jairo, 'admin')->get(route('admin.corte_confeccion.talleres.create'))->assertStatus(401);
    $this->actingAs($jairo, 'admin')->get(route('admin.corte_confeccion.talleres.edit', $s4->id))->assertStatus(401);
    $this->actingAs($jairo, 'admin')->get(route('admin.corte_confeccion.talleres.edit', $otro->id))->assertStatus(401);
    $this->actingAs($jairo, 'admin')->put(route('admin.corte_confeccion.talleres.update', $s4->id), [
        'nombre' => 'Intento S4',
        'estado' => 'inactivo',
        'usuario_id' => $otro->usuario_id,
    ])->assertStatus(401);

    $tallerConAll = ais_admin('TALLER CONFECCION', [], 'all');
    $propio = ais_crearTaller($tallerConAll, 'Taller All AIS '.uniqid());

    $this->actingAs($tallerConAll, 'admin')->get(route('admin.corte_confeccion.talleres.create'))->assertForbidden();
    $this->actingAs($tallerConAll, 'admin')->get(route('admin.corte_confeccion.talleres.edit', $propio->id))->assertForbidden();
    $this->actingAs($tallerConAll, 'admin')->put(route('admin.corte_confeccion.talleres.update', $propio->id), [
        'nombre' => 'Intento All',
        'estado' => 'inactivo',
        'usuario_id' => $otro->usuario_id,
    ])->assertForbidden();

    expect($s4->fresh()->estado)->toBe('activo')
        ->and($propio->fresh()->estado)->toBe('activo');
});

test('listado unico y cancel respetan ownership de TALLER CONFECCION', function () {
    $jairo = ais_admin('TALLER CONFECCION', ais_permisosTaller());
    $andrea = ais_admin('TALLER CONFECCION', ais_permisosTaller());

    $s4 = ais_crearTaller($jairo, 'SATELITE S4 Orden AIS '.uniqid());
    $s2 = ais_crearTaller($andrea, 'SATELITE 2 Orden AIS '.uniqid());
    $ordenS4 = ais_crearOrden($s4, 'OF-AIS-S4-'.uniqid());
    $ordenS2 = ais_crearOrden($s2, 'OF-AIS-S2-'.uniqid());

    Auth::guard('admin')->setUser($jairo);

    $codigos = app(OrdenConfeccionDataGrid::class)->prepareQueryBuilder()->pluck('codigo')->all();
    expect($codigos)->toContain($ordenS4->codigo)->not->toContain($ordenS2->codigo);

    $this->actingAs($jairo, 'admin')->post(route('admin.corte_confeccion.ordenes_confeccion.cancel', $ordenS2->id))->assertForbidden();
    expect($ordenS2->fresh()->estado)->toBe(EstadoOrdenConfeccion::Activa->value);

    $this->actingAs($jairo, 'admin')->post(route('admin.corte_confeccion.ordenes_confeccion.cancel', $ordenS4->id))->assertRedirect();
    expect($ordenS4->fresh()->estado)->toBe(EstadoOrdenConfeccion::Anulada->value);
});

test('reportar taller exige ACL reportar_taller ademas de ownership', function () {
    $jairoSinAclReportar = ais_admin('TALLER CONFECCION', ais_permisosTaller());
    $taller = ais_crearTaller($jairoSinAclReportar, 'SATELITE S4 Reporte AIS '.uniqid());
    $orden = ais_crearOrden($taller, 'OF-AIS-REP-'.uniqid());
    $detalle = $orden->detalles->first();

    $response = $this->actingAs($jairoSinAclReportar, 'admin')->post(
        route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $orden->id),
        ['detalles' => [['id' => $detalle->id, 'cantidad' => 1]]]
    );

    expect($response->getStatusCode())->toBeIn([401, 403]);
    expect($orden->fresh()->retornos()->count())->toBe(0);
});

test('Bodega conserva acceso transversal a talleres y ordenes segun ACL', function () {
    $bodega = ais_admin('BODEGA', [
        'corte_confeccion',
        'corte_confeccion.talleres',
        'corte_confeccion.ordenes_confeccion',
        'corte_confeccion.ordenes_confeccion.operar',
    ]);
    $s4 = ais_crearTaller(ais_admin('TALLER CONFECCION', ais_permisosTaller()), 'SATELITE S4 Bodega AIS '.uniqid());
    $s2 = ais_crearTaller(ais_admin('TALLER CONFECCION', ais_permisosTaller()), 'SATELITE 2 Bodega AIS '.uniqid());
    $ordenS2 = ais_crearOrden($s2, 'OF-AIS-BOD-'.uniqid());

    Auth::guard('admin')->setUser($bodega);

    $nombres = app(TallerDataGrid::class)->prepareQueryBuilder()->pluck('nombre')->all();
    expect($nombres)->toContain($s4->nombre)->toContain($s2->nombre);

    $buscar = $this->actingAs($bodega, 'admin')
        ->getJson(route('admin.corte_confeccion.talleres.buscar', ['query' => 'Bodega AIS']))
        ->assertOk()
        ->json('data');
    $labels = collect($buscar)->pluck('label')->all();
    expect($labels)->toContain($s4->nombre)->toContain($s2->nombre);

    $this->actingAs($bodega, 'admin')->get(route('admin.corte_confeccion.talleres.show', $s2->id))->assertOk();
    $this->actingAs($bodega, 'admin')->post(route('admin.corte_confeccion.ordenes_confeccion.cancel', $ordenS2->id))->assertRedirect();
    expect($ordenS2->fresh()->estado)->toBe(EstadoOrdenConfeccion::Anulada->value);
});
