<?php

use ByLopez\CorteConfeccion\DataGrids\OrdenConfeccionDataGrid;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenConfeccionDetalle;
use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Services\CompraTelaService;
use ByLopez\CorteConfeccion\Services\CortadorService;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenCorteService;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use ByLopez\CorteConfeccion\Services\RecepcionCorteService;
use ByLopez\CorteConfeccion\Services\TallerService;
use Illuminate\Support\Facades\Auth;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
|--------------------------------------------------------------------------
| Vista detalle (show) de Taller Confeccion. Helpers con prefijo tv_ para
| no chocar con las funciones globales de otros archivos de test.
|--------------------------------------------------------------------------
*/

function tv_crearAdmin(string $nombreRol, string $permissionType = 'custom'): Admin
{
    $role = Role::factory()->create(['name' => $nombreRol, 'permission_type' => $permissionType]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function tv_crearUsuarioTallerConEntrega(): Admin
{
    $role = Role::factory()->create([
        'name' => 'TALLER CONFECCION',
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.reportar_taller'],
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function tv_tipoTelaAttribute(): Attribute
{
    return Attribute::firstWhere('code', 'tipo_tela')
        ?? Attribute::factory()->create(['code' => 'tipo_tela', 'type' => 'select', 'admin_name' => 'Tipo de tela']);
}

function tv_datosTaller(array $overrides = []): array
{
    return array_merge([
        'nombre' => 'Taller QA TV '.uniqid(),
        'nit' => null,
        'contacto' => null,
        'telefono' => null,
        'direccion' => null,
        'estado' => 'activo',
        'observacion' => null,
        'usuario_id' => tv_crearAdmin('TALLER CONFECCION')->id,
    ], $overrides);
}

function tv_crearPerfilCortador(Admin $usuario): void
{
    if (Cortador::query()->where('usuario_id', $usuario->id)->exists()) {
        return;
    }

    app(CortadorService::class)->create([
        'nombre' => 'Cortador QA TV '.uniqid(),
        'nit' => null,
        'contacto' => null,
        'telefono' => null,
        'direccion' => null,
        'estado' => 'activo',
        'observacion' => null,
        'usuario_id' => $usuario->id,
    ]);
}

/**
 * Crea una OrdenConfeccion real y completa: proveedor -> compra -> rollo ->
 * orden de corte cerrada -> orden de confeccion para un taller dado.
 * Autosuficiente, no depende de otros archivos de test.
 */
function tv_crearOrdenConfeccionParaTaller(int $tallerId): OrdenConfeccion
{
    Auth::guard('admin')->setUser(tv_crearAdmin('Creador QA TV '.uniqid(), 'all'));

    $atributoTipoTela = tv_tipoTelaAttribute();
    $option = AttributeOption::factory()->for($atributoTipoTela, 'attribute')->create(['admin_name' => 'Tela QA TV '.uniqid()]);
    $color = cc_test_color_option();

    $proveedor = app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor QA TV '.uniqid(),
        'nit' => (string) random_int(900000000, 999999999),
        'telefono' => '3000000000',
        'contacto' => 'QA',
        'direccion' => 'Calle QA',
        'tipo_tela_ids' => [$option->id],
        'referencias' => [
            ['tipo_tela_id' => $option->id, 'color_option_id' => $color->id, 'color' => 'Blanco', 'referencia' => 'QA-TV', 'gramaje' => '180', 'valor_kilo_referencia' => 20000, 'estado' => 'activo'],
        ],
        'estado' => 'activo',
        'observacion' => null,
    ]);

    $compra = app(CompraTelaService::class)->create([
        'codigo' => 'CT-QA-TV-'.uniqid(),
        'proveedor_id' => $proveedor->id,
        'numero_factura' => null,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'observacion' => null,
        'rollos' => [
            ['codigo' => 'R-QA-TV-'.uniqid(), 'tipo_tela_id' => $option->id, 'referencia_tela_id' => $proveedor->referenciasTela()->first()->id, 'color' => 'Blanco', 'peso_inicial' => 10, 'valor_kilo' => 20000],
        ],
    ]);
    $rollo = $compra->rollos()->first();

    $cortador = tv_crearAdmin('CORTADOR');
    tv_crearPerfilCortador($cortador);
    $producto = Product::factory()->simple()->create();

    $ordenCorteService = app(OrdenCorteService::class);
    $orden = $ordenCorteService->create([
        'rollo_ids' => [$rollo->id],
        'cortador_id' => $cortador->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $producto->id, 'genero_linea' => 'Nino', 'talla' => 'U', 'cantidad_proyectada' => 20],
        ],
    ]);
    $orden = $ordenCorteService->entregar($orden);
    $orden = $ordenCorteService->cambiarEstado($orden, EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);

    $detalle = $orden->detalles->first();
    Auth::guard('admin')->setUser($cortador);
    $orden = $ordenCorteService->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 20]],
    ]);

    Auth::guard('admin')->setUser(tv_crearAdmin('Bodega QA TV '.uniqid(), 'all'));
    app(RecepcionCorteService::class)->create($orden, [
        'fecha_recepcion' => now()->toDateString(),
        'merma_total' => 0,
        'recibido_por' => 'Bodega QA TV',
        'observacion' => null,
        // F0: identidad por orden_corte_detalle_id (no product_id/talla).
        'detalles' => [
            ['orden_corte_detalle_id' => $detalle->id, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0, 'observacion' => null],
        ],
    ]);

    $ordenCorteService->cerrar($orden->fresh());

    return app(OrdenConfeccionService::class)->create([
        'orden_corte_id' => $orden->id,
        'taller_id' => $tallerId,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $producto->id, 'talla' => 'U', 'cantidad_entregada' => 10],
        ],
    ]);
}

