<?php

use ByLopez\CorteConfeccion\DataGrids\OrdenConfeccionDataGrid;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccionRetorno;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Enums\PrioridadOrdenConfeccion;
use ByLopez\CorteConfeccion\Enums\TipoInventarioMovimiento;
use ByLopez\CorteConfeccion\Models\InventarioMovimiento;
use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenConfeccionDetalle;
use ByLopez\CorteConfeccion\Models\OrdenConfeccionRetorno;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Services\CompraTelaService;
use ByLopez\CorteConfeccion\Services\DisponibilidadConfeccionService;
use ByLopez\CorteConfeccion\Services\InventarioMovimientoService;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenCorteService;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use ByLopez\CorteConfeccion\Services\RecepcionCorteService;
use ByLopez\CorteConfeccion\Services\TallerService;
use ByLopez\CorteConfeccion\Services\VarianteConsultaService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
|--------------------------------------------------------------------------
| Fixtures autosuficientes para el motor de distribucion (Fase 03A).
| Prefijo "dist_" para no chocar con helpers globales de otros archivos
| de test (mismo patron ya usado con "tv_" en TallerVistaDetalleTest.php).
|--------------------------------------------------------------------------
*/

function dist_crearAdmin(string $nombreRol, string $permissionType = 'all'): Admin
{
    $role = Role::factory()->create(['name' => $nombreRol, 'permission_type' => $permissionType]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function dist_crearTaller(array $overrides = []): Taller
{
    $usuarioId = array_key_exists('usuario_id', $overrides)
        ? $overrides['usuario_id']
        : dist_crearAdmin('TALLER CONFECCION')->id;

    return app(TallerService::class)->create(array_merge([
        'nombre' => 'Taller Dist QA '.uniqid(),
        'nit' => null,
        'contacto' => null,
        'telefono' => null,
        'direccion' => null,
        'estado' => 'activo',
        'observacion' => null,
        'usuario_id' => $usuarioId,
    ], $overrides));
}

/**
 * Crea una OrdenCorte real, recibida en bodega, con una fila de recepcion
 * por cada producto en $productosCantidades (['product' => Product, 'cantidad_recibida' => int, 'cantidad_defectuosa' => int]).
 * Devuelve la OrdenCorte fresca con 'recepcion.detalles' cargable.
 */
function dist_crearOrdenCorteRecibida(array $productosCantidades): OrdenCorte
{
    Auth::guard('admin')->setUser(dist_crearAdmin('Creador Dist QA '.uniqid()));

    $atributoTipoTela = Attribute::firstWhere('code', 'tipo_tela')
        ?? Attribute::factory()->create(['code' => 'tipo_tela', 'type' => 'select', 'admin_name' => 'Tipo de tela']);
    $option = AttributeOption::factory()->for($atributoTipoTela, 'attribute')->create(['admin_name' => 'Tela Dist QA '.uniqid()]);
    $color = cc_test_color_option();

    $proveedor = app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor Dist QA '.uniqid(), 'nit' => (string) random_int(900000000, 999999999),
        'telefono' => '3000000000', 'contacto' => 'QA', 'direccion' => 'Calle QA',
        'tipo_tela_ids' => [$option->id],
        'referencias' => [['tipo_tela_id' => $option->id, 'color_option_id' => $color->id, 'color' => 'Blanco', 'referencia' => 'QA-DIST', 'gramaje' => '180', 'valor_kilo_referencia' => 20000, 'estado' => 'activo']],
        'estado' => 'activo', 'observacion' => null,
    ]);

    $compra = app(CompraTelaService::class)->create([
        'codigo' => 'CT-DIST-'.uniqid(), 'proveedor_id' => $proveedor->id, 'numero_factura' => null,
        'fecha_compra' => now()->toDateString(), 'descuento' => 0, 'impuesto' => 0, 'observacion' => null,
        'rollos' => [['codigo' => 'R-DIST-'.uniqid(), 'tipo_tela_id' => $option->id, 'referencia_tela_id' => $proveedor->referenciasTela()->first()->id, 'color' => 'Blanco', 'peso_inicial' => 100, 'valor_kilo' => 20000]],
    ]);
    $rollo = $compra->rollos()->first();
    $cortador = dist_crearAdmin('CORTADOR');

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

    Auth::guard('admin')->setUser(dist_crearAdmin('Cortador Reporte Dist QA '.uniqid()));
    $orden = $ordenCorteService->reportarResultado($orden, [
        'resultados' => $orden->detalles->values()->map(fn ($detalleOrden, $i) => [
            'detalle_id' => $detalleOrden->id,
            'cantidad_real_cortada' => $productosCantidades[$i]['cantidad_recibida'] + $productosCantidades[$i]['cantidad_defectuosa'],
        ])->all(),
    ]);

    Auth::guard('admin')->setUser(dist_crearAdmin('Bodega Dist QA '.uniqid()));
    app(RecepcionCorteService::class)->create($orden, [
        'fecha_recepcion' => now()->toDateString(),
        'merma_total' => 0,
        'recibido_por' => 'Bodega Dist QA',
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

function dist_sembrarStockBagisto(Product $producto, int $cantidad): object
{
    $source = app(InventarioMovimientoService::class)->resolverFuenteInventario();
    $channelId = core()->getDefaultChannel()->id;

    DB::table('product_inventories')->updateOrInsert(
        [
            'product_id' => $producto->id,
            'inventory_source_id' => $source->id,
            'vendor_id' => 0,
        ],
        ['qty' => $cantidad]
    );

    DB::table('product_inventory_indices')->updateOrInsert(
        [
            'product_id' => $producto->id,
            'channel_id' => $channelId,
        ],
        [
            'qty' => $cantidad,
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );

    return $source;
}

function dist_stockFisico(Product $producto, int $sourceId): int
{
    return (int) DB::table('product_inventories')
        ->where('product_id', $producto->id)
        ->where('inventory_source_id', $sourceId)
        ->where('vendor_id', 0)
        ->value('qty');
}

function dist_stockIndexado(Product $producto): int
{
    return (int) DB::table('product_inventory_indices')
        ->where('product_id', $producto->id)
        ->where('channel_id', core()->getDefaultChannel()->id)
        ->value('qty');
}

/*
|--------------------------------------------------------------------------
| Bloque 1+2: prioridad (default) y disponibilidad/anulacion.
|--------------------------------------------------------------------------
*/

test('una OrdenConfeccionDetalle nueva queda en prioridad normal por defecto', function () {
    $admin = dist_crearAdmin('Admin Prioridad QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->create([
        'orden_corte_id' => $ordenCorte->id,
        'taller_id' => $taller->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $productoL->id, 'talla' => '', 'cantidad_entregada' => 60],
        ],
    ]);

    expect($orden->detalles->first()->prioridad)->toBe('normal');
});

test('CASO 5 - anular una OrdenConfeccion devuelve la cantidad al disponible', function () {
    $admin = dist_crearAdmin('Admin Anulacion QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
    ]);

    $disponibilidad = app(DisponibilidadConfeccionService::class);

    expect($disponibilidad->disponiblePara($ordenCorte, $productoL->id))->toBe(100);

    $orden = app(OrdenConfeccionService::class)->create([
        'orden_corte_id' => $ordenCorte->id,
        'taller_id' => $taller->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $productoL->id, 'talla' => '', 'cantidad_entregada' => 60],
        ],
    ]);

    expect($disponibilidad->disponiblePara($ordenCorte->fresh(), $productoL->id))->toBe(40);

    app(OrdenConfeccionService::class)->cancel($orden);

    expect($disponibilidad->disponiblePara($ordenCorte->fresh(), $productoL->id))->toBe(100);
});

/*
|--------------------------------------------------------------------------
| F0.1 - DisponibilidadConfeccionService agrega por product_id cuando el
| mismo producto aparece en mas de una fila de RecepcionCorteDetalle de la
| misma recepcion (ej. solicitud + adicional del mismo producto). Antes de
| este fix, disponiblePara() tomaba solo la PRIMERA fila coincidente y
| distribuir() (via ->keyBy('product_id')) tomaba solo la ULTIMA -- ambos
| casos subcontaban lo realmente disponible.
|--------------------------------------------------------------------------
*/

test('F0.1 - porOrdenCorte() y disponiblePara() suman las cantidades cuando el mismo product_id aparece en dos filas de la recepcion', function () {
    $admin = dist_crearAdmin('Admin F01 QA '.uniqid());
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();

    // Dos filas de la orden para el MISMO producto (equivalente a una
    // solicitud + un adicional del mismo producto): la recepcion termina
    // con 2 RecepcionCorteDetalle que comparten product_id.
    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 60, 'cantidad_defectuosa' => 0],
        ['product' => $producto, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 5],
    ]);

    $disponibilidad = app(DisponibilidadConfeccionService::class);
    $filas = $disponibilidad->porOrdenCorte($ordenCorte);

    // Una sola fila agregada por product_id, no dos.
    expect($filas)->toHaveCount(1);

    $fila = $filas->first();

    expect((int) $fila['cantidad_recibida'])->toBe(100)
        ->and((int) $fila['cantidad_defectuosa'])->toBe(5)
        ->and((int) $fila['cantidad_apta_confeccion'])->toBe(95)
        ->and((int) $fila['cantidad_disponible_confeccion'])->toBe(95);

    // 95 = suma real (60+40-5). Antes del fix: 60 (primera fila) o 35
    // (60-25... segun cual "ganara"), nunca la suma real.
    expect($disponibilidad->disponiblePara($ordenCorte, $producto->id))->toBe(95);
});

test('F0.1 - distribuir() acepta una cantidad mayor a cualquier fila individual de recepcion cuando el total agregado la cubre', function () {
    $admin = dist_crearAdmin('Admin F01 Distribuir QA '.uniqid());
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 60, 'cantidad_defectuosa' => 0],
        ['product' => $producto, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    // 90 supera cualquiera de las dos filas individuales (60 o 40) pero es
    // menor que el total real disponible (100). Con el bug de F0.1 sin
    // corregir, esto se habria rechazado como cantidad-excede-disponible.
    $orden = app(OrdenConfeccionService::class)->create([
        'orden_corte_id' => $ordenCorte->id,
        'taller_id' => $taller->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $producto->id, 'talla' => '', 'cantidad_entregada' => 90],
        ],
    ]);

    expect((int) $orden->detalles->first()->cantidad_entregada)->toBe(90);

    $disponibilidad = app(DisponibilidadConfeccionService::class);
    expect($disponibilidad->disponiblePara($ordenCorte->fresh(), $producto->id))->toBe(10);
});

test('una OrdenConfeccion anulada no bloquea una nueva distribucion sobre la misma cantidad', function () {
    $admin = dist_crearAdmin('Admin Anulacion Redistribuir QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $tallerA = dist_crearTaller();
    $tallerB = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
    ]);

    $ordenA = app(OrdenConfeccionService::class)->create([
        'orden_corte_id' => $ordenCorte->id,
        'taller_id' => $tallerA->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [['product_id' => $productoL->id, 'talla' => '', 'cantidad_entregada' => 100]],
    ]);

    app(OrdenConfeccionService::class)->cancel($ordenA);

    // Tras anular, las 100 unidades vuelven a estar disponibles para Taller B.
    $ordenB = app(OrdenConfeccionService::class)->create([
        'orden_corte_id' => $ordenCorte->fresh()->id,
        'taller_id' => $tallerB->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [['product_id' => $productoL->id, 'talla' => '', 'cantidad_entregada' => 100]],
    ]);

    expect($ordenB->detalles->first()->cantidad_entregada)->toBe(100);
});

test('el endpoint store no descarta detalles con cantidad positiva sin producto y rechaza toda la orden', function () {
    $admin = dist_crearAdmin('Admin Store Detalle Incompleto QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $productoM = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 60, 'cantidad_defectuosa' => 0],
        ['product' => $productoM, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    $response = $this->actingAs($admin, 'admin')->post(
        route('admin.corte_confeccion.ordenes_confeccion.store'),
        [
            'orden_corte_id' => $ordenCorte->id,
            'taller_id' => $taller->id,
            'detalles' => [
                ['product_id' => $productoL->id, 'talla' => '', 'cantidad_entregada' => 60],
                ['talla' => '', 'cantidad_entregada' => 40],
            ],
        ]
    );

    $response->assertSessionHasErrors('detalles.1.product_id');

    expect(OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Bloque 4: validacion de product_id "simple" (rechazo de configurable).
|--------------------------------------------------------------------------
*/

test('CASO 7 - un product_id de un producto configurable es rechazado sin crear ninguna orden', function () {
    $admin = dist_crearAdmin('Admin Configurable QA');
    Auth::guard('admin')->setUser($admin);

    $productoSimple = Product::factory()->simple()->create();
    $productoConfigurable = Product::factory()->configurable()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoSimple, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
    ]);

    expect(fn () => app(OrdenConfeccionService::class)->create([
        'orden_corte_id' => $ordenCorte->id,
        'taller_id' => $taller->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $productoConfigurable->id, 'talla' => '', 'cantidad_entregada' => 10],
        ],
    ]))->toThrow(ValidationException::class);

    expect(OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Bloques 5-7: payload/Request, Service::distribuir(), transaccion/rollback.
|--------------------------------------------------------------------------
*/

test('CASO 1 - una sola operacion distribuye una variante entre 2 talleres y CASO 2 - otra variante queda pendiente', function () {
    $admin = dist_crearAdmin('Admin MultiTaller QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $productoM = Product::factory()->simple()->create();
    $tallerA = dist_crearTaller();
    $tallerB = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
        ['product' => $productoM, 'cantidad_recibida' => 80, 'cantidad_defectuosa' => 0],
    ]);

    $ordenes = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            [
                'taller_id' => $tallerA->id,
                'fecha_entrega' => now()->toDateString(),
                'detalles' => [
                    ['product_id' => $productoL->id, 'cantidad' => 60],
                ],
            ],
            [
                'taller_id' => $tallerB->id,
                'fecha_entrega' => now()->toDateString(),
                'detalles' => [
                    ['product_id' => $productoL->id, 'cantidad' => 20],
                ],
            ],
        ],
    ]);

    expect($ordenes)->toHaveCount(2)
        ->and($ordenes->pluck('taller_id')->sort()->values()->all())->toBe(collect([$tallerA->id, $tallerB->id])->sort()->values()->all());

    $disponibilidad = app(DisponibilidadConfeccionService::class);
    $ordenCorteFresca = $ordenCorte->fresh();

    // CASO 1: disponible restante de la variante distribuida = 100 - 60 - 20 = 20.
    expect($disponibilidad->disponiblePara($ordenCorteFresca, $productoL->id))->toBe(20);

    // CASO 2: la variante M nunca fue referenciada en el payload -> sigue
    // intacta, y no se creo ningun OrdenConfeccionDetalle de cantidad 0 para ella.
    expect($disponibilidad->disponiblePara($ordenCorteFresca, $productoM->id))->toBe(80);

    $detallesM = OrdenConfeccionDetalle::query()
        ->where('product_id', $productoM->id)
        ->count();
    expect($detallesM)->toBe(0);
});

test('CASO 3 - si la suma entre talleres excede el disponible se rechaza toda la operacion', function () {
    $admin = dist_crearAdmin('Admin Exceso QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $tallerA = dist_crearTaller();
    $tallerB = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
    ]);

    $payload = [
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $tallerA->id, 'detalles' => [['product_id' => $productoL->id, 'cantidad' => 60]]],
            ['taller_id' => $tallerB->id, 'detalles' => [['product_id' => $productoL->id, 'cantidad' => 50]]],
        ],
    ];

    expect(fn () => app(OrdenConfeccionService::class)->distribuir($payload))
        ->toThrow(ValidationException::class);

    expect(OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->count())->toBe(0);
});

