<?php

use ByLopez\CorteConfeccion\DataGrids\InventarioMovimientoDataGrid;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Enums\TipoInventarioMovimiento;
use ByLopez\CorteConfeccion\Models\InventarioMovimiento;
use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Services\CompraTelaService;
use ByLopez\CorteConfeccion\Services\InventarioMovimientoService;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenCorteService;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use ByLopez\CorteConfeccion\Services\RecepcionCorteService;
use ByLopez\CorteConfeccion\Services\TallerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Core\Menu;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
|--------------------------------------------------------------------------
| Fixtures autosuficientes para "Movimientos de inventario" (vista/DataGrid/
| ACL). Prefijo "imv_" para no chocar con helpers de otros archivos de test
| (mismo patron ya usado con "dist_" en DistribucionConfeccionTest.php y
| "tv_" en TallerVistaDetalleTest.php). No se toca InventarioMovimientoService
| ni recibirBodega(): estos tests solo verifican lo que YA generan.
|--------------------------------------------------------------------------
*/

function imv_crearAdmin(string $nombreRol, array $permissions = [], string $permissionType = 'custom'): Admin
{
    $role = Role::factory()->create([
        'name' => $nombreRol,
        'permission_type' => $permissionType,
        'permissions' => $permissions,
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function imv_crearTaller(array $overrides = []): Taller
{
    $usuarioId = array_key_exists('usuario_id', $overrides)
        ? $overrides['usuario_id']
        : imv_crearAdmin('TALLER CONFECCION', ['corte_confeccion.ordenes_confeccion'])->id;

    return app(TallerService::class)->create(array_merge([
        'nombre' => 'Taller Mov QA '.uniqid(),
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
 * Copia autosuficiente del helper equivalente en DistribucionConfeccionTest.php:
 * crea una OrdenCorte real, entregada, cortada y recibida en bodega, lista
 * para distribuir hacia confeccion.
 */
function imv_crearOrdenCorteRecibida(array $productosCantidades)
{
    Auth::guard('admin')->setUser(imv_crearAdmin('Creador Mov QA '.uniqid(), [], 'all'));

    $atributoTipoTela = Attribute::firstWhere('code', 'tipo_tela')
        ?? Attribute::factory()->create(['code' => 'tipo_tela', 'type' => 'select', 'admin_name' => 'Tipo de tela']);
    $option = AttributeOption::factory()->for($atributoTipoTela, 'attribute')->create(['admin_name' => 'Tela Mov QA '.uniqid()]);
    $color = cc_test_color_option();

    $proveedor = app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor Mov QA '.uniqid(), 'nit' => (string) random_int(900000000, 999999999),
        'telefono' => '3000000000', 'contacto' => 'QA', 'direccion' => 'Calle QA',
        'tipo_tela_ids' => [$option->id],
        'referencias' => [['tipo_tela_id' => $option->id, 'color_option_id' => $color->id, 'color' => 'Blanco', 'referencia' => 'QA-MOV', 'gramaje' => '180', 'valor_kilo_referencia' => 20000, 'estado' => 'activo']],
        'estado' => 'activo', 'observacion' => null,
    ]);

    $compra = app(CompraTelaService::class)->create([
        'codigo' => 'CT-MOV-'.uniqid(), 'proveedor_id' => $proveedor->id, 'numero_factura' => null,
        'fecha_compra' => now()->toDateString(), 'descuento' => 0, 'impuesto' => 0, 'observacion' => null,
        'rollos' => [['codigo' => 'R-MOV-'.uniqid(), 'tipo_tela_id' => $option->id, 'referencia_tela_id' => $proveedor->referenciasTela()->first()->id, 'color' => 'Blanco', 'peso_inicial' => 100, 'valor_kilo' => 20000]],
    ]);
    $rollo = $compra->rollos()->first();
    $cortador = imv_crearAdmin('CORTADOR', [], 'all');

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

    Auth::guard('admin')->setUser(imv_crearAdmin('Cortador Reporte Mov QA '.uniqid(), [], 'all'));
    $orden = $ordenCorteService->reportarResultado($orden, [
        'resultados' => $orden->detalles->values()->map(fn ($detalleOrden, $i) => [
            'detalle_id' => $detalleOrden->id,
            'cantidad_real_cortada' => $productosCantidades[$i]['cantidad_recibida'] + $productosCantidades[$i]['cantidad_defectuosa'],
        ])->all(),
    ]);

    Auth::guard('admin')->setUser(imv_crearAdmin('Bodega Reporte Mov QA '.uniqid(), [], 'all'));
    app(RecepcionCorteService::class)->create($orden, [
        'fecha_recepcion' => now()->toDateString(),
        'merma_total' => 0,
        'recibido_por' => 'Bodega Mov QA',
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

function imv_sembrarStockBagisto(Product $producto, int $cantidad): object
{
    $source = app(InventarioMovimientoService::class)->resolverFuenteInventario();
    $channelId = core()->getDefaultChannel()->id;

    DB::table('product_inventories')->updateOrInsert(
        ['product_id' => $producto->id, 'inventory_source_id' => $source->id, 'vendor_id' => 0],
        ['qty' => $cantidad]
    );

    DB::table('product_inventory_indices')->updateOrInsert(
        ['product_id' => $producto->id, 'channel_id' => $channelId],
        ['qty' => $cantidad, 'created_at' => now(), 'updated_at' => now()]
    );

    return $source;
}

function imv_stockFisico(Product $producto, int $sourceId): int
{
    return (int) DB::table('product_inventories')
        ->where('product_id', $producto->id)
        ->where('inventory_source_id', $sourceId)
        ->where('vendor_id', 0)
        ->value('qty');
}

function imv_stockIndexado(Product $producto): int
{
    return (int) DB::table('product_inventory_indices')
        ->where('product_id', $producto->id)
        ->where('channel_id', core()->getDefaultChannel()->id)
        ->value('qty');
}

/*
|--------------------------------------------------------------------------
| IM-01: el escenario integral obligatorio -- 15 -> +68 -> 83, con todas
| las referencias (orden, entrega ENT-001, detalle, usuario).
|--------------------------------------------------------------------------
*/

test('IM-01 - la recepcion de bodega ya genera el movimiento entrada_confeccion: stock 15 -> +68 -> 83', function () {
    $bodega = imv_crearAdmin('Bodega Mov QA '.uniqid(), [], 'all');
    Auth::guard('admin')->setUser($bodega);

    $producto = Product::factory()->simple()->create();
    $source = imv_sembrarStockBagisto($producto, 15);
    $taller = imv_crearTaller();

    $ordenCorte = imv_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 70, 'cantidad_defectuosa' => 0],
    ]);

    Auth::guard('admin')->setUser($bodega);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 70]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);
    $detalleId = $orden->detalles->first()->id;

    $ent001 = $service->reportarTaller($orden, ['detalles' => [['id' => $detalleId, 'cantidad' => 70]]]);
    $retornoDetalleId = $ent001->detalles->first()->id;

    expect(imv_stockFisico($producto, $source->id))->toBe(15);

    $ent001 = $service->recibirBodega($ent001, [
        'detalles' => [[
            'id' => $retornoDetalleId,
            'cantidad_recibida' => 68,
            'cantidad_defectuosa' => 1,
            'cantidad_faltante' => 1,
            'observacion_bodega' => 'QA integral 15+68=83',
        ]],
    ]);

    // 2) Stock anterior, 3) cantidad movimiento, 4) stock nuevo.
    expect(imv_stockFisico($producto, $source->id))->toBe(83)
        ->and(imv_stockIndexado($producto))->toBe(83);

    $movimiento = InventarioMovimiento::query()
        ->where('retorno_detalle_id', $retornoDetalleId)
        ->where('tipo_movimiento', TipoInventarioMovimiento::EntradaConfeccion->value)
        ->first();

    // 1) el movimiento existe, 5-8) todas sus referencias son correctas.
    expect($movimiento)->not->toBeNull()
        ->and($movimiento->cantidad_anterior)->toBe(15)
        ->and($movimiento->cantidad_movimiento)->toBe(68)
        ->and($movimiento->cantidad_nueva)->toBe(83)
        ->and($movimiento->orden_confeccion_id)->toBe($orden->id)
        ->and($movimiento->retorno_id)->toBe($ent001->id)
        ->and($movimiento->retorno_detalle_id)->toBe($retornoDetalleId)
        ->and($movimiento->admin_id)->toBe($bodega->id)
        ->and($movimiento->nombre_usuario_snapshot)->toBe($bodega->name)
        ->and($movimiento->inventory_source_id)->toBe($source->id);

    // Debe aparecer en la vista general (DataGrid) con los mismos valores.
    $filaGeneral = app(InventarioMovimientoDataGrid::class)->prepareQueryBuilder()
        ->where('im.id', $movimiento->id)->first();

    expect($filaGeneral)->not->toBeNull()
        ->and((int) $filaGeneral->cantidad_anterior)->toBe(15)
        ->and((int) $filaGeneral->cantidad_movimiento)->toBe(68)
        ->and((int) $filaGeneral->cantidad_nueva)->toBe(83)
        ->and($filaGeneral->orden_confeccion_codigo)->toBe($orden->codigo)
        ->and($filaGeneral->retorno_codigo)->toBe($ent001->codigo);

    // Y en "Orden de confeccion > Movimientos de inventario".
    $ordenFresca = $orden->fresh(['movimientosInventario']);
    expect($ordenFresca->movimientosInventario->pluck('id'))->toContain($movimiento->id);
});

/*
|--------------------------------------------------------------------------
| IM-02: idempotencia -- re-recibir el mismo ENT-001 no duplica el
| movimiento ni vuelve a incrementar el stock (el Service ya rechaza el
| retorno por estado antes de llegar a InventarioMovimientoService, y este
| ultimo ademas tiene su propio guard + restriccion unica en BD).
|--------------------------------------------------------------------------
*/

test('IM-02 - una segunda recepcion del mismo ENT-001 no duplica el movimiento ni el stock', function () {
    $bodega = imv_crearAdmin('Bodega Mov QA '.uniqid(), [], 'all');
    Auth::guard('admin')->setUser($bodega);

    $producto = Product::factory()->simple()->create();
    $source = imv_sembrarStockBagisto($producto, 15);
    $taller = imv_crearTaller();

    $ordenCorte = imv_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 70, 'cantidad_defectuosa' => 0],
    ]);

    Auth::guard('admin')->setUser($bodega);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 70]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);
    $detalleId = $orden->detalles->first()->id;

    $ent001 = $service->reportarTaller($orden, ['detalles' => [['id' => $detalleId, 'cantidad' => 70]]]);
    $retornoDetalleId = $ent001->detalles->first()->id;

    $payloadRecepcion = [
        'detalles' => [[
            'id' => $retornoDetalleId,
            'cantidad_recibida' => 68,
            'cantidad_defectuosa' => 1,
            'cantidad_faltante' => 1,
            'observacion_bodega' => 'QA idempotencia',
        ]],
    ];

    $ent001 = $service->recibirBodega($ent001, $payloadRecepcion);
    expect(imv_stockFisico($producto, $source->id))->toBe(83);

    // Reintentar sobre el mismo retorno ya recibido: el Service lo rechaza
    // por estado (ya no esta pendiente_bodega) antes de tocar inventario.
    expect(fn () => $service->recibirBodega($ent001->fresh(), $payloadRecepcion))
        ->toThrow(ValidationException::class);

    expect(imv_stockFisico($producto, $source->id))->toBe(83)
        ->and(imv_stockIndexado($producto))->toBe(83)
        ->and(InventarioMovimiento::query()->where('retorno_detalle_id', $retornoDetalleId)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| IM-03: recepcion acumulativa por entregas parciales -- ENT-001 y ENT-002
| generan DOS movimientos independientes y trazables, nunca uno fusionado.
|--------------------------------------------------------------------------
*/

test('IM-03 - ENT-001 (+68) y ENT-002 (+20) quedan como dos movimientos independientes, nunca fusionados', function () {
    $bodega = imv_crearAdmin('Bodega Mov QA '.uniqid(), [], 'all');
    Auth::guard('admin')->setUser($bodega);

    $producto = Product::factory()->simple()->create();
    $source = imv_sembrarStockBagisto($producto, 15);
    $taller = imv_crearTaller();

    $ordenCorte = imv_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 90, 'cantidad_defectuosa' => 0],
    ]);

    Auth::guard('admin')->setUser($bodega);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 90]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);
    $detalleId = $orden->detalles->first()->id;

    $ent001 = $service->reportarTaller($orden, ['detalles' => [['id' => $detalleId, 'cantidad' => 70]]]);
    $ent001 = $service->recibirBodega($ent001, [
        'detalles' => [['id' => $ent001->detalles->first()->id, 'cantidad_recibida' => 68, 'cantidad_defectuosa' => 1, 'cantidad_faltante' => 1, 'observacion_bodega' => 'QA ENT-001']],
    ]);

    expect(imv_stockFisico($producto, $source->id))->toBe(83);

    $ent002 = $service->reportarTaller($orden->fresh(), ['detalles' => [['id' => $detalleId, 'cantidad' => 20]]]);
    $ent002 = $service->recibirBodega($ent002, [
        'detalles' => [['id' => $ent002->detalles->first()->id, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0, 'observacion_bodega' => null]],
    ]);

    expect(imv_stockFisico($producto, $source->id))->toBe(103)
        ->and(imv_stockIndexado($producto))->toBe(103);

    $movimientos = InventarioMovimiento::query()
        ->where('orden_confeccion_id', $orden->id)
        ->orderBy('id')
        ->get();

    expect($movimientos)->toHaveCount(2)
        ->and($movimientos[0]->retorno_id)->toBe($ent001->id)
        ->and($movimientos[0]->cantidad_movimiento)->toBe(68)
        ->and($movimientos[0]->cantidad_anterior)->toBe(15)
        ->and($movimientos[0]->cantidad_nueva)->toBe(83)
        ->and($movimientos[1]->retorno_id)->toBe($ent002->id)
        ->and($movimientos[1]->cantidad_movimiento)->toBe(20)
        ->and($movimientos[1]->cantidad_anterior)->toBe(83)
        ->and($movimientos[1]->cantidad_nueva)->toBe(103);

    // Nunca un movimiento fusionado 68+20=88 en una sola fila.
    expect($movimientos->pluck('cantidad_movimiento')->all())->not->toContain(88);

    // Ambos son visibles desde la orden.
    $idsEnOrden = $orden->fresh(['movimientosInventario'])->movimientosInventario->pluck('id');
    expect($idsEnOrden)->toContain($movimientos[0]->id)
        ->and($idsEnOrden)->toContain($movimientos[1]->id);
});