function tv_agregarDetalleConfeccion(OrdenConfeccion $orden, int $cantidad, string $talla = 'M'): OrdenConfeccionDetalle
{
    $producto = Product::factory()->simple()->create();

    return $orden->detalles()->create([
        'product_id' => $producto->id,
        'producto_sku' => $producto->sku ?: 'SKU-TV-'.uniqid(),
        'producto_nombre' => $producto->name ?: 'Producto QA TV',
        'talla' => $talla,
        'cantidad_entregada' => $cantidad,
        'prioridad' => 'alta',
    ]);
}

test('la vista detalle del taller muestra sus datos reales y solo sus ordenes de confeccion', function () {
    $admin = tv_crearAdmin('Admin TV', 'all');
    Auth::guard('admin')->setUser($admin);

    $taller = app(TallerService::class)->create([
        'nombre' => 'Taller Rosario QA',
        'nit' => '900123456',
        'contacto' => 'Maria Rosario',
        'telefono' => '3111234567',
        'direccion' => 'Calle 10 # 5-20',
        'estado' => 'activo',
        'observacion' => null,
        'usuario_id' => tv_crearAdmin('TALLER CONFECCION')->id,
    ]);

    $otroTaller = app(TallerService::class)->create(tv_datosTaller(['nombre' => 'Otro Taller QA']));

    tv_crearOrdenConfeccionParaTaller($taller->id);
    tv_crearOrdenConfeccionParaTaller($otroTaller->id);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.talleres.show', $taller->id));

    $response->assertOk();
    $response->assertSee('Taller Rosario QA', false);
    $response->assertSee('Maria Rosario', false);
    $response->assertSee('900123456', false);
    $response->assertSee('3111234567', false);
    $response->assertSee('Calle 10 # 5-20', false);
    // El DataGrid carga sus filas por AJAX (no en el HTML inicial): se verifica
    // que el src apunte al taller correcto; el filtrado en si ya se prueba
    // directamente contra prepareQueryBuilder() en el test siguiente.
    $response->assertSee('taller_id='.$taller->id, false);
});

test('la ruta admin de detalle de orden de confeccion abre sin error 500', function () {
    $admin = tv_crearAdmin('Admin TV Show Confeccion', 'all');
    Auth::guard('admin')->setUser($admin);

    $taller = app(TallerService::class)->create(tv_datosTaller(['nombre' => 'Taller Show Confeccion QA']));
    $orden = tv_crearOrdenConfeccionParaTaller($taller->id);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.show', $orden->id));

    $response->assertOk();
    $response->assertSee('Orden confeccion '.$orden->codigo, false);
    $response->assertSee('Progreso de confeccion', false);
    $response->assertSee('Produccion por variante', false);
});