test('CASO 4 - si una asignacion falla (taller inactivo) se revierte toda la operacion (rollback total)', function () {
    $admin = dist_crearAdmin('Admin Rollback QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $tallerActivo = dist_crearTaller();
    $tallerInactivo = dist_crearTaller(['estado' => 'inactivo']);

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
    ]);

    $payload = [
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            // Esta asignacion, aislada, seria perfectamente valida...
            ['taller_id' => $tallerActivo->id, 'detalles' => [['product_id' => $productoL->id, 'cantidad' => 60]]],
            // ...pero esta otra referencia un taller inactivo.
            ['taller_id' => $tallerInactivo->id, 'detalles' => [['product_id' => $productoL->id, 'cantidad' => 20]]],
        ],
    ];

    expect(fn () => app(OrdenConfeccionService::class)->distribuir($payload))
        ->toThrow(ValidationException::class);

    // Ni siquiera la asignacion del taller activo (que era valida en
    // aislamiento) debe haber quedado creada.
    expect(OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->count())->toBe(0);

    $disponibilidad = app(DisponibilidadConfeccionService::class);
    expect($disponibilidad->disponiblePara($ordenCorte->fresh(), $productoL->id))->toBe(100);
});

test('CASO 8 - cada OrdenConfeccionDetalle conserva su propia prioridad dentro del mismo taller', function () {
    $admin = dist_crearAdmin('Admin Prioridad Multi QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $productoM = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
        ['product' => $productoM, 'cantidad_recibida' => 80, 'cantidad_defectuosa' => 0],
    ]);

    $ordenes = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            [
                'taller_id' => $taller->id,
                'detalles' => [
                    ['product_id' => $productoL->id, 'cantidad' => 60, 'prioridad' => 'urgente'],
                    ['product_id' => $productoM->id, 'cantidad' => 40, 'prioridad' => 'normal'],
                ],
            ],
        ],
    ]);

    $detalles = $ordenes->first()->detalles->keyBy('product_id');

    expect($detalles[$productoL->id]->prioridad)->toBe('urgente')
        ->and($detalles[$productoM->id]->prioridad)->toBe('normal');
});

/*
|--------------------------------------------------------------------------
| Ajuste post-aprobacion: una operacion de distribucion nunca puede generar
| mas de una OrdenConfeccion para el mismo Taller, aunque cada bloque
| referencie variantes distintas (no se fusionan: fecha_entrega/observacion
| podrian diferir entre bloques).
|--------------------------------------------------------------------------
*/

test('taller_id duplicado entre asignaciones (Service) se rechaza sin crear ninguna orden', function () {
    $admin = dist_crearAdmin('Admin TallerDuplicado QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $productoM = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 60, 'cantidad_defectuosa' => 0],
        ['product' => $productoM, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    $payload = [
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $taller->id, 'detalles' => [['product_id' => $productoL->id, 'cantidad' => 60]]],
            ['taller_id' => $taller->id, 'detalles' => [['product_id' => $productoM->id, 'cantidad' => 40]]],
        ],
    ];

    expect(fn () => app(OrdenConfeccionService::class)->distribuir($payload))
        ->toThrow(ValidationException::class);

    expect(OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->count())->toBe(0);

    $disponibilidad = app(DisponibilidadConfeccionService::class);
    $ordenCorteFresca = $ordenCorte->fresh();
    expect($disponibilidad->disponiblePara($ordenCorteFresca, $productoL->id))->toBe(60)
        ->and($disponibilidad->disponiblePara($ordenCorteFresca, $productoM->id))->toBe(40);
});

test('taller_id duplicado entre asignaciones (endpoint HTTP) es rechazado por el Request antes de llegar al Service', function () {
    $admin = dist_crearAdmin('Admin TallerDuplicado HTTP QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $productoM = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 60, 'cantidad_defectuosa' => 0],
        ['product' => $productoM, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    $response = $this->actingAs($admin, 'admin')->post(
        route('admin.corte_confeccion.ordenes_confeccion.distribuir'),
        [
            'orden_corte_id' => $ordenCorte->id,
            'asignaciones' => [
                ['taller_id' => $taller->id, 'detalles' => [['product_id' => $productoL->id, 'cantidad' => 60]]],
                ['taller_id' => $taller->id, 'detalles' => [['product_id' => $productoM->id, 'cantidad' => 40]]],
            ],
        ]
    );

    $response->assertSessionHasErrors();

    expect(OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Bloque 8: endpoint HTTP (controller/ruta) dedicado a distribuir().
|--------------------------------------------------------------------------
*/

test('el endpoint HTTP admin.corte_confeccion.ordenes_confeccion.distribuir crea N ordenes y redirige a la orden de corte', function () {
    $admin = dist_crearAdmin('Admin Endpoint Dist QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $tallerA = dist_crearTaller();
    $tallerB = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
    ]);

    $response = $this->actingAs($admin, 'admin')->post(
        route('admin.corte_confeccion.ordenes_confeccion.distribuir'),
        [
            'orden_corte_id' => $ordenCorte->id,
            'asignaciones' => [
                ['taller_id' => $tallerA->id, 'detalles' => [['product_id' => $productoL->id, 'cantidad' => 60]]],
                ['taller_id' => $tallerB->id, 'detalles' => [['product_id' => $productoL->id, 'cantidad' => 20]]],
            ],
        ]
    );

    $response->assertRedirect(route('admin.corte_confeccion.ordenes_corte.show', $ordenCorte->id));

    expect(OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->count())->toBe(2);
});

test('el endpoint HTTP admin.corte_confeccion.ordenes_confeccion.distribuir filtra filas de cantidad 0 antes de validar', function () {
    $admin = dist_crearAdmin('Admin Endpoint Filtro QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
    ]);

    $response = $this->actingAs($admin, 'admin')->post(
        route('admin.corte_confeccion.ordenes_confeccion.distribuir'),
        [
            'orden_corte_id' => $ordenCorte->id,
            'asignaciones' => [
                ['taller_id' => $taller->id, 'detalles' => [
                    ['product_id' => $productoL->id, 'cantidad' => 60],
                    ['product_id' => $productoL->id, 'cantidad' => 0],
                ]],
            ],
        ]
    );

    $response->assertRedirect(route('admin.corte_confeccion.ordenes_corte.show', $ordenCorte->id));

    $detalles = OrdenConfeccionDetalle::query()
        ->where('product_id', $productoL->id)
        ->get();

    expect($detalles)->toHaveCount(1)
        ->and((int) $detalles->first()->cantidad_entregada)->toBe(60);
});

test('el endpoint HTTP no descarta asignaciones con cantidad positiva sin taller y rechaza toda la distribucion', function () {
    $admin = dist_crearAdmin('Admin Endpoint Sin Taller QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $productoM = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 60, 'cantidad_defectuosa' => 0],
        ['product' => $productoM, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    $response = $this->actingAs($admin, 'admin')->post(
        route('admin.corte_confeccion.ordenes_confeccion.distribuir'),
        [
            'orden_corte_id' => $ordenCorte->id,
            'asignaciones' => [
                ['taller_id' => $taller->id, 'detalles' => [['product_id' => $productoL->id, 'cantidad' => 60]]],
                ['detalles' => [['product_id' => $productoM->id, 'cantidad' => 40]]],
            ],
        ]
    );

    $response->assertSessionHasErrors('asignaciones.1.taller_id');

    expect(OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->count())->toBe(0);
});

test('el endpoint HTTP no descarta detalles con cantidad positiva sin producto y rechaza toda la distribucion', function () {
    $admin = dist_crearAdmin('Admin Endpoint Sin Producto QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $productoM = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 60, 'cantidad_defectuosa' => 0],
        ['product' => $productoM, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    $response = $this->actingAs($admin, 'admin')->post(
        route('admin.corte_confeccion.ordenes_confeccion.distribuir'),
        [
            'orden_corte_id' => $ordenCorte->id,
            'asignaciones' => [
                ['taller_id' => $taller->id, 'detalles' => [
                    ['product_id' => $productoL->id, 'cantidad' => 60],
                    ['cantidad' => 40],
                ]],
            ],
        ]
    );

    $response->assertSessionHasErrors('asignaciones.0.detalles.1.product_id');

    expect(OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| CASO 6 - concurrencia.
|
| Limitacion honesta del entorno de test (ver docs/confeccion/03A_MOTOR_DISTRIBUCION.md
| "Resultado de implementacion"): CorteConfeccionTestCase usa
| DatabaseTransactions, es decir, TODO el test corre dentro de UNA sola
| transaccion externa sin commit real, sobre UNA sola conexion. Esto hace
| posible probar la parte FUNCIONAL (A) -- que una segunda operacion
| recalcula la disponibilidad despues de que la primera "confirma" (su
| propio DB::transaction() interno queda como savepoint liberado dentro de
| la transaccion externa) -- pero NO permite abrir una segunda conexion/
| transaccion real independiente que demuestre el bloqueo efectivo de
| lockForUpdate() entre procesos concurrentes (parte B). Este test cubre
| unicamente la parte A y lo deja documentado explicitamente: no se afirma
| aqui que el lock en si fue probado bajo concurrencia real.
|--------------------------------------------------------------------------
*/

test('CASO 6 (parcial, solo parte funcional A) - una segunda distribucion ve la disponibilidad ya actualizada por la primera', function () {
    $admin = dist_crearAdmin('Admin Concurrencia QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $tallerA = dist_crearTaller();
    $tallerB = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
    ]);

    // "Proceso A": distribuye 70, se ejecuta y termina primero.
    app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $tallerA->id, 'detalles' => [['product_id' => $productoL->id, 'cantidad' => 70]]],
        ],
    ]);

    // "Proceso B": se ejecuta despues (secuencial, no concurrente real) e
    // intenta distribuir 60 mas -- nunca deben quedar 130 repartidas.
    $payloadB = [
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $tallerB->id, 'detalles' => [['product_id' => $productoL->id, 'cantidad' => 60]]],
        ],
    ];

    expect(fn () => app(OrdenConfeccionService::class)->distribuir($payloadB))
        ->toThrow(ValidationException::class);

    $totalDistribuido = OrdenConfeccionDetalle::query()
        ->where('product_id', $productoL->id)
        ->sum('cantidad_entregada');

    expect((int) $totalDistribuido)->toBe(70);
});

/*
|--------------------------------------------------------------------------
| Disponibilidad real OrdenCorte -> distribucion.
|--------------------------------------------------------------------------
*/

test('disponibilidad A - una orden recibida sin distribucion tiene todo el apto pendiente y permite distribuir', function () {
    $admin = dist_crearAdmin('Admin Disponibilidad Nada QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
    ]);

    $resumen = app(DisponibilidadConfeccionService::class)->resumenPorOrdenCorte($ordenCorte);

    expect($resumen['total_apto_confeccion'])->toBe(100)
        ->and($resumen['total_distribuido'])->toBe(0)
        ->and($resumen['total_pendiente'])->toBe(100)
        ->and($resumen['puede_distribuir'])->toBeTrue();

    $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.create', $ordenCorte->id))
        ->assertOk();
});

test('disponibilidad B y D - una distribucion parcial deja solo el pendiente real y rechaza excederlo por una unidad', function () {
    $admin = dist_crearAdmin('Admin Disponibilidad Parcial QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $tallerA = dist_crearTaller();
    $tallerB = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
    ]);

    app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $tallerA->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 60]]],
        ],
    ]);

    $ordenCorte = $ordenCorte->fresh();
    $resumen = app(DisponibilidadConfeccionService::class)->resumenPorOrdenCorte($ordenCorte);

    expect($resumen['total_apto_confeccion'])->toBe(100)
        ->and($resumen['total_distribuido'])->toBe(60)
        ->and($resumen['total_pendiente'])->toBe(40)
        ->and($resumen['puede_distribuir'])->toBeTrue();

    $response = $this->actingAs($admin, 'admin')->get(
        route('admin.corte_confeccion.ordenes_confeccion.create', $ordenCorte->id)
    );

    $response->assertOk();
    $fila = $response->viewData('filas')->firstWhere('product_id', $producto->id);

    expect($fila['disponible_confeccion'])->toBe(40);

    expect(fn () => app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $tallerB->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 41]]],
        ],
    ]))->toThrow(ValidationException::class);

    expect(OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->count())->toBe(1);
});

test('disponibilidad C - una orden totalmente distribuida oculta la accion visual y bloquea create/contexto/distribuir', function () {
    $admin = dist_crearAdmin('Admin Disponibilidad Completa QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $tallerA = dist_crearTaller();
    $tallerB = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
    ]);

    app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $tallerA->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 100]]],
        ],
    ]);

    $ordenCorte = $ordenCorte->fresh();
    $resumen = app(DisponibilidadConfeccionService::class)->resumenPorOrdenCorte($ordenCorte);

    expect($resumen['total_apto_confeccion'])->toBe(100)
        ->and($resumen['total_distribuido'])->toBe(100)
        ->and($resumen['total_pendiente'])->toBe(0)
        ->and($resumen['puede_distribuir'])->toBeFalse();

    $show = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.ordenes_corte.show', $ordenCorte->id));

    $show->assertOk()
        ->assertSee('Distribucion completada', false)
        ->assertSee('100 de 100 unidades distribuidas', false)
        ->assertDontSee('Distribuir a talleres', false);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.create', $ordenCorte->id))
        ->assertRedirect(route('admin.corte_confeccion.ordenes_corte.show', $ordenCorte->id))
        ->assertSessionHas('warning');

    $this->actingAs($admin, 'admin')
        ->getJson(route('admin.corte_confeccion.ordenes_confeccion.contexto', $ordenCorte->id))
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors']);

    expect(fn () => app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $tallerB->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 1]]],
        ],
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(OrdenConfeccionService::class)->create([
        'orden_corte_id' => $ordenCorte->id,
        'taller_id' => $tallerB->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $producto->id, 'talla' => '', 'cantidad_entregada' => 1],
        ],
    ]))->toThrow(ValidationException::class);

    expect(OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->count())->toBe(1);
});

test('disponibilidad E - dos talleres consumen todo el pendiente y no se permite una tercera distribucion', function () {
    $admin = dist_crearAdmin('Admin Dos Talleres Completo QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $tallerA = dist_crearTaller();
    $tallerB = dist_crearTaller();
    $tallerC = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
    ]);

    app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $tallerA->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 60]]],
            ['taller_id' => $tallerB->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 40]]],
        ],
    ]);

    $resumen = app(DisponibilidadConfeccionService::class)->resumenPorOrdenCorte($ordenCorte->fresh());

    expect($resumen['total_pendiente'])->toBe(0)
        ->and($resumen['puede_distribuir'])->toBeFalse()
        ->and(OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->count())->toBe(2);

    expect(fn () => app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $tallerC->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 1]]],
        ],
    ]))->toThrow(ValidationException::class);

    expect(OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->count())->toBe(2);
});

