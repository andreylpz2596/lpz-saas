<?php

use ByLopez\CorteConfeccion\Enums\TipoInventarioMovimiento;
use ByLopez\CorteConfeccion\Models\InventarioMovimiento;
use ByLopez\CorteConfeccion\Services\InventarioMovimientoService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

function iam_admin(string $roleName, string $permissionType = 'custom', array $permissions = []): Admin
{
    $role = Role::factory()->create([
        'name' => $roleName,
        'permission_type' => $permissionType,
        'permissions' => $permissions,
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function iam_sembrarStock(Product $producto, int $cantidad, bool $crearIndice = true): object
{
    $source = app(InventarioMovimientoService::class)->resolverFuenteInventario();

    DB::table('product_inventories')->updateOrInsert(
        [
            'product_id' => $producto->id,
            'inventory_source_id' => $source->id,
            'vendor_id' => 0,
        ],
        ['qty' => $cantidad]
    );

    if ($crearIndice) {
        DB::table('product_inventory_indices')->updateOrInsert(
            [
                'product_id' => $producto->id,
                'channel_id' => core()->getDefaultChannel()->id,
            ],
            [
                'qty' => $cantidad,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    return $source;
}

function iam_stockFisico(Product $producto, int $sourceId): int
{
    return (int) DB::table('product_inventories')
        ->where('product_id', $producto->id)
        ->where('inventory_source_id', $sourceId)
        ->where('vendor_id', 0)
        ->value('qty');
}

function iam_stockIndexado(Product $producto): int
{
    return (int) DB::table('product_inventory_indices')
        ->where('product_id', $producto->id)
        ->where('channel_id', core()->getDefaultChannel()->id)
        ->value('qty');
}

function iam_postAjuste($test, Admin $admin, array $payload)
{
    return $test->actingAs($admin, 'admin')->postJson(
        route('admin.corte_confeccion.inventario_movimientos.ajuste_manual'),
        $payload
    );
}

test('ADMIN puede registrar ajuste manual de entrada y deja movimiento trazable', function () {
    $admin = iam_admin('Administrador', 'all');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $source = iam_sembrarStock($producto, 83);

    $response = iam_postAjuste($this, $admin, [
        'product_id' => $producto->id,
        'tipo' => 'entrada',
        'cantidad' => 10,
        'motivo' => 'Diferencia conteo fisico',
        'idempotency_key' => 'iam-entrada-'.uniqid(),
    ]);

    $response->assertOk()
        ->assertJsonPath('movimiento.cantidad_anterior', 83)
        ->assertJsonPath('movimiento.cantidad_movimiento', 10)
        ->assertJsonPath('movimiento.cantidad_nueva', 93);

    $movimiento = InventarioMovimiento::query()->latest('id')->firstOrFail();

    expect($producto->type)->toBe('simple')
        ->and(iam_stockFisico($producto, $source->id))->toBe(93)
        ->and(iam_stockIndexado($producto))->toBe(93)
        ->and($movimiento->product_id)->toBe($producto->id)
        ->and($movimiento->inventory_source_id)->toBe($source->id)
        ->and($movimiento->tipo_movimiento)->toBe(TipoInventarioMovimiento::AjusteManual->value)
        ->and($movimiento->cantidad_anterior)->toBe(83)
        ->and($movimiento->cantidad_movimiento)->toBe(10)
        ->and($movimiento->cantidad_nueva)->toBe(93)
        ->and($movimiento->admin_id)->toBe($admin->id)
        ->and($movimiento->nombre_usuario_snapshot)->toBe($admin->name)
        ->and($movimiento->observacion)->toBe('Diferencia conteo fisico')
        ->and($movimiento->created_at)->not->toBeNull();
});

test('ADMIN puede registrar ajuste manual de salida', function () {
    $admin = iam_admin('Administrador', 'all');
    $producto = Product::factory()->simple()->create();
    $source = iam_sembrarStock($producto, 83);

    iam_postAjuste($this, $admin, [
        'product_id' => $producto->id,
        'tipo' => 'salida',
        'cantidad' => 5,
        'motivo' => 'Diferencia conteo fisico',
        'idempotency_key' => 'iam-salida-'.uniqid(),
    ])->assertOk();

    $movimiento = InventarioMovimiento::query()->latest('id')->firstOrFail();

    expect(iam_stockFisico($producto, $source->id))->toBe(78)
        ->and(iam_stockIndexado($producto))->toBe(78)
        ->and($movimiento->cantidad_anterior)->toBe(83)
        ->and($movimiento->cantidad_movimiento)->toBe(-5)
        ->and($movimiento->cantidad_nueva)->toBe(78);
});

test('no permite ajuste manual que deje stock negativo', function () {
    $admin = iam_admin('Administrador', 'all');
    $producto = Product::factory()->simple()->create();
    $source = iam_sembrarStock($producto, 3);

    iam_postAjuste($this, $admin, [
        'product_id' => $producto->id,
        'tipo' => 'salida',
        'cantidad' => 5,
        'motivo' => 'Diferencia conteo fisico',
        'idempotency_key' => 'iam-negativo-'.uniqid(),
    ])->assertStatus(422);

    expect(iam_stockFisico($producto, $source->id))->toBe(3)
        ->and(iam_stockIndexado($producto))->toBe(3)
        ->and(InventarioMovimiento::query()->where('product_id', $producto->id)->count())->toBe(0);
});

test('no permite motivo vacio ni cantidad cero', function () {
    $admin = iam_admin('Administrador', 'all');
    $producto = Product::factory()->simple()->create();
    $source = iam_sembrarStock($producto, 83);

    iam_postAjuste($this, $admin, [
        'product_id' => $producto->id,
        'tipo' => 'entrada',
        'cantidad' => 0,
        'motivo' => '',
        'idempotency_key' => 'iam-invalid-'.uniqid(),
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['cantidad', 'motivo']);

    expect(iam_stockFisico($producto, $source->id))->toBe(83)
        ->and(InventarioMovimiento::query()->where('product_id', $producto->id)->count())->toBe(0);
});

test('BODEGA no puede registrar ajuste manual', function () {
    $bodega = iam_admin('BODEGA', 'custom', ['corte_confeccion', 'corte_confeccion.inventario_movimientos']);
    $producto = Product::factory()->simple()->create();
    $source = iam_sembrarStock($producto, 83);

    iam_postAjuste($this, $bodega, [
        'product_id' => $producto->id,
        'tipo' => 'entrada',
        'cantidad' => 10,
        'motivo' => 'Diferencia conteo fisico',
        'idempotency_key' => 'iam-bodega-'.uniqid(),
    ])->assertForbidden();

    expect(iam_stockFisico($producto, $source->id))->toBe(83)
        ->and(InventarioMovimiento::query()->where('product_id', $producto->id)->count())->toBe(0);
});

test('TALLER no puede registrar ajuste manual', function () {
    $taller = iam_admin('TALLER CONFECCION', 'custom', ['corte_confeccion', 'corte_confeccion.inventario_movimientos']);
    $producto = Product::factory()->simple()->create();
    $source = iam_sembrarStock($producto, 83);

    iam_postAjuste($this, $taller, [
        'product_id' => $producto->id,
        'tipo' => 'entrada',
        'cantidad' => 10,
        'motivo' => 'Diferencia conteo fisico',
        'idempotency_key' => 'iam-taller-'.uniqid(),
    ])->assertForbidden();

    expect(iam_stockFisico($producto, $source->id))->toBe(83)
        ->and(InventarioMovimiento::query()->where('product_id', $producto->id)->count())->toBe(0);
});

test('reintento con misma llave de idempotencia no aplica dos veces', function () {
    $admin = iam_admin('Administrador', 'all');
    $producto = Product::factory()->simple()->create();
    $source = iam_sembrarStock($producto, 83);
    $key = 'iam-idempotente-'.uniqid();
    $payload = [
        'product_id' => $producto->id,
        'tipo' => 'entrada',
        'cantidad' => 10,
        'motivo' => 'Diferencia conteo fisico',
        'idempotency_key' => $key,
    ];

    iam_postAjuste($this, $admin, $payload)->assertOk();
    iam_postAjuste($this, $admin, $payload)->assertOk();

    expect(iam_stockFisico($producto, $source->id))->toBe(93)
        ->and(iam_stockIndexado($producto))->toBe(93)
        ->and(InventarioMovimiento::query()->where('idempotency_key', $key)->count())->toBe(1);
});

test('rollback revierte stock fisico si falla actualizacion del indice', function () {
    $admin = iam_admin('Administrador', 'all');
    $producto = Product::factory()->simple()->create();
    $source = iam_sembrarStock($producto, 83, false);

    iam_postAjuste($this, $admin, [
        'product_id' => $producto->id,
        'tipo' => 'salida',
        'cantidad' => 5,
        'motivo' => 'Diferencia conteo fisico',
        'idempotency_key' => 'iam-rollback-'.uniqid(),
    ])->assertStatus(422);

    expect(iam_stockFisico($producto, $source->id))->toBe(83)
        ->and(iam_stockIndexado($producto))->toBe(0)
        ->and(InventarioMovimiento::query()->where('product_id', $producto->id)->count())->toBe(0);
});

test('rechaza producto configurable padre para ajuste manual', function () {
    $admin = iam_admin('Administrador', 'all');
    $producto = Product::factory()->configurable()->create();
    $source = iam_sembrarStock($producto, 83);

    iam_postAjuste($this, $admin, [
        'product_id' => $producto->id,
        'tipo' => 'entrada',
        'cantidad' => 10,
        'motivo' => 'Diferencia conteo fisico',
        'idempotency_key' => 'iam-configurable-'.uniqid(),
    ])->assertStatus(422);

    expect(iam_stockFisico($producto, $source->id))->toBe(83)
        ->and(InventarioMovimiento::query()->where('product_id', $producto->id)->count())->toBe(0);
});