test('el taller asignado ve controles reales para reportar entrega parcial y masiva desde el frontend', function () {
    $usuarioTaller = tv_crearUsuarioTallerConEntrega();
    $taller = app(TallerService::class)->create(tv_datosTaller([
        'nombre' => 'Taller Front Entrega QA',
        'usuario_id' => $usuarioTaller->id,
    ]));
    $orden = tv_crearOrdenConfeccionParaTaller($taller->id);

    $response = $this->actingAs($usuarioTaller, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.show', $orden->id));

    $response->assertOk();
    $response->assertSee(route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $orden->id), false);
    $response->assertSee('Entrega actual', false);
    $response->assertSee('Seleccionar pendientes', false);
    $response->assertSee('Entregar todo seleccionado', false);
    $response->assertSee('Entregar todo', false);
    $response->assertSee('Revisar entrega', false);
    $response->assertSee('data-report-id', false);
    $response->assertSee('data-report-input', false);
});

test('el POST del frontend permite entrega parcial aunque otras variantes viajen en cero', function () {
    $usuarioTaller = tv_crearUsuarioTallerConEntrega();
    $taller = app(TallerService::class)->create(tv_datosTaller(['usuario_id' => $usuarioTaller->id]));
    $orden = tv_crearOrdenConfeccionParaTaller($taller->id);
    $detalleParcial = $orden->detalles()->first();
    $detalleEnCero = tv_agregarDetalleConfeccion($orden, 15, 'L');

    $response = $this->actingAs($usuarioTaller, 'admin')->post(
        route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $orden->id),
        [
            'fecha_reporte_taller' => now()->toDateString(),
            'observacion_taller' => 'Parcial desde formulario',
            'detalles' => [
                ['id' => $detalleParcial->id, 'cantidad' => 4],
                ['id' => $detalleEnCero->id, 'cantidad' => 0],
            ],
        ]
    );

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    $orden = $orden->fresh(['retornos.detalles', 'detalles.retornosDetalles']);
    $retorno = $orden->retornos->first();

    expect($orden->retornos)->toHaveCount(1)
        ->and($retorno->detalles)->toHaveCount(1)
        ->and((int) $retorno->detalles->sum('cantidad_reportada'))->toBe(4)
        ->and($retorno->observacion_taller)->toBe('Parcial desde formulario')
        ->and($orden->estado)->toBe(EstadoOrdenConfeccion::EnConfeccion->value);
});

test('el POST del frontend permite entrega masiva del pendiente despues de una parcial', function () {
    $usuarioTaller = tv_crearUsuarioTallerConEntrega();
    $taller = app(TallerService::class)->create(tv_datosTaller(['usuario_id' => $usuarioTaller->id]));
    $orden = tv_crearOrdenConfeccionParaTaller($taller->id);
    $detalleA = $orden->detalles()->first();
    $detalleB = tv_agregarDetalleConfeccion($orden, 15, 'L');

    $this->actingAs($usuarioTaller, 'admin')->post(
        route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $orden->id),
        [
            'fecha_reporte_taller' => now()->toDateString(),
            'detalles' => [
                ['id' => $detalleA->id, 'cantidad' => 4],
                ['id' => $detalleB->id, 'cantidad' => 0],
            ],
        ]
    )->assertRedirect()->assertSessionHasNoErrors();

    $response = $this->actingAs($usuarioTaller, 'admin')->post(
        route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $orden->id),
        [
            'fecha_reporte_taller' => now()->toDateString(),
            'detalles' => [
                ['id' => $detalleA->id, 'cantidad' => 6],
                ['id' => $detalleB->id, 'cantidad' => 15],
            ],
        ]
    );

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    $orden = $orden->fresh(['retornos.detalles', 'detalles.retornosDetalles']);

    expect($orden->retornos)->toHaveCount(2)
        ->and((int) $orden->retornos->flatMap->detalles->sum('cantidad_reportada'))->toBe(25)
        ->and($orden->retornos->last()->detalles)->toHaveCount(2)
        ->and($orden->estado)->toBe(EstadoOrdenConfeccion::TerminadaTaller->value);
});