test('disponibilidad F - por variante solo queda distribuible la talla con pendiente real', function () {
    $admin = dist_crearAdmin('Admin Variante Pendiente QA');
    Auth::guard('admin')->setUser($admin);

    $productoM = Product::factory()->simple()->create();
    $productoL = Product::factory()->simple()->create();
    $tallerA = dist_crearTaller();
    $tallerB = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoM, 'talla' => 'M', 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
        ['product' => $productoL, 'talla' => 'L', 'cantidad_recibida' => 80, 'cantidad_defectuosa' => 0],
    ]);

    app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $tallerA->id, 'detalles' => [
                ['product_id' => $productoM->id, 'cantidad' => 100],
                ['product_id' => $productoL->id, 'cantidad' => 40],
            ]],
        ],
    ]);

    $response = $this->actingAs($admin, 'admin')->get(
        route('admin.corte_confeccion.ordenes_confeccion.create', $ordenCorte->fresh()->id)
    );

    $response->assertOk();

    $filas = $response->viewData('filas')->keyBy('product_id');

    expect($filas[$productoM->id]['disponible_confeccion'])->toBe(0)
        ->and($filas[$productoL->id]['disponible_confeccion'])->toBe(40)
        ->and(app(DisponibilidadConfeccionService::class)->resumenPorOrdenCorte($ordenCorte->fresh())['total_pendiente'])->toBe(40);

    expect(fn () => app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $tallerB->id, 'detalles' => [['product_id' => $productoM->id, 'cantidad' => 1]]],
        ],
    ]))->toThrow(ValidationException::class);

    $ordenesL = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $tallerB->id, 'detalles' => [['product_id' => $productoL->id, 'cantidad' => 40]]],
        ],
    ]);

    expect($ordenesL)->toHaveCount(1)
        ->and(app(DisponibilidadConfeccionService::class)->resumenPorOrdenCorte($ordenCorte->fresh())['total_pendiente'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Fase 03C3 - contrato JSON de distribuir(), endpoint de contexto, y
| verificacion de base de datos para el escenario multi-taller real usado
| por la pantalla REVISAR/submit.
|--------------------------------------------------------------------------
*/

test('Fase 03C3 - la vista GET create de distribucion renderiza sin errores de Blade', function () {
    $admin = dist_crearAdmin('Admin Vista Create 03C3 QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
    ]);

    $response = $this->actingAs($admin, 'admin')->get(
        route('admin.corte_confeccion.ordenes_confeccion.create', $ordenCorte->id)
    );

    $response->assertOk();
});

test('Fase 03C3 - una request sin Accept JSON conserva el comportamiento HTML original (redirect + flash) tras el cambio', function () {
    $admin = dist_crearAdmin('Admin HTML Compat 03C3 QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
    ]);

    $response = $this->actingAs($admin, 'admin')->post(
        route('admin.corte_confeccion.ordenes_confeccion.distribuir'),
        [
            'orden_corte_id' => $ordenCorte->id,
            'asignaciones' => [
                ['taller_id' => $taller->id, 'detalles' => [['product_id' => $productoL->id, 'cantidad' => 60]]],
            ],
        ]
    );

    $response->assertRedirect(route('admin.corte_confeccion.ordenes_corte.show', $ordenCorte->id));
    $response->assertSessionHas('success');
});

test('Fase 03C3 - el endpoint distribuir responde JSON estable (success/message/ordenes/redirect_url) cuando el request pide JSON, con evidencia real en BD', function () {
    $admin = dist_crearAdmin('Admin JSON Exito 03C3 QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $productoM = Product::factory()->simple()->create();
    $tallerA = dist_crearTaller();
    $tallerB = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
        ['product' => $productoM, 'cantidad_recibida' => 80, 'cantidad_defectuosa' => 0],
    ]);

    $fechaEntregaA = now()->addDays(3)->toDateString();
    $fechaEntregaB = now()->addDays(5)->toDateString();

    // Escenario multi-taller del encargo: L 60 Taller1 + L 20 Taller4, M 40 Taller4 -> 2 OrdenConfeccion.
    $response = $this->actingAs($admin, 'admin')->postJson(
        route('admin.corte_confeccion.ordenes_confeccion.distribuir'),
        [
            'orden_corte_id' => $ordenCorte->id,
            'asignaciones' => [
                [
                    'taller_id' => $tallerA->id,
                    'fecha_entrega' => $fechaEntregaA,
                    'observacion' => 'Nota general QA',
                    'detalles' => [
                        ['product_id' => $productoL->id, 'cantidad' => 60, 'prioridad' => 'alta'],
                    ],
                ],
                [
                    'taller_id' => $tallerB->id,
                    'fecha_entrega' => $fechaEntregaB,
                    'observacion' => 'Nota general QA',
                    'detalles' => [
                        ['product_id' => $productoL->id, 'cantidad' => 20, 'prioridad' => 'urgente'],
                        ['product_id' => $productoM->id, 'cantidad' => 40],
                    ],
                ],
            ],
        ]
    );

    $response->assertOk()
        ->assertJson(['success' => true])
        ->assertJsonStructure(['success', 'message', 'ordenes' => [['id', 'codigo']], 'redirect_url']);

    expect($response->json('ordenes'))->toHaveCount(2)
        ->and($response->json('redirect_url'))->toBe(route('admin.corte_confeccion.ordenes_corte.show', $ordenCorte->id));

    // Evidencia real en BD (item 22 del encargo): no basta con que la UI/HTTP diga "success".
    expect(OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->count())->toBe(2);

    $ordenA = OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->where('taller_id', $tallerA->id)->with('detalles')->firstOrFail();
    $ordenB = OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->where('taller_id', $tallerB->id)->with('detalles')->firstOrFail();

    expect($ordenA->fecha_entrega->toDateString())->toBe($fechaEntregaA)
        ->and($ordenA->observacion)->toBe('Nota general QA')
        ->and($ordenA->detalles)->toHaveCount(1)
        ->and($ordenA->detalles->first()->product_id)->toBe($productoL->id)
        ->and((int) $ordenA->detalles->first()->cantidad_entregada)->toBe(60)
        ->and($ordenA->detalles->first()->prioridad)->toBe('alta');

    expect($ordenB->fecha_entrega->toDateString())->toBe($fechaEntregaB)
        ->and($ordenB->observacion)->toBe('Nota general QA')
        ->and($ordenB->detalles)->toHaveCount(2);

    $detallesB = $ordenB->detalles->keyBy('product_id');
    expect((int) $detallesB[$productoL->id]->cantidad_entregada)->toBe(20)
        ->and($detallesB[$productoL->id]->prioridad)->toBe('urgente')
        ->and((int) $detallesB[$productoM->id]->cantidad_entregada)->toBe(40)
        ->and($detallesB[$productoM->id]->prioridad)->toBe('normal');

    $disponibilidad = app(DisponibilidadConfeccionService::class);
    $ordenCorteFresca = $ordenCorte->fresh();
    expect($disponibilidad->disponiblePara($ordenCorteFresca, $productoL->id))->toBe(20)
        ->and($disponibilidad->disponiblePara($ordenCorteFresca, $productoM->id))->toBe(40);
});

test('Fase 03C3 - el endpoint distribuir responde 422 en formato JSON estandar de Laravel y no crea ninguna orden', function () {
    $admin = dist_crearAdmin('Admin JSON 422 03C3 QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $tallerActivo = dist_crearTaller();
    $tallerInactivo = dist_crearTaller(['estado' => 'inactivo']);

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
    ]);

    $response = $this->actingAs($admin, 'admin')->postJson(
        route('admin.corte_confeccion.ordenes_confeccion.distribuir'),
        [
            'orden_corte_id' => $ordenCorte->id,
            'asignaciones' => [
                ['taller_id' => $tallerActivo->id, 'detalles' => [['product_id' => $productoL->id, 'cantidad' => 60]]],
                ['taller_id' => $tallerInactivo->id, 'detalles' => [['product_id' => $productoL->id, 'cantidad' => 20]]],
            ],
        ]
    );

    $response->assertStatus(422)
        ->assertJsonStructure(['message', 'errors']);

    // El motor 03A es transaccional: un 422 nunca deja ordenes parcialmente creadas.
    expect(OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->count())->toBe(0);
});

test('Fase 03C3 - el endpoint de contexto devuelve la disponibilidad real por product_id despues de una distribucion', function () {
    $admin = dist_crearAdmin('Admin Contexto Disp 03C3 QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
    ]);

    app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $taller->id, 'detalles' => [['product_id' => $productoL->id, 'cantidad' => 60]]],
        ],
    ]);

    $response = $this->actingAs($admin, 'admin')->getJson(
        route('admin.corte_confeccion.ordenes_confeccion.contexto', $ordenCorte->fresh()->id)
    );

    $response->assertOk()->assertJsonStructure(['filas', 'talleres']);

    $fila = collect($response->json('filas'))->firstWhere('product_id', $productoL->id);

    expect($fila)->not->toBeNull()
        ->and($fila['disponible_confeccion'])->toBe(40)
        ->and($fila['ya_distribuido'])->toBe(60);
});

test('Fase 03C3 - el endpoint de contexto solo devuelve talleres activos', function () {
    $admin = dist_crearAdmin('Admin Contexto Talleres 03C3 QA');
    Auth::guard('admin')->setUser($admin);

    $productoL = Product::factory()->simple()->create();
    $tallerActivo = dist_crearTaller();
    $tallerInactivo = dist_crearTaller(['estado' => 'inactivo']);

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0],
    ]);

    $response = $this->actingAs($admin, 'admin')->getJson(
        route('admin.corte_confeccion.ordenes_confeccion.contexto', $ordenCorte->id)
    );

    $response->assertOk();

    $idsTalleres = collect($response->json('talleres'))->pluck('id')->all();

    expect($idsTalleres)->toContain($tallerActivo->id)
        ->and($idsTalleres)->not->toContain($tallerInactivo->id);
});

test('Fase 03C3 - el endpoint de contexto exige el permiso de crear ordenes de confeccion (ACL)', function () {
    $productoL = Product::factory()->simple()->create();
    dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoL, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0],
    ]);

    $roleSinPermiso = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']]);
    $adminSinPermiso = Admin::factory()->create(['role_id' => $roleSinPermiso->id]);

    $response = $this->actingAs($adminSinPermiso, 'admin')->getJson(
        route('admin.corte_confeccion.ordenes_confeccion.contexto', $ordenCorte->id)
    );

    // Bagisto aborta con 401 (Webkul\User\Http\Middleware\Bouncer::checkIfAuthorized),
    // no 403, cuando el rol no tiene el permiso mapeado en acl.php.
    $response->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| Retorno de Taller y Recepcion de Bodega.
|--------------------------------------------------------------------------
*/

test('RET-01 - taller reporta entregas parciales historicas y bodega recibe cada retorno', function () {
    $admin = dist_crearAdmin('Admin Retornos Historicos QA');
    Auth::guard('admin')->setUser($admin);

    $productoM = Product::factory()->simple()->create();
    $productoL = Product::factory()->simple()->create();
    $productoXL = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoM, 'talla' => 'M', 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
        ['product' => $productoL, 'talla' => 'L', 'cantidad_recibida' => 80, 'cantidad_defectuosa' => 0],
        ['product' => $productoXL, 'talla' => 'XL', 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            [
                'taller_id' => $taller->id,
                'detalles' => [
                    ['product_id' => $productoM->id, 'cantidad' => 100],
                    ['product_id' => $productoL->id, 'cantidad' => 80],
                    ['product_id' => $productoXL->id, 'cantidad' => 50],
                ],
            ],
        ],
    ])->first();

    $detalles = $orden->fresh()->detalles->keyBy('product_id');
    $service = app(OrdenConfeccionService::class);

    $retorno1 = $service->reportarTaller($orden, [
        'fecha_reporte_taller' => '2026-09-13',
        'detalles' => [
            ['id' => $detalles[$productoM->id]->id, 'cantidad' => 70],
        ],
    ]);

    expect($retorno1->estado)->toBe(EstadoOrdenConfeccionRetorno::PendienteBodega->value)
        ->and($retorno1->numero)->toBe(1)
        ->and($retorno1->detalles->sum('cantidad_reportada'))->toBe(70)
        ->and($orden->fresh()->estado)->toBe(EstadoOrdenConfeccion::EnConfeccion->value);

    $retorno1Recibido = $service->recibirBodega($retorno1, [
        'fecha_recepcion_bodega' => '2026-09-14',
        'detalles' => [[
            'id' => $retorno1->detalles->first()->id,
            'cantidad_recibida' => 68,
            'cantidad_defectuosa' => 1,
            'cantidad_faltante' => 1,
            'observacion_bodega' => 'Una prenda defectuosa y una faltante en conteo fisico.',
        ]],
    ]);

    expect($retorno1Recibido->estado)->toBe(EstadoOrdenConfeccionRetorno::RecibidaBodega->value)
        ->and($retorno1Recibido->detalles->first()->cantidad_reportada)->toBe(70)
        ->and($retorno1Recibido->detalles->first()->cantidad_recibida)->toBe(68)
        ->and($retorno1Recibido->detalles->first()->cantidad_defectuosa)->toBe(1)
        ->and($retorno1Recibido->detalles->first()->cantidad_faltante)->toBe(1);

    $retorno2 = $service->reportarTaller($orden->fresh(), [
        'detalles' => [
            ['id' => $detalles[$productoM->id]->id, 'cantidad' => 30],
            ['id' => $detalles[$productoL->id]->id, 'cantidad' => 40],
        ],
    ]);

    expect($retorno2->numero)->toBe(2)
        ->and($retorno2->detalles->sum('cantidad_reportada'))->toBe(70)
        ->and($orden->fresh()->estado)->toBe(EstadoOrdenConfeccion::EnConfeccion->value);

    $retorno3 = $service->reportarTaller($orden->fresh(), [
        'detalles' => [
            ['id' => $detalles[$productoL->id]->id, 'cantidad' => 40],
            ['id' => $detalles[$productoXL->id]->id, 'cantidad' => 50],
        ],
    ]);

    $orden = $orden->fresh(['detalles.retornosDetalles', 'retornos.detalles']);
    $reportadasPorProducto = $orden->detalles->mapWithKeys(fn ($detalle) => [
        $detalle->product_id => $detalle->retornosDetalles->sum('cantidad_reportada'),
    ]);

    expect($retorno3->numero)->toBe(3)
        ->and($orden->estado)->toBe(EstadoOrdenConfeccion::TerminadaTaller->value)
        ->and($orden->retornos)->toHaveCount(3)
        ->and($orden->retornos->flatMap(fn ($retorno) => $retorno->detalles)->sum('cantidad_reportada'))->toBe(230)
        ->and($reportadasPorProducto[$productoM->id])->toBe(100)
        ->and($reportadasPorProducto[$productoL->id])->toBe(80)
        ->and($reportadasPorProducto[$productoXL->id])->toBe(50);

    foreach ($orden->retornos->where('estado', EstadoOrdenConfeccionRetorno::PendienteBodega->value) as $retorno) {
        $service->recibirBodega($retorno, [
            'fecha_recepcion_bodega' => '2026-09-14',
            'detalles' => $retorno->detalles->map(fn ($detalleRetorno) => [
                'id' => $detalleRetorno->id,
                'cantidad_recibida' => $detalleRetorno->cantidad_reportada,
                'cantidad_defectuosa' => 0,
                'cantidad_faltante' => 0,
            ])->values()->all(),
        ]);
    }

    $orden = $orden->fresh(['retornos.detalles']);

    expect($orden->estado)->toBe(EstadoOrdenConfeccion::Cerrada->value)
        ->and($orden->retornos->where('estado', EstadoOrdenConfeccionRetorno::RecibidaBodega->value))->toHaveCount(3)
        ->and($orden->retornos->flatMap(fn ($retorno) => $retorno->detalles)->sum('cantidad_recibida'))->toBe(228)
        ->and($orden->retornos->flatMap(fn ($retorno) => $retorno->detalles)->sum('cantidad_defectuosa'))->toBe(1)
        ->and($orden->retornos->flatMap(fn ($retorno) => $retorno->detalles)->sum('cantidad_faltante'))->toBe(1)
        ->and($orden->retornos->flatMap(fn ($retorno) => $retorno->detalles)->sum(fn ($detalleRetorno) => $detalleRetorno->cantidad_recibida + $detalleRetorno->cantidad_defectuosa + $detalleRetorno->cantidad_faltante))->toBe(230);
});

test('RET-02 - un retorno parcial no puede superar el pendiente historico de la variante', function () {
    $admin = dist_crearAdmin('Admin Retorno Pendiente QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 40]]],
        ],
    ])->first();

    $detalle = $orden->detalles->first();
    $service = app(OrdenConfeccionService::class);

    $service->reportarTaller($orden, [
        'detalles' => [[
            'id' => $detalle->id,
            'cantidad' => 10,
        ]],
    ]);

    expect(fn () => $service->reportarTaller($orden->fresh(), [
        'detalles' => [[
            'id' => $detalle->id,
            'cantidad' => 31,
        ]],
    ]))->toThrow(ValidationException::class);

    expect(fn () => $service->reportarTaller($orden->fresh(), [
        'detalles' => [[
            'id' => $detalle->id,
            'cantidad' => -1,
        ]],
    ]))->toThrow(ValidationException::class);

    $orden = $orden->fresh(['detalles.retornosDetalles']);

    expect($orden->detalles->first()->retornosDetalles->sum('cantidad_reportada'))->toBe(10);
});

