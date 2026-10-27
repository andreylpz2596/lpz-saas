<?php

use ByLopez\CorteConfeccion\DataGrids\CortadorDataGrid;
use ByLopez\CorteConfeccion\DataGrids\OrdenCorteDataGrid;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\Rollo;
use ByLopez\CorteConfeccion\Services\CortadorService;
use Illuminate\Support\Facades\Auth;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

function cfais_role(string $nombre, array $permisos, string $permissionType = 'custom'): Role
{
    return Role::factory()->create([
        'name' => $nombre,
        'permission_type' => $permissionType,
        'permissions' => $permisos,
    ]);
}

function cfais_admin(string $nombreRol, array $permisos = [], string $permissionType = 'custom'): Admin
{
    return Admin::factory()->create([
        'role_id' => cfais_role($nombreRol, $permisos, $permissionType)->id,
    ]);
}

function cfais_permisosCortador(array $extra = []): array
{
    return array_values(array_unique(array_merge([
        'corte_confeccion',
        'corte_confeccion.cortadores',
        'corte_confeccion.ordenes_corte',
        'corte_confeccion.ordenes_corte.operar',
    ], $extra)));
}

function cfais_crearPerfilCortador(Admin $usuario, string $nombre): Cortador
{
    Auth::guard('admin')->setUser(cfais_admin('Admin CFAIS '.uniqid(), [], 'all'));

    return app(CortadorService::class)->create([
        'nombre' => $nombre,
        'nit' => null,
        'contacto' => 'Responsable '.$nombre,
        'telefono' => '3000000000',
        'direccion' => 'Direccion '.$nombre,
        'estado' => 'activo',
        'observacion' => null,
        'usuario_id' => $usuario->id,
    ]);
}

function cfais_crearOrdenCorte(Admin $cortador, string $codigo, ?string $estado = null, int $cantidad = 10): OrdenCorte
{
    $color = cc_test_color_option('Blanco CFAIS '.uniqid());

    $rollo = Rollo::query()->create([
        'codigo' => 'R-CFAIS-'.uniqid(),
        'proveedor' => 'Proveedor CFAIS',
        'color' => $color->admin_name,
        'color_option_id' => $color->id,
        'tipo_tela' => 'Tela CFAIS',
        'peso_inicial' => 100,
        'peso_disponible' => 100,
        'valor_kilo' => 20000,
        'costo_total' => 2000000,
        'fecha_compra' => now()->toDateString(),
        'estado' => EstadoRollo::ReservadoEnCorte->value,
        'observacion' => null,
    ]);

    $orden = OrdenCorte::query()->create([
        'codigo' => $codigo,
        'rollo_id' => $rollo->id,
        'cortador_id' => $cortador->id,
        'cortador_nombre' => $cortador->name,
        'cortador_nombre_snapshot' => $cortador->name,
        'color' => $color->admin_name,
        'genero_linea' => 'Nino',
        'fecha_entrega' => now()->toDateString(),
        'peso_entregado' => 10,
        'peso_total_rollos' => 10,
        'peso_sobrante_total' => 0,
        'merma_total' => 0,
        'peso_consumido_total' => 10,
        'total_piezas_cortadas' => $cantidad,
        'peso_promedio_pieza' => 1,
        'estado' => $estado ?? EstadoOrdenCorte::EntregadoACortador->value,
        'observacion' => null,
    ]);

    $orden->rollosCorte()->create([
        'rollo_id' => $rollo->id,
        'peso_inicial_usado' => 10,
        'entregado_en' => now(),
        'peso_sobrante' => 0,
        'peso_consumido' => 10,
    ]);

    $producto = Product::factory()->simple()->create();

    $orden->detalles()->create([
        'product_id' => $producto->id,
        'producto_sku' => $producto->sku ?: 'SKU-CFAIS-'.uniqid(),
        'producto_nombre' => $producto->name ?: 'Producto CFAIS',
        'genero_linea' => 'Nino',
        'color' => $color->admin_name,
        'talla' => 'M',
        'cantidad_proyectada' => $cantidad,
        'cantidad_real_cortada' => $cantidad,
        'es_adicional' => false,
        'observacion' => null,
    ]);

    return $orden->refresh()->load('detalles');
}

