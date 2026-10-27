<?php

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Services\CompraTelaService;
use ByLopez\CorteConfeccion\Services\OrdenCorteService;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use ByLopez\CorteConfeccion\Services\RecepcionCorteService;
use ByLopez\CorteConfeccion\Services\TallerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
|--------------------------------------------------------------------------
| Fase 03C1 -- base visual y de datos de "Distribuir a talleres".
|
| Fixtures autosuficientes (prefijo "cbf_" = "Crear Base Frontend") en vez
| de depender de los helpers globales "dist_" de DistribucionConfeccionTest.php:
| Pest solo requiere los archivos que efectivamente se le pasan al ejecutar,
| asi que este archivo debe poder correr solo (mismo patron ya usado en el
| paquete: cada archivo de test es autosuficiente, ver VarianteConsultaServiceTest.php).
|
| No se testea aqui la interaccion Vue (estado por fila, computeds, etc.)
| porque el proyecto no tiene infraestructura de test de componentes Vue --
| eso requiere validacion visual manual, documentada en 03C1_BASE_FRONTEND.md.
|--------------------------------------------------------------------------
*/

function cbf_crearAdmin(string $nombreRol, string $permissionType = 'all'): Admin
{
    $role = Role::factory()->create(['name' => $nombreRol, 'permission_type' => $permissionType]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function cbf_crearTaller(array $overrides = []): Taller
{
    $usuarioId = array_key_exists('usuario_id', $overrides)
        ? $overrides['usuario_id']
        : cbf_crearAdmin('TALLER CONFECCION')->id;

    return app(TallerService::class)->create(array_merge([
        'nombre' => 'Taller CBF QA '.uniqid(),
        'nit' => null,
        'contacto' => null,
        'telefono' => null,
        'direccion' => null,
        'estado' => 'activo',
        'observacion' => null,
        'usuario_id' => $usuarioId,
    ], $overrides));
}

function cbf_crearOrdenCorteRecibida(array $productosCantidades): OrdenCorte
{
    Auth::guard('admin')->setUser(cbf_crearAdmin('Creador CBF QA '.uniqid()));

    $atributoTipoTela = Attribute::firstWhere('code', 'tipo_tela')
        ?? Attribute::factory()->create(['code' => 'tipo_tela', 'type' => 'select', 'admin_name' => 'Tipo de tela']);
    $option = AttributeOption::factory()->for($atributoTipoTela, 'attribute')->create(['admin_name' => 'Tela CBF QA '.uniqid()]);
    $color = cc_test_color_option();

    $proveedor = app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor CBF QA '.uniqid(), 'nit' => (string) random_int(900000000, 999999999),
        'telefono' => '3000000000', 'contacto' => 'QA', 'direccion' => 'Calle QA',
        'tipo_tela_ids' => [$option->id],
        'referencias' => [['tipo_tela_id' => $option->id, 'color_option_id' => $color->id, 'color' => 'Blanco', 'referencia' => 'QA-CBF', 'gramaje' => '180', 'valor_kilo_referencia' => 20000, 'estado' => 'activo']],
        'estado' => 'activo', 'observacion' => null,
    ]);

    $compra = app(CompraTelaService::class)->create([
        'codigo' => 'CT-CBF-'.uniqid(), 'proveedor_id' => $proveedor->id, 'numero_factura' => null,
        'fecha_compra' => now()->toDateString(), 'descuento' => 0, 'impuesto' => 0, 'observacion' => null,
        'rollos' => [['codigo' => 'R-CBF-'.uniqid(), 'tipo_tela_id' => $option->id, 'referencia_tela_id' => $proveedor->referenciasTela()->first()->id, 'color' => 'Blanco', 'peso_inicial' => 100, 'valor_kilo' => 20000]],
    ]);
    $rollo = $compra->rollos()->first();
    $cortador = cbf_crearAdmin('CORTADOR');

    $ordenCorteService = app(OrdenCorteService::class);

    $orden = $ordenCorteService->create([
        'rollo_ids' => [$rollo->id],
        'cortador_id' => $cortador->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => collect($productosCantidades)->map(fn ($p) => [
            'product_id' => $p['product']->id,
            'genero_linea' => 'Nino',
            'talla' => $p['talla'] ?? '',
            'cantidad_proyectada' => $p['cantidad_recibida'] + $p['cantidad_defectuosa'],
        ])->values()->all(),
    ]);

    $orden = $ordenCorteService->entregar($orden);
    $orden = $ordenCorteService->cambiarEstado($orden, EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);

    Auth::guard('admin')->setUser(cbf_crearAdmin('Cortador Reporte CBF QA '.uniqid()));
    $orden = $ordenCorteService->reportarResultado($orden, [
        'resultados' => $orden->detalles->values()->map(fn ($detalleOrden, $i) => [
            'detalle_id' => $detalleOrden->id,
            'cantidad_real_cortada' => $productosCantidades[$i]['cantidad_recibida'] + $productosCantidades[$i]['cantidad_defectuosa'],
        ])->all(),
    ]);

    Auth::guard('admin')->setUser(cbf_crearAdmin('Bodega CBF QA '.uniqid()));
    app(RecepcionCorteService::class)->create($orden, [
        'fecha_recepcion' => now()->toDateString(),
        'merma_total' => 0,
        'recibido_por' => 'Bodega CBF QA',
        'observacion' => null,
        // F0: identidad por orden_corte_detalle_id (no product_id/talla). El
        // indice corresponde 1:1 con $productosCantidades porque
        // $orden->detalles se creo, dos bloques arriba, en ese mismo orden.
        'detalles' => $orden->detalles->values()->map(fn ($detalleOrden, $i) => [
            'orden_corte_detalle_id' => $detalleOrden->id,
            'cantidad_recibida' => $productosCantidades[$i]['cantidad_recibida'],
            'cantidad_defectuosa' => $productosCantidades[$i]['cantidad_defectuosa'],
            'observacion' => null,
        ])->all(),
    ]);

    $ordenCorteService->cerrar($orden->fresh());

    return $orden->fresh()->load(['recepcion.detalles', 'ordenesConfeccion.detalles']);
}