test('RET-03 - recepcion 70 con 68 buenas 1 defectuosa y 1 faltante entra a inventario y deja movimiento trazable', function () {
    $admin = dist_crearAdmin('Admin RET03 Inventario QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $source = dist_sembrarStockBagisto($producto, 15);
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 70, 'cantidad_defectuosa' => 0],
    ]);

    Auth::guard('admin')->setUser($admin);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 70]]],
        ],
    ])->first();

    $retorno = app(OrdenConfeccionService::class)->reportarTaller($orden, [
        'detalles' => [[
            'id' => $orden->detalles->first()->id,
            'cantidad' => 70,
        ]],
    ]);

    $retornoRecibido = app(OrdenConfeccionService::class)->recibirBodega($retorno, [
        'fecha_recepcion_bodega' => '2026-09-20',
        'detalles' => [[
            'id' => $retorno->detalles->first()->id,
            'cantidad_recibida' => 68,
            'cantidad_defectuosa' => 1,
            'cantidad_faltante' => 1,
            'observacion_bodega' => 'Una defectuosa y una faltante.',
        ]],
    ]);

    $movimiento = InventarioMovimiento::query()
        ->where('retorno_detalle_id', $retorno->detalles->first()->id)
        ->firstOrFail();

    expect($producto->type)->toBe('simple')
        ->and(dist_stockFisico($producto, $source->id))->toBe(83)
        ->and(dist_stockIndexado($producto))->toBe(83)
        ->and($retornoRecibido->estado)->toBe(EstadoOrdenConfeccionRetorno::RecibidaBodega->value)
        ->and($retornoRecibido->detalles->first()->cantidad_reportada)->toBe(70)
        ->and($retornoRecibido->detalles->first()->cantidad_recibida)->toBe(68)
        ->and($retornoRecibido->detalles->first()->cantidad_defectuosa)->toBe(1)
        ->and($retornoRecibido->detalles->first()->cantidad_faltante)->toBe(1)
        ->and($orden->fresh()->estado)->toBe(EstadoOrdenConfeccion::Cerrada->value)
        ->and($movimiento->product_id)->toBe($producto->id)
        ->and($movimiento->inventory_source_id)->toBe($source->id)
        ->and($movimiento->tipo_movimiento)->toBe(TipoInventarioMovimiento::EntradaConfeccion->value)
        ->and($movimiento->cantidad_anterior)->toBe(15)
        ->and($movimiento->cantidad_movimiento)->toBe(68)
        ->and($movimiento->cantidad_nueva)->toBe(83)
        ->and($movimiento->orden_confeccion_id)->toBe($orden->id)
        ->and($movimiento->retorno_id)->toBe($retorno->id)
        ->and($movimiento->retorno_detalle_id)->toBe($retorno->detalles->first()->id)
        ->and($movimiento->admin_id)->toBe($admin->id)
        ->and($movimiento->nombre_usuario_snapshot)->toBe($admin->name)
        ->and($movimiento->created_at)->not->toBeNull();

    expect(fn () => app(OrdenConfeccionService::class)->recibirBodega($retorno->fresh(), [
        'detalles' => [[
            'id' => $retorno->detalles->first()->id,
            'cantidad_recibida' => 68,
            'cantidad_defectuosa' => 1,
            'cantidad_faltante' => 1,
            'observacion_bodega' => 'Intento repetido.',
        ]],
    ]))->toThrow(ValidationException::class);

    expect(dist_stockFisico($producto, $source->id))->toBe(83)
        ->and(InventarioMovimiento::query()->where('retorno_detalle_id', $retorno->detalles->first()->id)->count())->toBe(1);
});

test('RET-03 - cero buenas no incrementa inventario ni crea movimiento', function () {
    $admin = dist_crearAdmin('Admin RET03 Cero Buenas QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $source = dist_sembrarStockBagisto($producto, 15);
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0],
    ]);

    Auth::guard('admin')->setUser($admin);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 10]]],
        ],
    ])->first();

    $retorno = app(OrdenConfeccionService::class)->reportarTaller($orden, [
        'detalles' => [[
            'id' => $orden->detalles->first()->id,
            'cantidad' => 10,
        ]],
    ]);

    app(OrdenConfeccionService::class)->recibirBodega($retorno, [
        'detalles' => [[
            'id' => $retorno->detalles->first()->id,
            'cantidad_recibida' => 0,
            'cantidad_defectuosa' => 5,
            'cantidad_faltante' => 5,
            'observacion_bodega' => 'Todo llego defectuoso o faltante.',
        ]],
    ]);

    expect(dist_stockFisico($producto, $source->id))->toBe(15)
        ->and(dist_stockIndexado($producto))->toBe(15)
        ->and(InventarioMovimiento::query()->where('retorno_detalle_id', $retorno->detalles->first()->id)->count())->toBe(0)
        ->and($orden->fresh()->estado)->toBe(EstadoOrdenConfeccion::Cerrada->value);
});

test('RET-03 - dos retornos de la misma variante acumulan stock y conservan historial', function () {
    $admin = dist_crearAdmin('Admin RET03 Dos Retornos QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $source = dist_sembrarStockBagisto($producto, 15);
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 90, 'cantidad_defectuosa' => 0],
    ]);

    Auth::guard('admin')->setUser($admin);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 90]]],
        ],
    ])->first();

    $detalleOrdenId = $orden->detalles->first()->id;

    $retorno1 = app(OrdenConfeccionService::class)->reportarTaller($orden, [
        'detalles' => [['id' => $detalleOrdenId, 'cantidad' => 70]],
    ]);

    app(OrdenConfeccionService::class)->recibirBodega($retorno1, [
        'detalles' => [[
            'id' => $retorno1->detalles->first()->id,
            'cantidad_recibida' => 68,
            'cantidad_defectuosa' => 1,
            'cantidad_faltante' => 1,
            'observacion_bodega' => 'Control fisico retorno 1.',
        ]],
    ]);

    $retorno2 = app(OrdenConfeccionService::class)->reportarTaller($orden->fresh(), [
        'detalles' => [['id' => $detalleOrdenId, 'cantidad' => 20]],
    ]);

    app(OrdenConfeccionService::class)->recibirBodega($retorno2, [
        'detalles' => [[
            'id' => $retorno2->detalles->first()->id,
            'cantidad_recibida' => 20,
            'cantidad_defectuosa' => 0,
            'cantidad_faltante' => 0,
        ]],
    ]);

    $movimientos = InventarioMovimiento::query()
        ->where('product_id', $producto->id)
        ->where('inventory_source_id', $source->id)
        ->orderBy('id')
        ->get();

    expect(dist_stockFisico($producto, $source->id))->toBe(103)
        ->and(dist_stockIndexado($producto))->toBe(103)
        ->and($movimientos)->toHaveCount(2)
        ->and($movimientos[0]->cantidad_anterior)->toBe(15)
        ->and($movimientos[0]->cantidad_movimiento)->toBe(68)
        ->and($movimientos[0]->cantidad_nueva)->toBe(83)
        ->and($movimientos[1]->cantidad_anterior)->toBe(83)
        ->and($movimientos[1]->cantidad_movimiento)->toBe(20)
        ->and($movimientos[1]->cantidad_nueva)->toBe(103)
        ->and($movimientos[0]->retorno_id)->toBe($retorno1->id)
        ->and($movimientos[1]->retorno_id)->toBe($retorno2->id)
        ->and($orden->fresh()->estado)->toBe(EstadoOrdenConfeccion::Cerrada->value);
});

test('RET-03 - recepcion fallida hace rollback de detalle retorno stock y movimiento', function () {
    $admin = dist_crearAdmin('Admin RET03 Rollback QA');
    Auth::guard('admin')->setUser($admin);

    $productoM = Product::factory()->simple()->create();
    $productoL = Product::factory()->simple()->create();
    $source = dist_sembrarStockBagisto($productoM, 15);
    dist_sembrarStockBagisto($productoL, 7);
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoM, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0],
        ['product' => $productoL, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0],
    ]);

    Auth::guard('admin')->setUser($admin);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [[
            'taller_id' => $taller->id,
            'detalles' => [
                ['product_id' => $productoM->id, 'cantidad' => 10],
                ['product_id' => $productoL->id, 'cantidad' => 10],
            ],
        ]],
    ])->first();

    $retorno = app(OrdenConfeccionService::class)->reportarTaller($orden, [
        'detalles' => $orden->detalles->map(fn ($detalle) => [
            'id' => $detalle->id,
            'cantidad' => 10,
        ])->values()->all(),
    ]);

    $detallesRetorno = $retorno->detalles->values();

    expect(fn () => app(OrdenConfeccionService::class)->recibirBodega($retorno, [
        'detalles' => [
            [
                'id' => $detallesRetorno[0]->id,
                'cantidad_recibida' => 10,
                'cantidad_defectuosa' => 0,
                'cantidad_faltante' => 0,
            ],
            [
                'id' => $detallesRetorno[1]->id,
                'cantidad_recibida' => 9,
                'cantidad_defectuosa' => 0,
                'cantidad_faltante' => 0,
            ],
        ],
    ]))->toThrow(ValidationException::class);

    $retorno = $retorno->fresh(['detalles']);

    expect($retorno->estado)->toBe(EstadoOrdenConfeccionRetorno::PendienteBodega->value)
        ->and($retorno->detalles->first()->cantidad_recibida)->toBeNull()
        ->and(dist_stockFisico($productoM, $source->id))->toBe(15)
        ->and(dist_stockFisico($productoL, $source->id))->toBe(7)
        ->and(InventarioMovimiento::query()->where('retorno_id', $retorno->id)->count())->toBe(0);
});

test('RET-03 - orden cerrada bloquea nuevos reportes recepciones repetidas y no regenera movimiento', function () {
    $admin = dist_crearAdmin('Admin Retorno Doble Recepcion QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $source = dist_sembrarStockBagisto($producto, 15);
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    Auth::guard('admin')->setUser($admin);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 40]]],
        ],
    ])->first();

    $retorno = app(OrdenConfeccionService::class)->reportarTaller($orden, [
        'detalles' => [[
            'id' => $orden->detalles->first()->id,
            'cantidad' => 40,
        ]],
    ]);

    $payloadRecepcion = [
        'detalles' => [[
            'id' => $retorno->detalles->first()->id,
            'cantidad_recibida' => 40,
            'cantidad_defectuosa' => 0,
            'cantidad_faltante' => 0,
        ]],
    ];

    app(OrdenConfeccionService::class)->recibirBodega($retorno, $payloadRecepcion);

    expect($orden->fresh()->estado)->toBe(EstadoOrdenConfeccion::Cerrada->value);

    expect(fn () => app(OrdenConfeccionService::class)->reportarTaller($orden->fresh(), [
        'detalles' => [[
            'id' => $orden->detalles->first()->id,
            'cantidad' => 1,
        ]],
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(OrdenConfeccionService::class)->recibirBodega($retorno->fresh(), $payloadRecepcion))
        ->toThrow(ValidationException::class);

    app(InventarioMovimientoService::class)->registrarEntradaConfeccion($retorno->fresh(['ordenConfeccion', 'detalles']));

    expect(dist_stockFisico($producto, $source->id))->toBe(55)
        ->and(InventarioMovimiento::query()->where('retorno_detalle_id', $retorno->detalles->first()->id)->count())->toBe(1);
});

test('RET-04 - bodega debe explicar defectuosas o faltantes al recibir un retorno', function () {
    $admin = dist_crearAdmin('Admin Retorno Obs Bodega QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 40]]],
        ],
    ])->first();

    $retorno = app(OrdenConfeccionService::class)->reportarTaller($orden, [
        'detalles' => [[
            'id' => $orden->detalles->first()->id,
            'cantidad' => 40,
        ]],
    ]);

    expect(fn () => app(OrdenConfeccionService::class)->recibirBodega($retorno, [
        'detalles' => [[
            'id' => $retorno->detalles->first()->id,
            'cantidad_recibida' => 38,
            'cantidad_defectuosa' => 1,
            'cantidad_faltante' => 1,
            'observacion_bodega' => null,
        ]],
    ]))->toThrow(ValidationException::class);

    $recibido = app(OrdenConfeccionService::class)->recibirBodega($retorno->fresh(), [
        'detalles' => [[
            'id' => $retorno->detalles->first()->id,
            'cantidad_reportada' => 999,
            'cantidad_recibida' => 38,
            'cantidad_defectuosa' => 1,
            'cantidad_faltante' => 1,
            'observacion_bodega' => 'Una prenda con defecto y una no llego fisicamente.',
        ]],
    ]);

    expect($recibido->estado)->toBe(EstadoOrdenConfeccionRetorno::RecibidaBodega->value)
        ->and($recibido->detalles->first()->cantidad_reportada)->toBe(40)
        ->and($recibido->detalles->first()->cantidad_recibida)->toBe(38)
        ->and($recibido->detalles->first()->cantidad_defectuosa)->toBe(1)
        ->and($recibido->detalles->first()->cantidad_faltante)->toBe(1)
        ->and($recibido->detalles->first()->observacion_bodega)->toBe('Una prenda con defecto y una no llego fisicamente.');
});

test('RET-05 - servicio rechaza recepcion incompleta o con cantidades negativas', function () {
    $admin = dist_crearAdmin('Admin Retorno Invariantes QA');
    Auth::guard('admin')->setUser($admin);

    $productoM = Product::factory()->simple()->create();
    $productoL = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoM, 'talla' => 'M', 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0],
        ['product' => $productoL, 'talla' => 'L', 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [[
            'taller_id' => $taller->id,
            'detalles' => [
                ['product_id' => $productoM->id, 'cantidad' => 20],
                ['product_id' => $productoL->id, 'cantidad' => 20],
            ],
        ]],
    ])->first();

    $retorno = app(OrdenConfeccionService::class)->reportarTaller($orden, [
        'detalles' => $orden->detalles->map(fn ($detalle) => [
            'id' => $detalle->id,
            'cantidad' => 10,
        ])->values()->all(),
    ]);

    expect(fn () => app(OrdenConfeccionService::class)->recibirBodega($retorno, [
        'detalles' => [[
            'id' => $retorno->detalles->first()->id,
            'cantidad_recibida' => 10,
            'cantidad_defectuosa' => 0,
            'cantidad_faltante' => 0,
        ]],
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(OrdenConfeccionService::class)->recibirBodega($retorno, [
        'detalles' => $retorno->detalles->map(fn ($detalleRetorno) => [
            'id' => $detalleRetorno->id,
            'cantidad_recibida' => 11,
            'cantidad_defectuosa' => -1,
            'cantidad_faltante' => 0,
        ])->values()->all(),
    ]))->toThrow(ValidationException::class);

    expect($retorno->fresh()->estado)->toBe(EstadoOrdenConfeccionRetorno::PendienteBodega->value);
});