test('CORTADOR solo lista y visualiza su perfil asociado', function () {
    $camilo = cfais_admin('CORTADOR', cfais_permisosCortador());
    $jairo = cfais_admin('CORTADOR', cfais_permisosCortador());

    $perfilCamilo = cfais_crearPerfilCortador($camilo, 'Cortador Camilo CFAIS '.uniqid());
    $perfilJairo = cfais_crearPerfilCortador($jairo, 'Cortador Jairo CFAIS '.uniqid());

    Auth::guard('admin')->setUser($camilo);

    $nombres = app(CortadorDataGrid::class)->prepareQueryBuilder()->pluck('nombre')->all();

    expect($nombres)->toContain($perfilCamilo->nombre)->not->toContain($perfilJairo->nombre);

    $this->actingAs($camilo, 'admin')
        ->get(route('admin.corte_confeccion.cortadores.show', $perfilCamilo->id))
        ->assertOk();

    $this->actingAs($camilo, 'admin')
        ->get(route('admin.corte_confeccion.cortadores.show', $perfilJairo->id))
        ->assertForbidden();
});

test('CORTADOR sin perfil falla cerrado en cortadores y ordenes de corte', function () {
    $usuarioSinPerfil = cfais_admin('CORTADOR', cfais_permisosCortador());
    $otro = cfais_admin('CORTADOR', cfais_permisosCortador());
    $perfilAjeno = cfais_crearPerfilCortador($otro, 'Cortador Ajeno CFAIS '.uniqid());
    $ordenAjena = cfais_crearOrdenCorte($otro, 'OC-CFAIS-AJ-'.uniqid());

    Auth::guard('admin')->setUser($usuarioSinPerfil);

    expect(app(CortadorDataGrid::class)->prepareQueryBuilder()->count())->toBe(0)
        ->and(app(OrdenCorteDataGrid::class)->prepareQueryBuilder()->count())->toBe(0);

    $this->actingAs($usuarioSinPerfil, 'admin')
        ->get(route('admin.corte_confeccion.cortadores.show', $perfilAjeno->id))
        ->assertForbidden();

    $this->actingAs($usuarioSinPerfil, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_corte.show', $ordenAjena->id))
        ->assertForbidden();
});

test('CORTADOR solo lista y opera ordenes de corte propias', function () {
    $camilo = cfais_admin('CORTADOR', cfais_permisosCortador());
    $jairo = cfais_admin('CORTADOR', cfais_permisosCortador());

    cfais_crearPerfilCortador($camilo, 'Cortador Orden Camilo CFAIS '.uniqid());
    cfais_crearPerfilCortador($jairo, 'Cortador Orden Jairo CFAIS '.uniqid());

    $ordenCamilo = cfais_crearOrdenCorte($camilo, 'OC-CFAIS-CAM-'.uniqid());
    $ordenJairo = cfais_crearOrdenCorte($jairo, 'OC-CFAIS-JAI-'.uniqid());

    Auth::guard('admin')->setUser($camilo);

    $codigos = app(OrdenCorteDataGrid::class)->prepareQueryBuilder()->pluck('codigo')->all();

    expect($codigos)->toContain($ordenCamilo->codigo)->not->toContain($ordenJairo->codigo);

    $this->actingAs($camilo, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_corte.show', $ordenCamilo->id))
        ->assertOk();

    $this->actingAs($camilo, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_corte.show', $ordenJairo->id))
        ->assertForbidden();

    $this->actingAs($camilo, 'admin')
        ->post(route('admin.corte_confeccion.ordenes_corte.cancel', $ordenJairo->id))
        ->assertForbidden();

    expect($ordenJairo->fresh()->estado)->toBe(EstadoOrdenCorte::EntregadoACortador->value);
});

test('BODEGA conserva acceso transversal a cortadores y ordenes de corte segun ACL', function () {
    $bodega = cfais_admin('BODEGA', [
        'corte_confeccion',
        'corte_confeccion.cortadores',
        'corte_confeccion.ordenes_corte',
        'corte_confeccion.ordenes_corte.operar',
    ]);
    $camilo = cfais_admin('CORTADOR', cfais_permisosCortador());
    $jairo = cfais_admin('CORTADOR', cfais_permisosCortador());
    $perfilCamilo = cfais_crearPerfilCortador($camilo, 'Cortador Bodega Camilo CFAIS '.uniqid());
    $perfilJairo = cfais_crearPerfilCortador($jairo, 'Cortador Bodega Jairo CFAIS '.uniqid());
    $ordenJairo = cfais_crearOrdenCorte($jairo, 'OC-CFAIS-BOD-'.uniqid());

    Auth::guard('admin')->setUser($bodega);

    $nombres = app(CortadorDataGrid::class)->prepareQueryBuilder()->pluck('nombre')->all();
    $codigos = app(OrdenCorteDataGrid::class)->prepareQueryBuilder()->pluck('codigo')->all();

    expect($nombres)->toContain($perfilCamilo->nombre)->toContain($perfilJairo->nombre)
        ->and($codigos)->toContain($ordenJairo->codigo);

    $this->actingAs($bodega, 'admin')
        ->get(route('admin.corte_confeccion.cortadores.show', $perfilJairo->id))
        ->assertOk();

    $this->actingAs($bodega, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_corte.show', $ordenJairo->id))
        ->assertOk();
});

test('CORTADOR no puede crear editar ni actualizar perfiles aunque sean propios', function () {
    $camilo = cfais_admin('CORTADOR', cfais_permisosCortador([
        'corte_confeccion.cortadores.create',
        'corte_confeccion.cortadores.edit',
    ]));
    $jairo = cfais_admin('CORTADOR', cfais_permisosCortador());

    $perfilCamilo = cfais_crearPerfilCortador($camilo, 'Cortador Mutacion Camilo CFAIS '.uniqid());
    $perfilJairo = cfais_crearPerfilCortador($jairo, 'Cortador Mutacion Jairo CFAIS '.uniqid());

    $this->actingAs($camilo, 'admin')
        ->get(route('admin.corte_confeccion.cortadores.create'))
        ->assertForbidden();

    $this->actingAs($camilo, 'admin')
        ->get(route('admin.corte_confeccion.cortadores.edit', $perfilCamilo->id))
        ->assertForbidden();

    $this->actingAs($camilo, 'admin')
        ->put(route('admin.corte_confeccion.cortadores.update', $perfilCamilo->id), [
            'nombre' => 'Intento Cortador CFAIS',
            'estado' => 'inactivo',
            'usuario_id' => $perfilJairo->usuario_id,
        ])
        ->assertForbidden();

    expect($perfilCamilo->fresh()->estado)->toBe('activo');
});

test('la ficha visual de cortador replica estructura de Talleres y apunta al DataGrid filtrado', function () {
    $admin = cfais_admin('Admin Cortador Vista CFAIS', [], 'all');
    Auth::guard('admin')->setUser($admin);

    $camilo = cfais_admin('CORTADOR', cfais_permisosCortador());
    $perfil = cfais_crearPerfilCortador($camilo, 'Cortador Visual CFAIS '.uniqid());
    cfais_crearOrdenCorte($camilo, 'OC-CFAIS-VIS-'.uniqid());

    $response = $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.cortadores.show', $perfil->id));

    $response->assertOk();
    $response->assertSee('v-cortador-view-template', false);
    $response->assertSee('Ordenes de corte', false);
    $response->assertSee('Historial del cortador', false);
    $response->assertSee('v-cortador-edit-template', false);
    $response->assertSee('Editar cortador', false);
    $response->assertSee('mt-3.5 flex items-start gap-2.5 max-xl:flex-wrap', false);
    $response->assertSee('box-shadow min-w-0 overflow-hidden rounded bg-white p-4', false);
    $response->assertSee('w-[360px]', false);
    $response->assertSee('shrink-0', false);
    $response->assertSee('variant="link"', false);
    $response->assertSee('cortador_id='.$camilo->id, false);
});

test('el listado de cortadores crea desde modal y no enlaza a la pagina create', function () {
    $admin = cfais_admin('Admin Cortador Modal CFAIS', [], 'all');
    Auth::guard('admin')->setUser($admin);

    $response = $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.cortadores.index'));

    $response->assertOk();
    $response->assertSee('v-cortador-create-template', false);
    $response->assertSee('Crear cortador', false);
    $response->assertSee('ref="cortadoresDatagrid"', false);
    $response->assertDontSee('href="'.route('admin.corte_confeccion.cortadores.create'), false);
});

test('la ruta create de cortadores ya no renderiza vista propia y redirecciona al listado', function () {
    $admin = cfais_admin('Admin Cortador Create Redirect CFAIS', [], 'all');

    $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.cortadores.create'))
        ->assertRedirect(route('admin.corte_confeccion.cortadores.index'));
});

test('crear cortador por ajax lo deja activo y devuelve json para refrescar el DataGrid', function () {
    $admin = cfais_admin('Admin Cortador Store Ajax CFAIS', [], 'all');
    $usuarioCortador = cfais_admin('CORTADOR', cfais_permisosCortador());
    $nombre = 'Cortador Ajax CFAIS '.uniqid();

    $response = $this->actingAs($admin, 'admin')
        ->postJson(route('admin.corte_confeccion.cortadores.store'), [
            'nombre' => $nombre,
            'nit' => '900111222',
            'contacto' => 'Responsable Ajax',
            'telefono' => '3001112233',
            'direccion' => 'Direccion Ajax',
            'estado' => 'activo',
            'observacion' => 'Creado desde modal',
            'usuario_id' => $usuarioCortador->id,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.cortador.nombre', $nombre)
        ->assertJsonPath('data.cortador.estado', 'activo')
        ->assertJsonPath('data.cortador.usuario.id', $usuarioCortador->id);

    expect(Cortador::where('nombre', $nombre)->exists())->toBeTrue();
});