test('el frontend no puede superar el pendiente historico ni reportar una orden de otro taller', function () {
    $usuarioTaller = tv_crearUsuarioTallerConEntrega();
    $taller = app(TallerService::class)->create(tv_datosTaller(['usuario_id' => $usuarioTaller->id]));
    $orden = tv_crearOrdenConfeccionParaTaller($taller->id);
    $detalle = $orden->detalles()->first();

    $this->actingAs($usuarioTaller, 'admin')->post(
        route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $orden->id),
        [
            'fecha_reporte_taller' => now()->toDateString(),
            'detalles' => [
                ['id' => $detalle->id, 'cantidad' => 8],
            ],
        ]
    )->assertRedirect()->assertSessionHasNoErrors();

    $response = $this->actingAs($usuarioTaller, 'admin')->post(
        route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $orden->id),
        [
            'fecha_reporte_taller' => now()->toDateString(),
            'detalles' => [
                ['id' => $detalle->id, 'cantidad' => 3],
            ],
        ]
    );

    $response->assertRedirect();
    $response->assertSessionHasErrors('detalles');

    $otroUsuarioTaller = tv_crearUsuarioTallerConEntrega();

    $this->actingAs($otroUsuarioTaller, 'admin')->post(
        route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $orden->id),
        [
            'fecha_reporte_taller' => now()->toDateString(),
            'detalles' => [
                ['id' => $detalle->id, 'cantidad' => 1],
            ],
        ]
    )->assertForbidden();

    $orden = $orden->fresh(['retornos.detalles']);

    expect($orden->retornos)->toHaveCount(1)
        ->and((int) $orden->retornos->flatMap(fn ($retorno) => $retorno->detalles)->sum('cantidad_reportada'))->toBe(8);
});

test('guardar un taller redirecciona a la ficha visual del taller', function () {
    $admin = tv_crearAdmin('Admin TV Store', 'all');
    Auth::guard('admin')->setUser($admin);

    $nombre = 'Taller Redireccion QA '.uniqid();
    $usuarioTaller = tv_crearAdmin('TALLER CONFECCION');

    $response = $this->actingAs($admin, 'admin')->post(route('admin.corte_confeccion.talleres.store'), [
        'nombre' => $nombre,
        'nit' => '900111222',
        'contacto' => 'Responsable QA',
        'telefono' => '3001112233',
        'direccion' => 'Calle QA',
        'estado' => 'activo',
        'observacion' => null,
        'usuario_id' => $usuarioTaller->id,
    ]);

    $taller = Taller::where('nombre', $nombre)->first();

    expect($taller)->not->toBeNull();
    $response->assertRedirect(route('admin.corte_confeccion.talleres.show', $taller->id));
});

test('la ruta edit no renderiza vista propia y redirecciona al detalle', function () {
    $admin = tv_crearAdmin('Admin TV Edit Redirect', 'all');
    Auth::guard('admin')->setUser($admin);
    $taller = app(TallerService::class)->create(tv_datosTaller(['nombre' => 'Taller Edit Redirect QA']));

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.talleres.edit', $taller->id));

    $response->assertRedirect(route('admin.corte_confeccion.talleres.show', $taller->id));
});

test('la ficha del taller incluye el segundo bloque placeholder tipo Cliente/Facturas', function () {
    $admin = tv_crearAdmin('Admin TV Placeholder', 'all');
    Auth::guard('admin')->setUser($admin);
    $taller = app(TallerService::class)->create(tv_datosTaller(['nombre' => 'Taller Placeholder QA']));

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.talleres.show', $taller->id));

    $response->assertOk();
    $response->assertSee('Historial del taller', false);
    $response->assertSee('icon-report', false);
});

test('la ficha visual del taller incluye el modal de edicion tipo cliente', function () {
    $admin = tv_crearAdmin('Admin TV Modal', 'all');
    Auth::guard('admin')->setUser($admin);
    $taller = app(TallerService::class)->create(tv_datosTaller(['nombre' => 'Taller Modal QA']));

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.talleres.show', $taller->id));

    $response->assertOk();
    $response->assertSee('v-taller-edit-template', false);
    $response->assertSee('Editar taller', false);
    $response->assertSee('mt-3.5 flex items-start gap-2.5 max-xl:flex-wrap', false);
    $response->assertSee('box-shadow min-w-0 overflow-hidden rounded bg-white p-4', false);
    // Rediseño tipo Cliente (patrón aprobado en docs/GUIA_VISUAL_ADMIN_BAGISTO.md): el
    // panel lateral "Usuario y ubicación" se fusionó dentro del panel único "Taller".
    $response->assertSee('w-[360px]', false);
    $response->assertSee('shrink-0', false);
    $response->assertSee('variant="link"', false);
    $response->assertDontSee('href="'.route('admin.corte_confeccion.talleres.edit', $taller->id), false);
});