test('RET-06 - taller solo ve y reporta ordenes asignadas a su propio taller', function () {
    $roleTaller = Role::factory()->create([
        'name' => 'TALLER CONFECCION',
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.reportar_taller'],
    ]);
    $usuarioTallerA = Admin::factory()->create(['role_id' => $roleTaller->id]);
    $usuarioTallerB = Admin::factory()->create(['role_id' => $roleTaller->id]);

    Auth::guard('admin')->setUser(dist_crearAdmin('Admin Retorno Seguridad QA'));

    $producto = Product::factory()->simple()->create();
    $tallerA = dist_crearTaller(['usuario_id' => $usuarioTallerA->id]);
    $tallerB = dist_crearTaller(['usuario_id' => $usuarioTallerB->id]);

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    $ordenes = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $tallerA->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 20, 'prioridad' => PrioridadOrdenConfeccion::Urgente->value]]],
            ['taller_id' => $tallerB->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 20, 'prioridad' => PrioridadOrdenConfeccion::Normal->value]]],
        ],
    ]);

    $ordenA = $ordenes->firstWhere('taller_id', $tallerA->id);
    $ordenB = $ordenes->firstWhere('taller_id', $tallerB->id);
    $detalleA = $ordenA->detalles->first();
    $detalleB = $ordenB->detalles->first();

    app(OrdenConfeccionService::class)->reportarTaller($ordenA, [
        'detalles' => [[
            'id' => $detalleA->id,
            'cantidad' => 10,
        ]],
    ]);

    $ordenA = $ordenA->fresh(['detalles.retornosDetalles', 'retornos.detalles']);
    $ordenB = $ordenB->fresh(['detalles.retornosDetalles', 'retornos.detalles']);

    expect($ordenA->detalles->first()->prioridad)->toBe(PrioridadOrdenConfeccion::Urgente->value)
        ->and($ordenB->detalles->first()->prioridad)->toBe(PrioridadOrdenConfeccion::Normal->value)
        ->and($ordenA->retornos)->toHaveCount(1)
        ->and($ordenB->retornos)->toHaveCount(0)
        ->and($ordenA->detalles->first()->retornosDetalles->sum('cantidad_reportada'))->toBe(10)
        ->and($ordenB->detalles->first()->retornosDetalles->sum('cantidad_reportada'))->toBe(0)
        ->and($detalleA->id)->not->toBe($detalleB->id);

    $this->actingAs($usuarioTallerA, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.index'))
        ->assertOk();

    $responseListado = $this->actingAs($usuarioTallerA, 'admin')
        ->withHeader('X-Requested-With', 'XMLHttpRequest')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.index'));

    $responseListado->assertOk();

    expect($responseListado->getContent())
        ->toContain($ordenA->codigo)
        ->not->toContain($ordenB->codigo);

    $this->actingAs($usuarioTallerA, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.show', $ordenA->id))
        ->assertOk();

    $this->actingAs($usuarioTallerA, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.show', $ordenB->id))
        ->assertStatus(403);

    $this->actingAs($usuarioTallerA, 'admin')
        ->post(route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $ordenB->id), [
            'detalles' => [[
                'id' => $ordenB->detalles->first()->id,
                'cantidad' => 1,
            ]],
        ])
        ->assertStatus(403);
});

test('RET-07 - taller no puede ejecutar la recepcion de bodega ni siquiera sobre su propia entrega', function () {
    $roleTaller = Role::factory()->create([
        'name' => 'TALLER CONFECCION',
        'permission_type' => 'custom',
        // Un ACL mal configurado que le marcara recibir_bodega a Taller no debe
        // alcanzar: OrdenConfeccionGate::puedeRecibirBodega() bloquea el rol
        // 'taller confeccion' de forma dura, sin importar el permiso.
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_confeccion.reportar_taller', 'corte_confeccion.ordenes_confeccion.recibir_bodega'],
    ]);
    $usuarioTaller = Admin::factory()->create(['role_id' => $roleTaller->id]);

    Auth::guard('admin')->setUser(dist_crearAdmin('Admin RET07 QA'));

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller(['usuario_id' => $usuarioTaller->id]);

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 40]]],
        ],
    ])->first();

    $retorno = app(OrdenConfeccionService::class)->reportarTaller($orden, [
        'detalles' => [[
            'id' => $orden->detalles->first()->id,
            'cantidad' => 40,
        ]],
    ]);

    $this->actingAs($usuarioTaller, 'admin')
        ->post(route('admin.corte_confeccion.ordenes_confeccion.recibir_bodega', $retorno->id), [
            'detalles' => [[
                'id' => $retorno->detalles->first()->id,
                'cantidad_recibida' => 40,
                'cantidad_defectuosa' => 0,
                'cantidad_faltante' => 0,
            ]],
        ])
        ->assertStatus(403);

    expect($retorno->fresh()->estado)->toBe(EstadoOrdenConfeccionRetorno::PendienteBodega->value);
});

test('RET-08 - bodega no puede registrar una entrega suplantando al taller', function () {
    $roleBodega = Role::factory()->create([
        'name' => 'BODEGA',
        'permission_type' => 'custom',
        // Un ACL mal configurado que le marcara reportar_taller a Bodega no debe
        // alcanzar: OrdenConfeccionGate::puedeReportarTaller() bloquea el rol
        // 'bodega' de forma dura cuando no es el taller propietario.
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_confeccion.recibir_bodega', 'corte_confeccion.ordenes_confeccion.reportar_taller'],
    ]);
    $usuarioBodega = Admin::factory()->create(['role_id' => $roleBodega->id]);

    Auth::guard('admin')->setUser(dist_crearAdmin('Admin RET08 QA'));

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 40]]],
        ],
    ])->first();

    $this->actingAs($usuarioBodega, 'admin')
        ->post(route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $orden->id), [
            'detalles' => [[
                'id' => $orden->detalles->first()->id,
                'cantidad' => 10,
            ]],
        ])
        ->assertStatus(403);

    expect($orden->fresh()->retornos)->toHaveCount(0);
});

test('RET-09 - OrdenConfeccionDataGrid expone retornos_pendientes contando solo retornos sin recibir', function () {
    $admin = dist_crearAdmin('Admin RET09 QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [
            ['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 40]]],
        ],
    ])->first();

    // Primera entrega: se reporta y se recibe -> no debe contar como pendiente.
    $retornoRecibido = app(OrdenConfeccionService::class)->reportarTaller($orden, [
        'detalles' => [[
            'id' => $orden->detalles->first()->id,
            'cantidad' => 20,
        ]],
    ]);

    app(OrdenConfeccionService::class)->recibirBodega($retornoRecibido, [
        'detalles' => [[
            'id' => $retornoRecibido->detalles->first()->id,
            'cantidad_recibida' => 20,
            'cantidad_defectuosa' => 0,
            'cantidad_faltante' => 0,
        ]],
    ]);

    // Segunda entrega: solo se reporta, queda pendiente de bodega.
    app(OrdenConfeccionService::class)->reportarTaller($orden->fresh(), [
        'detalles' => [[
            'id' => $orden->detalles->first()->id,
            'cantidad' => 20,
        ]],
    ]);

    $fila = app(OrdenConfeccionDataGrid::class)->prepareQueryBuilder()
        ->where('ofc.id', $orden->id)
        ->first();

    expect((int) $fila->retornos_pendientes)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Fase 2 - Endurecimiento, casos limite y cierre del flujo (RET-10 en
| adelante). No se reescribe la logica de RET-01..RET-09: estos tests
| demuestran/confirman comportamiento ya existente en el Service, salvo
| donde se indique explicitamente un cambio (ver OrdenConfeccionGate en
| Fase 1 para el unico cambio de comportamiento previo).
|--------------------------------------------------------------------------
*/

test('RET-10 - entrega parcial en dos pasos deja pendientes correctos por variante sin cerrar la orden', function () {
    $admin = dist_crearAdmin('Admin Parcial Dos Pasos QA');
    Auth::guard('admin')->setUser($admin);

    $productoM = Product::factory()->simple()->create();
    $productoL = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoM, 'talla' => 'M', 'cantidad_recibida' => 70, 'cantidad_defectuosa' => 0],
        ['product' => $productoL, 'talla' => 'L', 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [[
            'taller_id' => $taller->id,
            'detalles' => [
                ['product_id' => $productoM->id, 'cantidad' => 70],
                ['product_id' => $productoL->id, 'cantidad' => 40],
            ],
        ]],
    ])->first();

    $detalles = $orden->detalles->keyBy('product_id');
    $service = app(OrdenConfeccionService::class);

    // Primera entrega: M=30, L=0 (L en cero se omite, el service exige
    // cantidad > 0 por detalle enviado).
    $service->reportarTaller($orden, [
        'detalles' => [
            ['id' => $detalles[$productoM->id]->id, 'cantidad' => 30],
        ],
    ]);

    $orden = $orden->fresh(['detalles.retornosDetalles']);
    $pendienteM = (int) $detalles[$productoM->id]->cantidad_entregada - (int) $orden->detalles->firstWhere('product_id', $productoM->id)->retornosDetalles->sum('cantidad_reportada');
    $pendienteL = (int) $detalles[$productoL->id]->cantidad_entregada - (int) $orden->detalles->firstWhere('product_id', $productoL->id)->retornosDetalles->sum('cantidad_reportada');

    expect($pendienteM)->toBe(40)
        ->and($pendienteL)->toBe(40)
        ->and($orden->estado)->toBe(EstadoOrdenConfeccion::EnConfeccion->value);

    // Segunda entrega: M=20, L=20.
    $service->reportarTaller($orden, [
        'detalles' => [
            ['id' => $detalles[$productoM->id]->id, 'cantidad' => 20],
            ['id' => $detalles[$productoL->id]->id, 'cantidad' => 20],
        ],
    ]);

    $orden = $orden->fresh(['detalles.retornosDetalles']);
    $pendienteM = (int) $detalles[$productoM->id]->cantidad_entregada - (int) $orden->detalles->firstWhere('product_id', $productoM->id)->retornosDetalles->sum('cantidad_reportada');
    $pendienteL = (int) $detalles[$productoL->id]->cantidad_entregada - (int) $orden->detalles->firstWhere('product_id', $productoL->id)->retornosDetalles->sum('cantidad_reportada');

    expect($pendienteM)->toBe(20)
        ->and($pendienteL)->toBe(20)
        ->and($orden->estado)->not->toBe(EstadoOrdenConfeccion::Cerrada->value)
        ->and($orden->estado)->not->toBe(EstadoOrdenConfeccion::TerminadaTaller->value)
        ->and($orden->estado)->toBe(EstadoOrdenConfeccion::EnConfeccion->value);
});

test('RET-11 - taller reporta el pendiente final y la orden queda TerminadaTaller mientras bodega no ha recibido', function () {
    $admin = dist_crearAdmin('Admin Entrega Total Final QA');
    Auth::guard('admin')->setUser($admin);

    $productoM = Product::factory()->simple()->create();
    $productoL = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoM, 'talla' => 'M', 'cantidad_recibida' => 70, 'cantidad_defectuosa' => 0],
        ['product' => $productoL, 'talla' => 'L', 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [[
            'taller_id' => $taller->id,
            'detalles' => [
                ['product_id' => $productoM->id, 'cantidad' => 70],
                ['product_id' => $productoL->id, 'cantidad' => 40],
            ],
        ]],
    ])->first();

    $detalles = $orden->detalles->keyBy('product_id');
    $service = app(OrdenConfeccionService::class);

    $service->reportarTaller($orden, [
        'detalles' => [
            ['id' => $detalles[$productoM->id]->id, 'cantidad' => 30],
        ],
    ]);

    $orden = $orden->fresh();

    $service->reportarTaller($orden, [
        'detalles' => [
            ['id' => $detalles[$productoM->id]->id, 'cantidad' => 20],
            ['id' => $detalles[$productoL->id]->id, 'cantidad' => 20],
        ],
    ]);

    // Cierra el pendiente: M=40 (resta 20), L=40 (resta 20).
    $orden = $orden->fresh();
    $service->reportarTaller($orden, [
        'detalles' => [
            ['id' => $detalles[$productoM->id]->id, 'cantidad' => 20],
            ['id' => $detalles[$productoL->id]->id, 'cantidad' => 20],
        ],
    ]);

    $orden = $orden->fresh(['detalles.retornosDetalles', 'retornos']);
    $totalEntregado = (int) $orden->detalles->sum('cantidad_entregada');
    $totalReportado = (int) $orden->detalles->flatMap(fn ($d) => $d->retornosDetalles)->sum('cantidad_reportada');

    expect($totalReportado)->toBe($totalEntregado)
        ->and($totalEntregado)->toBe(110)
        // No inventar estados: el existente que corresponde exactamente a
        // "taller termino, bodega no ha recibido nada todavia" es
        // TerminadaTaller.
        ->and($orden->estado)->toBe(EstadoOrdenConfeccion::TerminadaTaller->value)
        ->and($orden->retornos->where('estado', EstadoOrdenConfeccionRetorno::PendienteBodega->value))->toHaveCount(3);

    // Con todo reportado, un nuevo intento de reportar debe rechazarse
    // (no queda pendiente que reportar).
    expect(fn () => $service->reportarTaller($orden, [
        'detalles' => [['id' => $detalles[$productoM->id]->id, 'cantidad' => 1]],
    ]))->toThrow(ValidationException::class);
});

test('RET-12 - la orden solo llega a Cerrada cuando todo fue reportado, recibido y no quedan retornos pendientes', function () {
    $admin = dist_crearAdmin('Admin Regla Cierre QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 40]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);
    $detalleId = $orden->detalles->first()->id;

    // 1) Reportado parcial (20 de 40): ni reportado ni recibido esta completo.
    $retorno1 = $service->reportarTaller($orden, ['detalles' => [['id' => $detalleId, 'cantidad' => 20]]]);
    expect($orden->fresh()->estado)->toBe(EstadoOrdenConfeccion::EnConfeccion->value);

    // 2) Reportado completo (40 de 40) pero AUN sin recibir: TerminadaTaller,
    // no Cerrada (retornos pendientes de bodega > 0).
    $service->reportarTaller($orden->fresh(), ['detalles' => [['id' => $detalleId, 'cantidad' => 20]]]);
    expect($orden->fresh()->estado)->toBe(EstadoOrdenConfeccion::TerminadaTaller->value);

    // 3) Bodega recibe el PRIMER retorno (20) pero queda el segundo
    // pendiente: RecibidaBodega y Cerrada ambas exigen retornos_pendientes=0,
    // asi que la orden no debe avanzar a ninguno de esos dos todavia.
    $service->recibirBodega($retorno1, [
        'detalles' => [['id' => $retorno1->detalles->first()->id, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0]],
    ]);
    expect($orden->fresh()->estado)->toBe(EstadoOrdenConfeccion::TerminadaTaller->value);

    // 4) Bodega recibe el retorno restante: ahora si, retornos_pendientes=0,
    // reportado>=entregado, recibido>=reportado y todo movimiento de
    // inventario ya se registro -> Cerrada.
    $retorno2 = $orden->fresh()->retornos->where('estado', EstadoOrdenConfeccionRetorno::PendienteBodega->value)->first();
    $service->recibirBodega($retorno2, [
        'detalles' => [['id' => $retorno2->detalles->first()->id, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0]],
    ]);

    expect($orden->fresh()->estado)->toBe(EstadoOrdenConfeccion::Cerrada->value)
        ->and($orden->fresh()->retornos()->where('estado', EstadoOrdenConfeccionRetorno::PendienteBodega->value)->count())->toBe(0);
});

test('RET-13 - defectuosas y faltantes no dejan pendiente de confeccion residual: la obligacion del taller termina al reportar', function () {
    $admin = dist_crearAdmin('Admin Defectuosas Sin Pendiente QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 70, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 70]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);
    $detalleId = $orden->detalles->first()->id;

    $retorno = $service->reportarTaller($orden, ['detalles' => [['id' => $detalleId, 'cantidad' => 70]]]);

    $service->recibirBodega($retorno, [
        'detalles' => [[
            'id' => $retorno->detalles->first()->id,
            'cantidad_recibida' => 68,
            'cantidad_defectuosa' => 1,
            'cantidad_faltante' => 1,
            'observacion_bodega' => 'Una defectuosa y una faltante en conteo fisico.',
        ]],
    ]);

    $orden = $orden->fresh(['detalles.retornosDetalles']);
    $detalle = $orden->detalles->first();

    // "Pendiente de confeccion" (obligacion del taller) se calcula SOLO con
    // cantidad_reportada: 70 reportadas de 70 asignadas = 0 pendiente, sin
    // importar cuantas resultaron defectuosas o faltantes en bodega.
    $pendienteConfeccion = (int) $detalle->cantidad_entregada - (int) $detalle->retornosDetalles->sum('cantidad_reportada');

    expect($pendienteConfeccion)->toBe(0)
        ->and($pendienteConfeccion)->not->toBe(2)
        ->and($orden->estado)->toBe(EstadoOrdenConfeccion::Cerrada->value);

    // La merma/incidencia fisica (defectuosa + faltante) es un dato
    // DISTINTO, de control de bodega, y no debe confundirse con el
    // pendiente de confeccion.
    $mermaFisica = (int) $detalle->retornosDetalles->sum('cantidad_defectuosa') + (int) $detalle->retornosDetalles->sum('cantidad_faltante');

    expect($mermaFisica)->toBe(2);
});

test('RET-14 - doble recepcion via HTTP es idempotente: la segunda peticion se rechaza y no duplica inventario', function () {
    $roleBodega = Role::factory()->create([
        'name' => 'BODEGA',
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.recibir_bodega'],
    ]);
    $usuarioBodega = Admin::factory()->create(['role_id' => $roleBodega->id]);

    Auth::guard('admin')->setUser(dist_crearAdmin('Admin RET14 QA'));

    $producto = Product::factory()->simple()->create();
    $source = dist_sembrarStockBagisto($producto, 0);
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 40]]]],
    ])->first();

    $retorno = app(OrdenConfeccionService::class)->reportarTaller($orden, [
        'detalles' => [['id' => $orden->detalles->first()->id, 'cantidad' => 40]],
    ]);

    $payload = [
        'detalles' => [[
            'id' => $retorno->detalles->first()->id,
            'cantidad_recibida' => 40,
            'cantidad_defectuosa' => 0,
            'cantidad_faltante' => 0,
        ]],
    ];

    $primera = $this->actingAs($usuarioBodega, 'admin')
        ->post(route('admin.corte_confeccion.ordenes_confeccion.recibir_bodega', $retorno->id), $payload);
    $primera->assertRedirect();
    $primera->assertSessionHasNoErrors();

    $segunda = $this->actingAs($usuarioBodega, 'admin')
        ->post(route('admin.corte_confeccion.ordenes_confeccion.recibir_bodega', $retorno->id), $payload);
    $segunda->assertSessionHasErrors();

    expect(dist_stockFisico($producto, $source->id))->toBe(40)
        ->and(InventarioMovimiento::query()->where('retorno_detalle_id', $retorno->detalles->first()->id)->count())->toBe(1)
        ->and($retorno->fresh()->estado)->toBe(EstadoOrdenConfeccionRetorno::RecibidaBodega->value);
});