/*
|--------------------------------------------------------------------------
| IM-04: Buenas = 0 no genera movimiento ni incrementa stock (comportamiento
| ya existente en InventarioMovimientoService::registrarEntradaConfeccion,
| linea `if ($cantidad <= 0) { return null; }`; aqui solo se confirma).
|--------------------------------------------------------------------------
*/

test('IM-04 - una recepcion con Buenas=0 no genera movimiento ni incrementa el stock', function () {
    $bodega = imv_crearAdmin('Bodega Mov QA '.uniqid(), [], 'all');
    Auth::guard('admin')->setUser($bodega);

    $producto = Product::factory()->simple()->create();
    $source = imv_sembrarStockBagisto($producto, 15);
    $taller = imv_crearTaller();

    $ordenCorte = imv_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 5, 'cantidad_defectuosa' => 0],
    ]);

    Auth::guard('admin')->setUser($bodega);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 5]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);
    $detalleId = $orden->detalles->first()->id;

    $retorno = $service->reportarTaller($orden, ['detalles' => [['id' => $detalleId, 'cantidad' => 5]]]);
    $retornoDetalleId = $retorno->detalles->first()->id;

    $service->recibirBodega($retorno, [
        'detalles' => [[
            'id' => $retornoDetalleId,
            'cantidad_recibida' => 0,
            'cantidad_defectuosa' => 5,
            'cantidad_faltante' => 0,
            'observacion_bodega' => 'QA todo defectuoso',
        ]],
    ]);

    expect(imv_stockFisico($producto, $source->id))->toBe(15)
        ->and(imv_stockIndexado($producto))->toBe(15)
        ->and(InventarioMovimiento::query()->where('retorno_detalle_id', $retornoDetalleId)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| IM-05/IM-06: ACL de la nueva vista -- Admin y Bodega acceden, Taller no
| (ni por URL directa ni por el menu), backend protegido independiente del
| menu.
|--------------------------------------------------------------------------
*/

test('IM-05 - Admin y Bodega pueden acceder a index y show de Movimientos de inventario', function () {
    $bodega = imv_crearAdmin('Bodega Mov QA '.uniqid(), [], 'all');
    Auth::guard('admin')->setUser($bodega);

    $producto = Product::factory()->simple()->create();
    imv_sembrarStockBagisto($producto, 15);
    $taller = imv_crearTaller();

    $ordenCorte = imv_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0],
    ]);

    Auth::guard('admin')->setUser($bodega);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 10]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);
    $retorno = $service->reportarTaller($orden, ['detalles' => [['id' => $orden->detalles->first()->id, 'cantidad' => 10]]]);
    $retorno = $service->recibirBodega($retorno, [
        'detalles' => [['id' => $retorno->detalles->first()->id, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0, 'observacion_bodega' => null]],
    ]);

    $movimiento = InventarioMovimiento::query()->where('retorno_id', $retorno->id)->first();

    $admin = imv_crearAdmin('Admin Mov QA '.uniqid(), [], 'all');
    $bodegaLector = imv_crearAdmin('BODEGA', ['corte_confeccion', 'corte_confeccion.inventario_movimientos']);

    foreach ([$admin, $bodegaLector] as $persona) {
        $this->actingAs($persona, 'admin')
            ->get(route('admin.corte_confeccion.inventario_movimientos.index'))
            ->assertOk();

        $this->actingAs($persona, 'admin')
            ->get(route('admin.corte_confeccion.inventario_movimientos.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();

        $this->actingAs($persona, 'admin')
            ->get(route('admin.corte_confeccion.inventario_movimientos.show', $movimiento->id))
            ->assertOk();

        $this->actingAs($persona, 'admin')
            ->get(route('admin.corte_confeccion.inventario_movimientos.show', $movimiento->id), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('data-movimiento-detalle', false)
            ->assertSee('Stock anterior')
            ->assertSee('Stock nuevo')
            ->assertSee($orden->codigo)
            ->assertSee($retorno->codigo)
            ->assertDontSee('<html', false)
            ->assertDontSee('Volver al listado');
    }
});

test('IM-06 - Taller (confeccion/cortador) es rechazado en Movimientos de inventario y no ve la entrada en el menu', function () {
    $bodega = imv_crearAdmin('Bodega Mov QA '.uniqid(), [], 'all');
    Auth::guard('admin')->setUser($bodega);

    $producto = Product::factory()->simple()->create();
    imv_sembrarStockBagisto($producto, 15);
    $tallerUsuario = imv_crearAdmin('TALLER CONFECCION', ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.reportar_taller']);
    $taller = imv_crearTaller(['usuario_id' => $tallerUsuario->id]);

    $ordenCorte = imv_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0],
    ]);

    Auth::guard('admin')->setUser($bodega);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 10]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);
    $retorno = $service->reportarTaller($orden, ['detalles' => [['id' => $orden->detalles->first()->id, 'cantidad' => 10]]]);
    $retorno = $service->recibirBodega($retorno, [
        'detalles' => [['id' => $retorno->detalles->first()->id, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0, 'observacion_bodega' => null]],
    ]);
    $movimiento = InventarioMovimiento::query()->where('retorno_id', $retorno->id)->first();

    // Sin el permiso 'corte_confeccion.inventario_movimientos', igual que
    // cualquier rol 'cortador' puro.
    $cortador = imv_crearAdmin('CORTADOR', ['corte_confeccion']);

    // El middleware 'admin' (Webkul\User\Http\Middleware\Bouncer, packages/Webkul,
    // no se toca) ya intercepta cualquier ruta mapeada en acl.php cuyo permiso
    // no tenga el rol, y responde 401 antes de llegar al controlador -- mismo
    // codigo que el resto de rutas ACL de este paquete (ver SEC01PermisosDistribucionTest,
    // DistribucionConfeccionTest). El abort_if(403) del controlador es una
    // segunda capa defensiva que en la practica queda inalcanzable para este caso.
    foreach ([$tallerUsuario, $cortador] as $persona) {
        $this->actingAs($persona, 'admin')
            ->get(route('admin.corte_confeccion.inventario_movimientos.index'))
            ->assertStatus(401);

        $this->actingAs($persona, 'admin')
            ->get(route('admin.corte_confeccion.inventario_movimientos.show', $movimiento->id))
            ->assertStatus(401);

        $this->actingAs($persona, 'admin')
            ->get(route('admin.corte_confeccion.inventario_movimientos.show', $movimiento->id), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    Auth::guard('admin')->setUser($tallerUsuario);
    $menu = app(Menu::class)->getItems('admin');
    $corteMenu = $menu->first(fn ($item) => $item->key === 'corte_confeccion');

    expect($corteMenu)->not->toBeNull()
        ->and($corteMenu->getChildren()->pluck('key')->all())->not->toContain('corte_confeccion.inventario_movimientos');
});