test('actualizar taller por ajax devuelve json para refrescar la ficha visual', function () {
    $admin = tv_crearAdmin('Admin TV Ajax', 'all');
    Auth::guard('admin')->setUser($admin);

    $taller = app(TallerService::class)->create(tv_datosTaller(['nombre' => 'Taller Ajax QA']));
    $nuevoUsuario = tv_crearAdmin('TALLER CONFECCION');

    $response = $this->actingAs($admin, 'admin')->putJson(route('admin.corte_confeccion.talleres.update', $taller->id), [
        'nombre' => 'Taller Ajax Actualizado QA',
        'nit' => '900333444',
        'contacto' => 'Responsable actualizado',
        'telefono' => '3013334444',
        'direccion' => 'Direccion actualizada',
        'estado' => 'inactivo',
        'observacion' => 'Observacion actualizada',
        'usuario_id' => $nuevoUsuario->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.taller.nombre', 'Taller Ajax Actualizado QA')
        ->assertJsonPath('data.taller.estado', 'inactivo')
        ->assertJsonPath('data.taller.usuario.id', $nuevoUsuario->id);

    expect($taller->fresh()->usuario_id)->toBe($nuevoUsuario->id);
});

test('el filtro taller_id de OrdenConfeccionDataGrid solo devuelve ordenes del taller solicitado', function () {
    $admin = tv_crearAdmin('Admin TV Grid', 'all');
    Auth::guard('admin')->setUser($admin);

    $tallerA = app(TallerService::class)->create(tv_datosTaller(['nombre' => 'Taller A QA']));
    $tallerB = app(TallerService::class)->create(tv_datosTaller(['nombre' => 'Taller B QA']));

    $ordenA = tv_crearOrdenConfeccionParaTaller($tallerA->id);
    tv_crearOrdenConfeccionParaTaller($tallerB->id);

    request()->merge(['taller_id' => $tallerA->id]);

    $ids = app(OrdenConfeccionDataGrid::class)->prepareQueryBuilder()->pluck('id');

    expect($ids->all())->toBe([$ordenA->id]);
});

test('la vista de taller respeta permisos: sin permiso de editar no muestra el enlace Editar, sin permiso de crear confeccion no muestra el boton', function () {
    $rolLimitado = Role::factory()->create([
        'name' => 'Solo Ver Talleres QA',
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.talleres'],
    ]);
    $usuarioLimitado = Admin::factory()->create(['role_id' => $rolLimitado->id]);

    $admin = tv_crearAdmin('Admin TV Perm', 'all');
    Auth::guard('admin')->setUser($admin);
    $taller = app(TallerService::class)->create(tv_datosTaller(['nombre' => 'Taller Permisos QA']));

    $response = $this->actingAs($usuarioLimitado, 'admin')->get(route('admin.corte_confeccion.talleres.show', $taller->id));

    $response->assertOk();
    $response->assertDontSee('Crear Confeccion', false);
    $response->assertDontSee(route('admin.corte_confeccion.talleres.edit', $taller->id), false);
});

test('la ficha del taller ya no enlaza a crear confeccion y muestra los 4 indicadores en su lugar', function () {
    $admin = tv_crearAdmin('Admin TV Boton', 'all');
    Auth::guard('admin')->setUser($admin);
    $taller = app(TallerService::class)->create(tv_datosTaller(['nombre' => 'Taller Boton QA']));
    tv_crearOrdenConfeccionParaTaller($taller->id);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.talleres.show', $taller->id));

    $response->assertOk();
    // Rediseño tipo Cliente: la franja superior de acciones deja de tener el boton
    // "Crear Confeccion" (ese flujo no pertenece al detalle de un taller ya creado)
    // y en su lugar muestra los indicadores de solo lectura del taller.
    $response->assertDontSee('Crear Confeccion', false);
    $response->assertDontSee(route('admin.corte_confeccion.ordenes_corte.index', ['estado' => EstadoOrdenCorte::Cerrado->value]), false);
    $response->assertSee('icon-processing', false);
    $response->assertSee('icon-product', false);
    $response->assertSee('icon-pause', false);
    $response->assertSee('icon-ship', false);
    $response->assertSee('resumen.ordenes_activas', false);
    $response->assertSee('resumen.prendas_asignadas', false);
    $response->assertSee('resumen.pendiente_taller', false);
    $response->assertSee('resumen.pendiente_recepcion', false);
});

test('crear orden de confeccion preselecciona el taller cuando llega taller_id por query string', function () {
    $admin = tv_crearAdmin('Admin TV Preseleccion', 'all');
    Auth::guard('admin')->setUser($admin);

    $taller = app(TallerService::class)->create(tv_datosTaller(['nombre' => 'Taller Preseleccion QA']));

    // Necesitamos una orden de corte cerrada para llegar a la pantalla de crear.
    Auth::guard('admin')->setUser(tv_crearAdmin('Creador QA TV Presel', 'all'));
    $atributoTipoTela = tv_tipoTelaAttribute();
    $option = AttributeOption::factory()->for($atributoTipoTela, 'attribute')->create(['admin_name' => 'Tela QA TV Presel '.uniqid()]);
    $color = cc_test_color_option();
    $proveedor = app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor QA TV Presel '.uniqid(), 'nit' => (string) random_int(900000000, 999999999),
        'telefono' => '3000000000', 'contacto' => 'QA', 'direccion' => 'Calle QA',
        'tipo_tela_ids' => [$option->id],
        'referencias' => [['tipo_tela_id' => $option->id, 'color_option_id' => $color->id, 'color' => 'Blanco', 'referencia' => 'QA-TVP', 'gramaje' => '180', 'valor_kilo_referencia' => 20000, 'estado' => 'activo']],
        'estado' => 'activo', 'observacion' => null,
    ]);
    $compra = app(CompraTelaService::class)->create([
        'codigo' => 'CT-QA-TVP-'.uniqid(), 'proveedor_id' => $proveedor->id, 'numero_factura' => null,
        'fecha_compra' => now()->toDateString(), 'descuento' => 0, 'impuesto' => 0, 'observacion' => null,
        'rollos' => [['codigo' => 'R-QA-TVP-'.uniqid(), 'tipo_tela_id' => $option->id, 'referencia_tela_id' => $proveedor->referenciasTela()->first()->id, 'color' => 'Blanco', 'peso_inicial' => 10, 'valor_kilo' => 20000]],
    ]);
    $rollo = $compra->rollos()->first();
    $cortador = tv_crearAdmin('CORTADOR');
    tv_crearPerfilCortador($cortador);
    $producto = Product::factory()->simple()->create();

    $ordenCorteService = app(OrdenCorteService::class);
    $orden = $ordenCorteService->create([
        'rollo_ids' => [$rollo->id], 'cortador_id' => $cortador->id, 'fecha_entrega' => now()->toDateString(), 'observacion' => null,
        'detalles' => [['product_id' => $producto->id, 'genero_linea' => 'Nino', 'talla' => 'U', 'cantidad_proyectada' => 20]],
    ]);
    $orden = $ordenCorteService->entregar($orden);
    $orden = $ordenCorteService->cambiarEstado($orden, EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);
    $detalle = $orden->detalles->first();
    Auth::guard('admin')->setUser($cortador);
    $orden = $ordenCorteService->reportarResultado($orden, ['resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 20]]]);
    Auth::guard('admin')->setUser(tv_crearAdmin('Bodega QA TV Presel '.uniqid(), 'all'));
    app(RecepcionCorteService::class)->create($orden, [
        'fecha_recepcion' => now()->toDateString(), 'merma_total' => 0,
        'recibido_por' => 'Bodega QA', 'observacion' => null,
        // F0: identidad por orden_corte_detalle_id (no product_id/talla).
        'detalles' => [['orden_corte_detalle_id' => $detalle->id, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0, 'observacion' => null]],
    ]);
    $ordenCorteService->cerrar($orden->fresh());

    $response = $this->actingAs($admin, 'admin')->get(
        route('admin.corte_confeccion.ordenes_confeccion.create', $orden->id).'?taller_id='.$taller->id
    );

    $response->assertOk();
    $response->assertSee('Taller Preseleccion QA', false);
});