test('RET-15 - dos solicitudes secuenciales de taller no pueden superar juntas el pendiente exacto', function () {
    $admin = dist_crearAdmin('Admin RET15 QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 20]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);
    $detalleId = $orden->detalles->first()->id;

    // Solicitud A: reporta los 20 disponibles.
    $service->reportarTaller($orden, ['detalles' => [['id' => $detalleId, 'cantidad' => 20]]]);

    // Solicitud B (llega "al mismo tiempo" con el mismo saldo de 20 en
    // mente): debe rechazarse porque, para cuando se procesa, el pendiente
    // real ya es 0. El lockForUpdate() sobre la orden en reportarTaller()
    // serializa ambas solicitudes aunque hubieran llegado concurrentemente.
    expect(fn () => $service->reportarTaller($orden->fresh(), ['detalles' => [['id' => $detalleId, 'cantidad' => 20]]]))
        ->toThrow(ValidationException::class);

    $totalReportado = (int) $orden->fresh(['detalles.retornosDetalles'])->detalles->first()->retornosDetalles->sum('cantidad_reportada');

    expect($totalReportado)->toBe(20)
        ->and($totalReportado)->not->toBe(40);
});

test('RET-16 - el lockForUpdate() de reportarTaller bloquea una segunda conexion real hasta que la primera libera', function () {
    // Marcas de "antes" para poder borrar en el finally EXACTAMENTE los
    // admins/roles que cree esta prueba (varios se crean dentro de los
    // helpers dist_crearOrdenCorteRecibida/dist_crearTaller, sin devolver
    // sus ids), sin depender del rollback automatico de DatabaseTransactions
    // que aqui no aplica (ver comentario sobre DB::commit() mas abajo).
    $adminMaxIdAntes = (int) DB::table('admins')->max('id');
    $roleMaxIdAntes = (int) DB::table('roles')->max('id');

    $admin = dist_crearAdmin('Admin Concurrencia Real QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 20]]]],
    ])->first();

    /*
     * Para que una SEGUNDA conexion real (no otro modelo Eloquent en la
     * misma conexion) pueda ver estas filas y disputar el lock, la
     * transaccion ambiental de DatabaseTransactions debe comitearse. Se
     * reabre inmediatamente para que el tearDown normal del framework siga
     * funcionando, y absolutamente todo lo creado aqui (incluida esta
     * orden) se borra a mano en el finally, sin depender del rollback
     * automatico.
     */
    DB::commit();
    DB::beginTransaction();

    $pdo2 = null;

    try {
        $pdo2 = new PDO('mysql:host=127.0.0.1;port=3306;dbname=lpz_saas_testing;charset=utf8mb4', 'root', '');
        $pdo2->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $pdo2->beginTransaction();
        $pdo2->query('SELECT id FROM bylopez_cc_ordenes_confeccion WHERE id = '.(int) $orden->id.' FOR UPDATE');

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        $bloqueadoPorLockReal = false;

        try {
            app(OrdenConfeccionService::class)->reportarTaller($orden, [
                'detalles' => [['id' => $orden->detalles->first()->id, 'cantidad' => 5]],
            ]);
        } catch (Throwable $e) {
            $bloqueadoPorLockReal = str_contains($e->getMessage(), '1205')
                || str_contains(strtolower($e->getMessage()), 'lock wait timeout');
        }

        // Libera el lock de la segunda conexion: la primera ya debe poder
        // proceder normalmente.
        $pdo2->rollBack();

        expect($bloqueadoPorLockReal)->toBeTrue();

        DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');

        $retorno = app(OrdenConfeccionService::class)->reportarTaller($orden->fresh(), [
            'detalles' => [['id' => $orden->detalles->first()->id, 'cantidad' => 5]],
        ]);

        expect($retorno->detalles->sum('cantidad_reportada'))->toBe(5);
    } finally {
        $pdo2 = null;

        DB::table('bylopez_cc_ordenes_confeccion_retorno_detalles')->whereIn('retorno_id', function ($q) use ($orden) {
            $q->select('id')->from('bylopez_cc_ordenes_confeccion_retornos')->where('orden_confeccion_id', $orden->id);
        })->delete();
        DB::table('bylopez_cc_ordenes_confeccion_retornos')->where('orden_confeccion_id', $orden->id)->delete();
        DB::table('bylopez_cc_ordenes_confeccion_detalles')->where('orden_confeccion_id', $orden->id)->delete();
        DB::table('bylopez_cc_ordenes_confeccion')->where('id', $orden->id)->delete();

        $ordenCorteFresca = $ordenCorte->fresh(['rollosCorte.rollo.compraTela', 'recepcion.detalles']);
        $rolloIds = $ordenCorteFresca->rollosCorte->pluck('rollo_id');
        $compraIds = DB::table('bylopez_cc_rollos')->whereIn('id', $rolloIds)->pluck('compra_tela_id')->unique();
        $proveedorIds = DB::table('bylopez_cc_compras_tela')->whereIn('id', $compraIds)->pluck('proveedor_id')->unique();

        DB::table('bylopez_cc_recepciones_corte_detalles')->whereIn('recepcion_corte_id', function ($q) use ($ordenCorte) {
            $q->select('id')->from('bylopez_cc_recepciones_corte')->where('orden_corte_id', $ordenCorte->id);
        })->delete();
        DB::table('bylopez_cc_recepciones_corte')->where('orden_corte_id', $ordenCorte->id)->delete();
        DB::table('bylopez_cc_ordenes_corte_detalles')->where('orden_corte_id', $ordenCorte->id)->delete();
        DB::table('bylopez_cc_orden_corte_rollos')->where('orden_corte_id', $ordenCorte->id)->delete();
        DB::table('bylopez_cc_ordenes_corte')->where('id', $ordenCorte->id)->delete();
        DB::table('bylopez_cc_rollos')->whereIn('id', $rolloIds)->delete();
        DB::table('bylopez_cc_compras_tela')->whereIn('id', $compraIds)->delete();
        DB::table('bylopez_cc_proveedor_tela_referencias')->whereIn('proveedor_tela_id', $proveedorIds)->delete();
        DB::table('bylopez_cc_proveedor_tela_tipos')->whereIn('proveedor_tela_id', $proveedorIds)->delete();
        DB::table('bylopez_cc_proveedores_tela')->whereIn('id', $proveedorIds)->delete();
        DB::table('bylopez_cc_talleres')->where('id', $taller->id)->delete();

        DB::table('admins')->where('id', '>', $adminMaxIdAntes)->delete();
        DB::table('roles')->where('id', '>', $roleMaxIdAntes)->delete();

        /*
         * Critico: estos deletes de limpieza corren dentro de la transaccion
         * que se reabrio despues del DB::commit() de arriba. El tearDown
         * normal de DatabaseTransactions va a hacer UN rollback al terminar
         * la prueba; si no se comitea aqui primero, ese rollback deshace la
         * limpieza (no los inserts, que ya quedaron comiteados antes) y
         * todo lo creado por esta prueba queda huerfano en la base de
         * pruebas para siempre. Se comitea la limpieza y se reabre una
         * transaccion vacia para que ese rollback final no falle ni afecte
         * nada real.
         */
        DB::commit();
        DB::beginTransaction();
    }
})->skip(
    ! extension_loaded('pdo_mysql'),
    'Requiere la extension pdo_mysql para abrir una segunda conexion real y probar el lock entre conexiones.'
);

test('RET-17 - un usuario sin ningun ACL relevante no puede ver, reportar ni recibir', function () {
    $roleSinPermisos = Role::factory()->create([
        'name' => 'Sin Permisos QA',
        'permission_type' => 'custom',
        'permissions' => ['dashboard'],
    ]);
    $usuarioSinPermisos = Admin::factory()->create(['role_id' => $roleSinPermisos->id]);

    Auth::guard('admin')->setUser(dist_crearAdmin('Admin RET17 QA'));

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 20]]]],
    ])->first();

    $retorno = app(OrdenConfeccionService::class)->reportarTaller($orden, [
        'detalles' => [['id' => $orden->detalles->first()->id, 'cantidad' => 10]],
    ]);

    // Un rol sin NINGUNA clave de ACL de corte_confeccion.ordenes_confeccion.*
    // ni siquiera tiene mapeo de ruta en acl()->getRoles(): el middleware
    // Bouncer corta con 401 antes de que el controlador o el Gate se
    // ejecuten. Es una proteccion MAS estricta que el 403 del Gate (ese
    // aplica cuando SI hay acceso a la ruta pero el Gate niega por
    // ownership/rol), no una falla de seguridad.
    $this->actingAs($usuarioSinPermisos, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.show', $orden->id))
        ->assertStatus(401);

    $this->actingAs($usuarioSinPermisos, 'admin')
        ->post(route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $orden->id), [
            'detalles' => [['id' => $orden->detalles->first()->id, 'cantidad' => 1]],
        ])
        ->assertStatus(401);

    $this->actingAs($usuarioSinPermisos, 'admin')
        ->post(route('admin.corte_confeccion.ordenes_confeccion.recibir_bodega', $retorno->id), [
            'detalles' => [['id' => $retorno->detalles->first()->id, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0]],
        ])
        ->assertStatus(401);

    expect($retorno->fresh()->estado)->toBe(EstadoOrdenConfeccionRetorno::PendienteBodega->value)
        ->and($orden->fresh()->retornos()->count())->toBe(1);
});

test('RET-18 - una orden anulada bloquea nuevos reportes y recepciones sin borrar historial previo', function () {
    $admin = dist_crearAdmin('Admin RET18 QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 20]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);

    // La regla YA existente (cancel() solo permite anular estado Activa) es
    // la razon por la que "retornos historicos previos que sobreviven a la
    // anulacion" es un escenario que hoy no puede ocurrir: una orden con
    // algun retorno ya paso a EnConfeccion/TerminadaTaller/etc., y cancel()
    // la rechaza. Se documenta con este mismo test en vez de asumirlo.
    $service->reportarTaller($orden, ['detalles' => [['id' => $orden->detalles->first()->id, 'cantidad' => 5]]]);

    expect(fn () => $service->cancel($orden->fresh()))->toThrow(ValidationException::class);

    // Con una orden SIN retornos (estado Activa), anular si es valido y no
    // hay ningun historico que pudiera perderse.
    $ordenCorte2 = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0],
    ]);
    $ordenSinRetornos = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte2->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 10]]]],
    ])->first();

    $ordenAnulada = $service->cancel($ordenSinRetornos);
    expect($ordenAnulada->estado)->toBe(EstadoOrdenConfeccion::Anulada->value);

    // Taller no puede reportar sobre una orden anulada.
    expect(fn () => $service->reportarTaller($ordenAnulada->fresh(), [
        'detalles' => [['id' => $ordenAnulada->detalles->first()->id, 'cantidad' => 1]],
    ]))->toThrow(ValidationException::class);

    // El retorno de la PRIMERA orden (la que si tiene historial, y quedo
    // pendiente porque no se pudo anular) sigue existiendo y visible.
    $retornoHistorico = $orden->fresh()->retornos->first();
    expect($retornoHistorico)->not->toBeNull()
        ->and($retornoHistorico->detalles->sum('cantidad_reportada'))->toBe(5);

    // Bodega no puede recibir un retorno cuya orden quedo anulada. Se anula
    // la orden DEL RETORNO manualmente via DB para simular el escenario
    // exacto que pide el punto 13 (orden anulada con retorno pendiente),
    // aunque el flujo normal de cancel() no permita llegar aqui.
    DB::table('bylopez_cc_ordenes_confeccion')->where('id', $orden->id)->update(['estado' => EstadoOrdenConfeccion::Anulada->value]);

    expect(fn () => $service->recibirBodega($retornoHistorico->fresh(), [
        'detalles' => [['id' => $retornoHistorico->detalles->first()->id, 'cantidad_recibida' => 5, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0]],
    ]))->toThrow(ValidationException::class);

    // No aumenta inventario ni se genera movimiento, y el retorno historico
    // sigue existiendo (nunca se borra) para auditoria.
    expect(InventarioMovimiento::query()->where('retorno_id', $retornoHistorico->id)->count())->toBe(0)
        ->and(OrdenConfeccionRetorno::find($retornoHistorico->id))->not->toBeNull()
        ->and($retornoHistorico->fresh()->estado)->toBe(EstadoOrdenConfeccionRetorno::PendienteBodega->value);
});