test('create() carga metadata de VarianteConsultaService (sku/nombre/color/talla) por fila', function () {
    $admin = cbf_crearAdmin('Admin Frontend Base QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $ordenCorte = cbf_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 10],
    ]);

    $response = $this->actingAs($admin, 'admin')->get(
        route('admin.corte_confeccion.ordenes_confeccion.create', $ordenCorte->id)
    );

    $response->assertOk();

    $filas = $response->viewData('filas');

    expect($filas)->toHaveCount(1);

    $fila = $filas->first();

    expect($fila['product_id'])->toBe($productoL->id)
        ->and($fila['sku'])->toBe($productoL->sku)
        ->and($fila)->toHaveKeys(['nombre', 'color', 'talla', 'talla_etiqueta', 'stock_actual']);
});

test('la disponibilidad (recibido/defectuosa/apta/ya_distribuido/disponible) llega correcta a la vista', function () {
    $admin = cbf_crearAdmin('Admin Frontend Disponibilidad QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $ordenCorte = cbf_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 10],
    ]);

    $response = $this->actingAs($admin, 'admin')->get(
        route('admin.corte_confeccion.ordenes_confeccion.create', $ordenCorte->id)
    );

    $fila = $response->viewData('filas')->first();

    expect($fila['cantidad_recibida'])->toBe(100)
        ->and($fila['cantidad_defectuosa'])->toBe(10)
        ->and($fila['apta_confeccion'])->toBe(90)
        ->and($fila['ya_distribuido'])->toBe(0)
        ->and($fila['disponible_confeccion'])->toBe(90);
});

test('stock 0 llega como entero 0 a la vista, nunca convertido a null ni omitido', function () {
    $admin = cbf_crearAdmin('Admin Frontend Stock Cero QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $ordenCorte = cbf_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0],
    ]);

    DB::table('product_inventory_indices')->insert([
        'product_id' => $productoL->id,
        'channel_id' => core()->getDefaultChannel()->id,
        'qty' => 0,
    ]);

    $response = $this->actingAs($admin, 'admin')->get(
        route('admin.corte_confeccion.ordenes_confeccion.create', $ordenCorte->id)
    );

    $fila = $response->viewData('filas')->first();

    expect($fila['stock_actual'])->toBe(0)->not->toBeNull();
});

test('stock sin indice llega como null (no disponible), nunca como 0', function () {
    $admin = cbf_crearAdmin('Admin Frontend Stock Null QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $ordenCorte = cbf_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0],
    ]);

    $response = $this->actingAs($admin, 'admin')->get(
        route('admin.corte_confeccion.ordenes_confeccion.create', $ordenCorte->id)
    );

    $fila = $response->viewData('filas')->first();

    expect($fila['stock_actual'])->toBeNull();
});

test('solo se listan talleres activos para el selector de la vista', function () {
    $admin = cbf_crearAdmin('Admin Frontend Talleres QA');
    Auth::guard('admin')->setUser($admin);

    cbf_crearTaller(['nombre' => 'Taller Activo Frontend QA']);
    cbf_crearTaller(['nombre' => 'Taller Inactivo Frontend QA', 'estado' => 'inactivo']);

    $productoL = Product::factory()->simple()->create();
    $ordenCorte = cbf_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0],
    ]);

    $response = $this->actingAs($admin, 'admin')->get(
        route('admin.corte_confeccion.ordenes_confeccion.create', $ordenCorte->id)
    );

    $talleres = $response->viewData('talleres');
    $nombres = $talleres->pluck('nombre');

    expect($nombres)->toContain('Taller Activo Frontend QA')
        ->and($nombres)->not->toContain('Taller Inactivo Frontend QA');

    unset($tallerInactivo, $tallerActivo);
});