/*
|--------------------------------------------------------------------------
| IM-07: filtros/busqueda basicos del DataGrid -- por SKU, por codigo de
| Orden de Confeccion y por codigo ENT (punto 12 del requerimiento).
|--------------------------------------------------------------------------
*/

test('IM-07 - la busqueda del DataGrid encuentra el movimiento por SKU, por codigo de orden y por codigo ENT', function () {
    $bodega = imv_crearAdmin('Bodega Mov QA '.uniqid(), [], 'all');
    Auth::guard('admin')->setUser($bodega);

    $producto = Product::factory()->simple()->create();
    imv_sembrarStockBagisto($producto, 15);
    $taller = imv_crearTaller();

    $ordenCorte = imv_crearOrdenCorteRecibida([
        ['product' => $producto, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0],
    ]);

    Auth::guard('admin')->setUser($bodega);

    $orden = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 10]]]],
    ])->first();

    $service = app(OrdenConfeccionService::class);
    $retorno = $service->reportarTaller($orden, ['detalles' => [['id' => $orden->detalles->first()->id, 'cantidad' => 10]]]);
    $retorno = $service->recibirBodega($retorno, [
        'detalles' => [['id' => $retorno->detalles->first()->id, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0, 'observacion_bodega' => null]],
    ]);

    $grid = app(InventarioMovimientoDataGrid::class);

    expect($grid->prepareQueryBuilder()->where('p.sku', $producto->sku)->exists())->toBeTrue()
        ->and($grid->prepareQueryBuilder()->where('oc.codigo', $orden->codigo)->exists())->toBeTrue()
        ->and($grid->prepareQueryBuilder()->where('r.codigo', $retorno->codigo)->exists())->toBeTrue();
});