test('RET-19 - el badge retornos_pendientes cuenta solo PendienteBodega: 2 pendientes de 3 retornos, no 3', function () {
    $admin = dist_crearAdmin('Admin RET19 QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 30, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 30]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);
    $detalleId = $orden->detalles->first()->id;

    // ENT-001: se reporta y SI se recibe.
    $ent001 = $service->reportarTaller($orden, ['detalles' => [['id' => $detalleId, 'cantidad' => 10]]]);
    $service->recibirBodega($ent001, [
        'detalles' => [['id' => $ent001->detalles->first()->id, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0]],
    ]);

    // ENT-002 y ENT-003: se reportan pero NO se reciben (quedan pendientes).
    $service->reportarTaller($orden->fresh(), ['detalles' => [['id' => $detalleId, 'cantidad' => 10]]]);
    $service->reportarTaller($orden->fresh(), ['detalles' => [['id' => $detalleId, 'cantidad' => 10]]]);

    $orden = $orden->fresh(['retornos']);
    expect($orden->retornos)->toHaveCount(3)
        ->and($orden->retornos->pluck('codigo')->sort()->values()->all())->toBe([
            $orden->codigo.'-ENT-001',
            $orden->codigo.'-ENT-002',
            $orden->codigo.'-ENT-003',
        ]);

    $fila = app(OrdenConfeccionDataGrid::class)->prepareQueryBuilder()->where('ofc.id', $orden->id)->first();

    expect((int) $fila->retornos_pendientes)->toBe(2)
        ->and((int) $fila->retornos_pendientes)->not->toBe(3);
});

test('RET-20 - el historial de entregas muestra reportado, buenas/aprobadas, defectuosas, faltantes y estado por cada ENT-XXX', function () {
    $roleBodega = Role::factory()->create([
        'name' => 'BODEGA',
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.recibir_bodega'],
    ]);
    $usuarioBodega = Admin::factory()->create(['role_id' => $roleBodega->id]);

    Auth::guard('admin')->setUser(dist_crearAdmin('Admin RET20 QA'));

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 40]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);
    $detalleId = $orden->detalles->first()->id;

    $ent001 = $service->reportarTaller($orden, ['detalles' => [['id' => $detalleId, 'cantidad' => 40]]]);
    $service->recibirBodega($ent001, [
        'detalles' => [['id' => $ent001->detalles->first()->id, 'cantidad_recibida' => 38, 'cantidad_defectuosa' => 1, 'cantidad_faltante' => 1, 'observacion_bodega' => 'Conteo fisico.']],
    ]);

    $response = $this->actingAs($usuarioBodega, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.show', $orden->id));

    $response->assertOk();
    $response->assertSee($orden->codigo.'-ENT-001', false);
    $response->assertSee('Reportadas', false);
    $response->assertSee('40', false);
    $response->assertSee('Buenas / aprobadas', false);
    $response->assertSee('38', false);
    $response->assertSee('Defectuosas', false);
    $response->assertSee('Faltantes', false);
    $response->assertSee(EstadoOrdenConfeccionRetorno::RecibidaBodega->label(), false);

    // No es un CRUD editable: no debe existir ningun formulario/accion de
    // edicion sobre un retorno ya recibido en la vista.
    $response->assertDontSee('Editar entrega', false);
    $response->assertDontSee('name="detalles[0][cantidad_recibida]"', false);
});

test('RET-21 - stock acumulativo end-to-end: product_inventories, product_inventory_indices y movimientos independientes por recepcion', function () {
    $admin = dist_crearAdmin('Admin RET21 Stock QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $source = dist_sembrarStockBagisto($producto, 15);
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 70, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 70]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);
    $detalleId = $orden->detalles->first()->id;

    expect(dist_stockFisico($producto, $source->id))->toBe(15)
        ->and(dist_stockIndexado($producto))->toBe(15);

    $ent001 = $service->reportarTaller($orden, ['detalles' => [['id' => $detalleId, 'cantidad' => 30]]]);
    $service->recibirBodega($ent001, [
        'detalles' => [['id' => $ent001->detalles->first()->id, 'cantidad_recibida' => 28, 'cantidad_defectuosa' => 1, 'cantidad_faltante' => 1, 'observacion_bodega' => 'QA']],
    ]);

    expect(dist_stockFisico($producto, $source->id))->toBe(43)
        ->and(dist_stockIndexado($producto))->toBe(43);

    $ent002 = $service->reportarTaller($orden->fresh(), ['detalles' => [['id' => $detalleId, 'cantidad' => 40]]]);
    $service->recibirBodega($ent002, [
        'detalles' => [['id' => $ent002->detalles->first()->id, 'cantidad_recibida' => 35, 'cantidad_defectuosa' => 3, 'cantidad_faltante' => 2, 'observacion_bodega' => 'QA']],
    ]);

    expect(dist_stockFisico($producto, $source->id))->toBe(78)
        ->and(dist_stockIndexado($producto))->toBe(78);

    $movimientos = InventarioMovimiento::query()
        ->where('product_id', $producto->id)
        ->where('tipo_movimiento', TipoInventarioMovimiento::EntradaConfeccion->value)
        ->orderBy('id')
        ->get();

    expect($movimientos)->toHaveCount(2)
        ->and($movimientos[0]->retorno_id)->toBe($ent001->id)
        ->and($movimientos[0]->cantidad_movimiento)->toBe(28)
        ->and($movimientos[0]->cantidad_anterior)->toBe(15)
        ->and($movimientos[0]->cantidad_nueva)->toBe(43)
        ->and($movimientos[1]->retorno_id)->toBe($ent002->id)
        ->and($movimientos[1]->cantidad_movimiento)->toBe(35)
        ->and($movimientos[1]->cantidad_anterior)->toBe(43)
        ->and($movimientos[1]->cantidad_nueva)->toBe(78)
        ->and($movimientos[0]->retorno_detalle_id)->not->toBe($movimientos[1]->retorno_detalle_id);
});

test('RET-22 - los codigos ENT-XXX son secuenciales por orden y la restriccion unica impide duplicados', function () {
    $admin = dist_crearAdmin('Admin RET22 Secuencia QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 30, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 30]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);
    $detalleId = $orden->detalles->first()->id;

    $ent001 = $service->reportarTaller($orden, ['detalles' => [['id' => $detalleId, 'cantidad' => 10]]]);
    $ent002 = $service->reportarTaller($orden->fresh(), ['detalles' => [['id' => $detalleId, 'cantidad' => 10]]]);
    $ent003 = $service->reportarTaller($orden->fresh(), ['detalles' => [['id' => $detalleId, 'cantidad' => 10]]]);

    expect($ent001->codigo)->toBe($orden->codigo.'-ENT-001')
        ->and($ent002->codigo)->toBe($orden->codigo.'-ENT-002')
        ->and($ent003->codigo)->toBe($orden->codigo.'-ENT-003')
        ->and($ent001->numero)->toBe(1)
        ->and($ent002->numero)->toBe(2)
        ->and($ent003->numero)->toBe(3);

    // La restriccion unica (orden_confeccion_id, numero) es la proteccion
    // real contra duplicados: un intento de insertar el mismo numero para
    // la misma orden debe fallar a nivel de base de datos.
    expect(fn () => DB::table('bylopez_cc_ordenes_confeccion_retornos')->insert([
        'codigo' => $orden->codigo.'-ENT-002-DUP',
        'orden_confeccion_id' => $orden->id,
        'taller_id' => $orden->taller_id,
        'numero' => 2,
        'fecha_reporte_taller' => now(),
        'estado' => EstadoOrdenConfeccionRetorno::PendienteBodega->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // El codigo completo tambien es unico globalmente (columna unica
    // "codigo"), asi que reutilizar el mismo string tambien falla aunque
    // el numero fuera distinto.
    expect(fn () => DB::table('bylopez_cc_ordenes_confeccion_retornos')->insert([
        'codigo' => $ent001->codigo,
        'orden_confeccion_id' => $orden->id,
        'taller_id' => $orden->taller_id,
        'numero' => 99,
        'fecha_reporte_taller' => now(),
        'estado' => EstadoOrdenConfeccionRetorno::PendienteBodega->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect($orden->fresh()->retornos)->toHaveCount(3);
});

test('RET-23 - backend rechaza escenarios invalidos de recepcion aunque no pasen por la UI', function () {
    $admin = dist_crearAdmin('Admin RET23 Validaciones QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $productoOtro = Product::factory()->simple()->create();
    $taller = dist_crearTaller();
    $tallerOtro = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 30, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 30]]]],
    ])->first();

    $ordenCorteOtra = dist_crearOrdenCorteRecibida([
        ['product' => $productoOtro, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0],
    ]);
    $ordenOtra = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorteOtra->id,
        'asignaciones' => [['taller_id' => $tallerOtro->id, 'detalles' => [['product_id' => $productoOtro->id, 'cantidad' => 10]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);
    $detalleId = $orden->detalles->first()->id;

    $retorno = $service->reportarTaller($orden, ['detalles' => [['id' => $detalleId, 'cantidad' => 20]]]);
    $retornoDetalleId = $retorno->detalles->first()->id;

    $base = ['id' => $retornoDetalleId, 'observacion_bodega' => 'QA'];

    // 1) Cantidad negativa.
    expect(fn () => $service->recibirBodega($retorno, ['detalles' => [array_merge($base, [
        'cantidad_recibida' => -1, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 21,
    ])]]))->toThrow(ValidationException::class);

    // 2) Buenas > reportadas (aislado: 21 > 20, con defectuosa/faltante en 0
    // para que la unica razon de rechazo sea exceder lo reportado).
    expect(fn () => $service->recibirBodega($retorno, ['detalles' => [[
        'id' => $retornoDetalleId, 'cantidad_recibida' => 21, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0,
    ]]]))->toThrow(ValidationException::class);

    // 3) Defectuosas > reportadas (aislado: 21 > 20).
    expect(fn () => $service->recibirBodega($retorno, ['detalles' => [array_merge($base, [
        'cantidad_recibida' => 0, 'cantidad_defectuosa' => 21, 'cantidad_faltante' => 0,
    ])]]))->toThrow(ValidationException::class);

    // 4) Buenas + defectuosas > reportadas (11 + 11 = 22 > 20), aunque cada
    // una por separado sea menor que 20.
    expect(fn () => $service->recibirBodega($retorno, ['detalles' => [array_merge($base, [
        'cantidad_recibida' => 11, 'cantidad_defectuosa' => 11, 'cantidad_faltante' => 0,
    ])]]))->toThrow(ValidationException::class);

    // 5) Variante inexistente (id de detalle de retorno que no existe).
    expect(fn () => $service->recibirBodega($retorno, ['detalles' => [[
        'id' => 999999999, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0,
    ]]]))->toThrow(ValidationException::class);

    // 6) Variante de OTRA orden (detalle de retorno que pertenece a $ordenOtra).
    $retornoOtro = $service->reportarTaller($ordenOtra, ['detalles' => [['id' => $ordenOtra->detalles->first()->id, 'cantidad' => 5]]]);
    expect(fn () => $service->recibirBodega($retorno, ['detalles' => [[
        'id' => $retornoOtro->detalles->first()->id, 'cantidad_recibida' => 5, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0,
    ]]]))->toThrow(ValidationException::class);

    // 7) Retorno ya recibido: no puede volver a recibirse.
    $service->recibirBodega($retorno, ['detalles' => [array_merge($base, [
        'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0,
    ])]]);
    expect(fn () => $service->recibirBodega($retorno->fresh(), ['detalles' => [array_merge($base, [
        'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0,
    ])]]))->toThrow(ValidationException::class);

    // 8) Retorno de una orden anulada (se anula la orden manualmente porque
    // cancel() de negocio no permite anular una orden con retornos, tal
    // como se documento en RET-18; esto prueba el guard de recibirBodega
    // en el escenario exacto que pide el enunciado).
    $retornoOtroDetalleId = $ordenOtra->detalles->first()->id;
    $retornoSegundo = $service->reportarTaller($ordenOtra->fresh(), ['detalles' => [['id' => $retornoOtroDetalleId, 'cantidad' => 5]]]);
    DB::table('bylopez_cc_ordenes_confeccion')->where('id', $ordenOtra->id)->update(['estado' => EstadoOrdenConfeccion::Anulada->value]);
    expect(fn () => $service->recibirBodega($retornoSegundo->fresh(), ['detalles' => [[
        'id' => $retornoSegundo->detalles->first()->id, 'cantidad_recibida' => 5, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0,
    ]]]))->toThrow(ValidationException::class);
});

test('RET-24 - TEST FINAL INTEGRAL: flujo completo bodega-distribuye hasta orden cerrada, con seguridad post-cierre', function () {
    $roleTaller = Role::factory()->create([
        'name' => 'TALLER CONFECCION',
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.reportar_taller'],
    ]);
    $usuarioTaller = Admin::factory()->create(['role_id' => $roleTaller->id]);

    $roleBodega = Role::factory()->create([
        'name' => 'BODEGA',
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.recibir_bodega'],
    ]);
    $usuarioBodega = Admin::factory()->create(['role_id' => $roleBodega->id]);

    Auth::guard('admin')->setUser(dist_crearAdmin('Admin RET24 Integral QA'));

    $productoM = Product::factory()->simple()->create();
    $productoL = Product::factory()->simple()->create();
    $source = dist_sembrarStockBagisto($productoM, 0);
    dist_sembrarStockBagisto($productoL, 0);
    $taller = dist_crearTaller(['usuario_id' => $usuarioTaller->id]);

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $productoM, 'talla' => 'M', 'cantidad_recibida' => 70, 'cantidad_defectuosa' => 0],
        ['product' => $productoL, 'talla' => 'L', 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    // 1) Bodega (via Admin con permission_type=all en este helper de fixture;
    // la autorizacion real de "quien puede distribuir" ya esta cubierta por
    // SEC-01 y no es el foco de este test) distribuye M=70, L=40 al taller.
    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [[
            'taller_id' => $taller->id,
            'detalles' => [
                ['product_id' => $productoM->id, 'cantidad' => 70],
                ['product_id' => $productoL->id, 'cantidad' => 40],
            ],
        ]],
    ])->first();

    $detalles = $orden->detalles->keyBy('product_id');
    $detalleM = $detalles[$productoM->id];
    $detalleL = $detalles[$productoL->id];

    // 2) Taller reporta M=30.
    $entregaHttp1 = $this->actingAs($usuarioTaller, 'admin')
        ->post(route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $orden->id), [
            'detalles' => [['id' => $detalleM->id, 'cantidad' => 30]],
        ]);
    $entregaHttp1->assertSessionHasNoErrors();

    $retorno1 = $orden->fresh()->retornos->sortBy('numero')->first();
    expect($retorno1->codigo)->toBe($orden->codigo.'-ENT-001');

    // 3) Bodega recibe: buenas=28, defectuosas=1, faltantes=1.
    $recepcionHttp1 = $this->actingAs($usuarioBodega, 'admin')
        ->post(route('admin.corte_confeccion.ordenes_confeccion.recibir_bodega', $retorno1->id), [
            'detalles' => [[
                'id' => $retorno1->detalles->first()->id,
                'cantidad_recibida' => 28,
                'cantidad_defectuosa' => 1,
                'cantidad_faltante' => 1,
                'observacion_bodega' => 'Conteo fisico ENT-001.',
            ]],
        ]);
    $recepcionHttp1->assertSessionHasNoErrors();

    // 4) Inventario M: +28 (arranco en 0).
    expect(dist_stockFisico($productoM, $source->id))->toBe(28)
        ->and(dist_stockIndexado($productoM))->toBe(28);

    // 5) Orden sigue abierta (no cerrada: falta reportar/recibir el resto).
    $orden = $orden->fresh();
    expect($orden->estado)->not->toBe(EstadoOrdenConfeccion::Cerrada->value)
        ->and($orden->estado)->toBe(EstadoOrdenConfeccion::EnConfeccion->value);

    // 6) Taller reporta el resto: M=40, L=40.
    $entregaHttp2 = $this->actingAs($usuarioTaller, 'admin')
        ->post(route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $orden->id), [
            'detalles' => [
                ['id' => $detalleM->id, 'cantidad' => 40],
                ['id' => $detalleL->id, 'cantidad' => 40],
            ],
        ]);
    $entregaHttp2->assertSessionHasNoErrors();

    // 7) Taller ya no tiene pendiente.
    $orden = $orden->fresh(['detalles.retornosDetalles']);
    $totalEntregado = (int) $orden->detalles->sum('cantidad_entregada');
    $totalReportado = (int) $orden->detalles->flatMap(fn ($d) => $d->retornosDetalles)->sum('cantidad_reportada');
    expect($totalReportado)->toBe($totalEntregado)
        ->and($totalEntregado)->toBe(110);

    // 8) Orden refleja terminacion del taller, pero existen entregas
    // pendientes de bodega (el ENT-002 recien creado).
    expect($orden->estado)->toBe(EstadoOrdenConfeccion::TerminadaTaller->value);
    $retorno2 = $orden->fresh(['retornos'])->retornos->where('estado', EstadoOrdenConfeccionRetorno::PendienteBodega->value)->sortBy('numero')->first();
    expect($retorno2->codigo)->toBe($orden->codigo.'-ENT-002');

    // Taller B (no propietario) no puede ver ni reportar sobre esta orden.
    $tallerB = dist_crearTaller();
    $usuarioTallerB = Admin::find($tallerB->usuario_id);
    $this->actingAs($usuarioTallerB, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.show', $orden->id))
        ->assertStatus(403);

    // Bodega no puede reportar entregas suplantando al taller. Su rol no
    // tiene la clave ACL "reportar_taller" (bien configurado, no deberia
    // tenerla), asi que el middleware de ACL corta primero con 401; RET-08
    // ya prueba especificamente que el Gate ADEMAS bloquea a Bodega aunque
    // esa clave se le otorgue por error de configuracion.
    $this->actingAs($usuarioBodega, 'admin')
        ->post(route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $orden->id), [
            'detalles' => [['id' => $detalleM->id, 'cantidad' => 1]],
        ])
        ->assertStatus(401);

    // Taller no puede ejecutar la recepcion de bodega (misma razon: RET-07
    // ya prueba el bloqueo del Gate especificamente).
    $this->actingAs($usuarioTaller, 'admin')
        ->post(route('admin.corte_confeccion.ordenes_confeccion.recibir_bodega', $retorno2->id), [
            'detalles' => [['id' => $retorno2->detalles->first()->id, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0]],
        ])
        ->assertStatus(401);

    // 9) Bodega recibe la segunda entrega (M=40 buenas, L=40 buenas).
    $recepcionHttp2 = $this->actingAs($usuarioBodega, 'admin')
        ->post(route('admin.corte_confeccion.ordenes_confeccion.recibir_bodega', $retorno2->id), [
            'detalles' => $retorno2->detalles->map(fn ($d) => [
                'id' => $d->id,
                'cantidad_recibida' => $d->cantidad_reportada,
                'cantidad_defectuosa' => 0,
                'cantidad_faltante' => 0,
            ])->values()->all(),
        ]);
    $recepcionHttp2->assertSessionHasNoErrors();

    // 10) Inventario suma UNICAMENTE las buenas: M pasa de 28 a 68 (+40),
    // L pasa de 0 a 40 (+40). Nunca se suman defectuosas/faltantes.
    expect(dist_stockFisico($productoM, $source->id))->toBe(68)
        ->and(dist_stockIndexado($productoM))->toBe(68)
        ->and(dist_stockFisico($productoL, $source->id))->toBe(40)
        ->and(dist_stockIndexado($productoL))->toBe(40);

    // 11) No quedan retornos pendientes.
    $orden = $orden->fresh(['retornos']);
    expect($orden->retornos->where('estado', EstadoOrdenConfeccionRetorno::PendienteBodega->value))->toHaveCount(0);

    // 12) Orden llega al estado final EXISTENTE (Cerrada, no un estado
    // inventado): reportado=entregado, recibido=reportado, 0 pendientes de
    // bodega, y todas las buenas ya procesadas en inventario.
    expect($orden->estado)->toBe(EstadoOrdenConfeccion::Cerrada->value);

    // 13) No puede volver a recibirse ningun retorno.
    expect(fn () => app(OrdenConfeccionService::class)->recibirBodega($retorno2->fresh(), [
        'detalles' => [['id' => $retorno2->detalles->first()->id, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0]],
    ]))->toThrow(ValidationException::class);

    $this->actingAs($usuarioBodega, 'admin')
        ->post(route('admin.corte_confeccion.ordenes_confeccion.recibir_bodega', $retorno2->id), [
            'detalles' => [['id' => $retorno2->detalles->first()->id, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0]],
        ])
        ->assertSessionHasErrors();

    // 14) Taller no puede generar mas entregas sobre esta orden (cerrada).
    $this->actingAs($usuarioTaller, 'admin')
        ->post(route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $orden->id), [
            'detalles' => [['id' => $detalleM->id, 'cantidad' => 1]],
        ])
        ->assertSessionHasErrors();

    // El total de inventario procesado (28 + 40 + 40 = 108) coincide
    // exactamente con las buenas de los dos retornos; nunca con las 110
    // reportadas (que incluyen la defectuosa+faltante de ENT-001).
    $totalBuenasInventario = InventarioMovimiento::query()
        ->whereIn('product_id', [$productoM->id, $productoL->id])
        ->where('tipo_movimiento', TipoInventarioMovimiento::EntradaConfeccion->value)
        ->sum('cantidad_movimiento');

    expect((int) $totalBuenasInventario)->toBe(108)
        ->and((int) $totalBuenasInventario)->not->toBe(110);
});

/*
|--------------------------------------------------------------------------
| Fase 3 - View de Orden de Confeccion como centro operativo. No se toca
| reportarTaller()/recibirBodega()/inventario/distribucion: estos tests
| cubren navegacion listado->view y visibilidad condicional de acciones en
| show.blade.php (RET-25 en adelante).
|--------------------------------------------------------------------------
*/

test('RET-25 - desde el listado se puede llegar a la view: el codigo trae un enlace real a la ruta nombrada', function () {
    $admin = dist_crearAdmin('Admin RET25 Navegacion QA');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 20]]]],
    ])->first();

    $fila = app(OrdenConfeccionDataGrid::class)->prepareQueryBuilder()->where('ofc.id', $orden->id)->first();
    $columnas = app(OrdenConfeccionDataGrid::class);
    $columnas->prepareColumns();
    $reflexion = new ReflectionClass($columnas);
    $metodo = $reflexion->getMethod('codigoEnlace');
    $metodo->setAccessible(true);
    $html = $metodo->invoke($columnas, $fila);

    $rutaEsperada = route('admin.corte_confeccion.ordenes_confeccion.show', $orden->id);

    expect($html)->toContain('<a')
        ->and($html)->toContain($rutaEsperada)
        ->and($html)->toContain($orden->codigo);

    // La ruta nombrada realmente resuelve a la view (no hardcodeada en el
    // Blade: si la ruta cambiara de URI, esto seguiria funcionando).
    $this->actingAs($admin, 'admin')->get($rutaEsperada)->assertOk();
});

test('RET-26 - taller sin pendiente no ve Registrar entrega y ve el mensaje de produccion completa', function () {
    $admin = dist_crearAdmin('Admin RET26 QA');
    Auth::guard('admin')->setUser($admin);

    $roleTaller = Role::factory()->create([
        'name' => 'TALLER CONFECCION',
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.reportar_taller'],
    ]);
    $usuarioTaller = Admin::factory()->create(['role_id' => $roleTaller->id]);

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller(['usuario_id' => $usuarioTaller->id]);

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 20]]]],
    ])->first();

    app(OrdenConfeccionService::class)->reportarTaller($orden, [
        'detalles' => [['id' => $orden->detalles->first()->id, 'cantidad' => 20]],
    ]);

    $response = $this->actingAs($usuarioTaller, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.show', $orden->id));

    $response->assertOk();
    $response->assertDontSee('Entrega actual', false);
    $response->assertSee('Produccion reportada completamente.', false);
    $response->assertSee('Pendiente de recepcion por Bodega', false);
});

test('RET-27 - bodega ve la recepcion cuando hay un retorno pendiente y NO la ve cuando no queda ninguno', function () {
    $roleBodega = Role::factory()->create([
        'name' => 'BODEGA',
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.recibir_bodega'],
    ]);
    $usuarioBodega = Admin::factory()->create(['role_id' => $roleBodega->id]);

    Auth::guard('admin')->setUser(dist_crearAdmin('Admin RET27 QA'));

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 20]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);
    $retorno = $service->reportarTaller($orden, ['detalles' => [['id' => $orden->detalles->first()->id, 'cantidad' => 20]]]);

    // Con el retorno pendiente, Bodega SI ve el formulario de recepcion.
    $conPendiente = $this->actingAs($usuarioBodega, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.show', $orden->id));
    $conPendiente->assertOk();
    $conPendiente->assertSee('Recepcion fisica de bodega', false);
    $conPendiente->assertSee($retorno->codigo, false);

    // Bodega recibe el retorno: ya no debe quedar ningun formulario de
    // recepcion pendiente que mostrar (no hay ningun otro retorno).
    $service->recibirBodega($retorno, [
        'detalles' => [['id' => $retorno->detalles->first()->id, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0]],
    ]);

    $sinPendiente = $this->actingAs($usuarioBodega, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.show', $orden->id));
    $sinPendiente->assertOk();
    $sinPendiente->assertDontSee('Recepcion fisica de bodega', false);
});

test('RET-28 - una orden Cerrada no muestra ninguna accion mutable aunque el usuario tenga permisos', function () {
    $roleBodega = Role::factory()->create([
        'name' => 'BODEGA',
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.recibir_bodega'],
    ]);
    $usuarioBodega = Admin::factory()->create(['role_id' => $roleBodega->id]);

    Auth::guard('admin')->setUser(dist_crearAdmin('Admin RET28 QA'));

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 20]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);
    $retorno = $service->reportarTaller($orden, ['detalles' => [['id' => $orden->detalles->first()->id, 'cantidad' => 20]]]);
    $service->recibirBodega($retorno, [
        'detalles' => [['id' => $retorno->detalles->first()->id, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0]],
    ]);

    expect($orden->fresh()->estado)->toBe(EstadoOrdenConfeccion::Cerrada->value);

    $response = $this->actingAs($usuarioBodega, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.show', $orden->id));

    $response->assertOk();
    $response->assertDontSee('Entrega actual', false);
    $response->assertDontSee('Recepcion fisica de bodega', false);
    $response->assertDontSee('Editar orden', false);
    // Si y solo si se auditoria (resumen, variantes, historial) sigue visible.
    $response->assertSee('Produccion por variante', false);
    $response->assertSee($retorno->codigo, false);
});

test('RET-29 - una orden Anulada no muestra ninguna accion mutable, ni siquiera un retorno historico pendiente', function () {
    $roleBodega = Role::factory()->create([
        'name' => 'BODEGA',
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.recibir_bodega'],
    ]);
    $usuarioBodega = Admin::factory()->create(['role_id' => $roleBodega->id]);

    Auth::guard('admin')->setUser(dist_crearAdmin('Admin RET29 QA'));

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0],
    ]);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 20]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);
    $retorno = $service->reportarTaller($orden, ['detalles' => [['id' => $orden->detalles->first()->id, 'cantidad' => 10]]]);

    // Simula (via DB, igual que RET-18/RET-23) el escenario exacto que pide
    // el enunciado: orden anulada con un retorno historico aun pendiente de
    // bodega. cancel() de negocio no permite llegar aqui normalmente porque
    // solo anula ordenes Activa; esto prueba especificamente que la VIEW
    // (no solo el Service) deja de exponer la recepcion como mutable.
    DB::table('bylopez_cc_ordenes_confeccion')->where('id', $orden->id)->update(['estado' => EstadoOrdenConfeccion::Anulada->value]);

    $response = $this->actingAs($usuarioBodega, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.show', $orden->id));

    $response->assertOk();
    $response->assertDontSee('Recepcion fisica de bodega', false);
    $response->assertDontSee('Entrega actual', false);
    // El historico sigue siendo visible para auditoria.
    $response->assertSee($retorno->codigo, false);
});

test('UI Distribuir Corte - la fila expone color_hex del swatch, sin miniatura, con KPIs y autocompletado de cantidad al elegir taller', function () {
    $admin = dist_crearAdmin('Admin Visual Distribuir '.uniqid());
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 70, 'cantidad_defectuosa' => 0],
    ]);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.create', $ordenCorte->id));
    $response->assertOk();

    // El controlador entrega la clave color_hex (null si el producto no tiene color con swatch).
    expect($response->viewData('filas')->first())->toHaveKey('color_hex');

    $hex = app(VarianteConsultaService::class)->hexPorColor();
    expect($hex)->each->toMatch('/^#[0-9a-fA-F]{3,8}$/');

    // Solo la plantilla del componente (el layout admin trae sus propios <img>).
    $html = $response->getContent();
    $inicio = strpos($html, 'id="v-distribuir-confeccion-template"');
    $plantilla = substr($html, $inicio, strpos($html, '</script>', $inicio) - $inicio);

    expect($plantilla)->not->toContain('<img');
    expect($html)->toContain('data-testid="kpis-distribucion"')
        ->toContain('Apto total')->toContain('Por distribuir')->toContain('Variantes')
        ->toContain('data-testid="color-variante"')
        ->toContain('alCambiarTaller(fila)')          // autocompleta la cantidad al elegir taller
        ->toContain('data-testid="btn-revisar-distribucion"')
        ->toContain('estado-label=');
});

test('UI Detalle Orden de Confeccion - pestanas, contadores, produccion con color/talla/SKU y movimientos con la visual aprobada', function () {
    $admin = dist_crearAdmin('Admin Tabs OC '.uniqid());
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $taller = dist_crearTaller();

    $ordenCorte = dist_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ]);

    $service = app(OrdenConfeccionService::class);
    $orden = $service->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 40]]]],
    ])->first();
    $detalleId = $orden->detalles->first()->id;

    $ent1 = $service->reportarTaller($orden, ['detalles' => [['id' => $detalleId, 'cantidad' => 30]]]);
    $service->recibirBodega($ent1, ['detalles' => [['id' => $ent1->detalles->first()->id, 'cantidad_recibida' => 28, 'cantidad_defectuosa' => 1, 'cantidad_faltante' => 1, 'observacion_bodega' => 'Conteo fisico.']]]);
    $service->reportarTaller($orden->fresh(), ['detalles' => [['id' => $detalleId, 'cantidad' => 5]]]);   // ENT-002 pendiente de recepcion (quedan 5 sin reportar)

    $html = $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.show', $orden->id))
        ->assertOk()->getContent();

    // Pestanas y contadores (Produccion 1 variante, Recepciones 1 pendiente, Historial 2, Movimientos 1).
    expect($html)->toContain('data-tab="produccion"')->toContain('data-tab="recepciones"')
        ->toContain('data-tab="historial"')->toContain('data-tab="movimientos"')
        ->toContain('data-tab-panel="produccion"')->toContain('data-tab-panel="movimientos"');

    // Produccion abierta por defecto; el resto oculto.
    expect($html)->toMatch('/data-tab-panel="produccion" class="\s*mt-4"/')
        ->toMatch('/data-tab-panel="recepciones" class="hidden/')
        ->toMatch('/data-tab-panel="historial" class="hidden/')
        ->toMatch('/data-tab-panel="movimientos" class="hidden/');

    // Columnas nuevas de produccion y resumen de estado.
    expect($html)->toContain('Producto / variante')->toContain('Color / Talla')->toContain('>SKU<')
        ->toContain('Resumen de estado')->toContain('Aun hay 1 variante con cantidades pendientes');

    // Recepciones: bandeja, entrega en revision, tarjetas de variante, resumen y CTA; faltantes solo lectura (regla existente).
    expect($html)->toContain('Entregas pendientes de recepcion')->toContain('Trabajo pendiente')
        ->toContain('Entrega en revision')->toContain('data-recepcion-resumen')->toContain('Resumen de recepcion')
        ->toContain('data-campo-faltante')->toContain('readonly')->toContain('Confirmar recepcion');

    // Movimientos: misma visual aprobada (plantilla de grid del listado y stock anterior -> entrada -> nueva).
    expect($html)->toContain('grid-cols-[1.05fr_1.15fr_2fr_2.5fr_1.45fr_1fr_0.9fr_0.45fr]')
        ->toContain('Anterior')->toContain('Entrada')->toContain('Nueva')->toContain('+28');

    // ?tab= abre esa pestana.
    $conTab = $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.show', [$orden->id, 'tab' => 'historial']))
        ->assertOk()->getContent();

    expect($conTab)->toMatch('/data-tab-panel="historial" class="\s*mt-4"/')
        ->toMatch('/data-tab-panel="produccion" class="hidden/');
});
