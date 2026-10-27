<?php

use ByLopez\CorteConfeccion\DataGrids\RolloDataGrid;
use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use ByLopez\CorteConfeccion\Services\CompraTelaService;
use ByLopez\CorteConfeccion\Services\OrdenCorteService;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

function rollosUxCrearAdmin(string $nombreRol, array $permisos = [], string $permissionType = 'custom'): Admin
{
    $role = Role::factory()->create(['name' => $nombreRol, 'permission_type' => $permissionType, 'permissions' => $permisos]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function rollosUxCrearTresRollos(): array
{
    $atributo = Attribute::firstWhere('code', 'tipo_tela')
        ?? Attribute::factory()->create(['code' => 'tipo_tela', 'type' => 'select']);
    $option = AttributeOption::factory()->for($atributo, 'attribute')->create(['admin_name' => 'Tela QA UX '.uniqid()]);
    $color = cc_test_color_option();

    $proveedor = app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor QA UX '.uniqid(), 'nit' => (string) random_int(900000000, 999999999),
        'telefono' => '3000000000', 'contacto' => 'QA', 'direccion' => 'Calle QA',
        'tipo_tela_ids' => [$option->id],
        'referencias' => [['tipo_tela_id' => $option->id, 'color_option_id' => $color->id, 'color' => 'Blanco', 'referencia' => 'QA-REF', 'gramaje' => '180', 'valor_kilo_referencia' => 20000, 'estado' => 'activo']],
        'estado' => 'activo', 'observacion' => null,
    ]);
    $referencia = $proveedor->referenciasTela()->first();

    $compra = app(CompraTelaService::class)->create([
        'codigo' => 'CT-QA-UX-'.uniqid(), 'proveedor_id' => $proveedor->id, 'numero_factura' => null,
        'fecha_compra' => now()->toDateString(), 'descuento' => 0, 'impuesto' => 0, 'observacion' => null,
        'rollos' => [
            ['codigo' => 'R-QA-UX-A-'.uniqid(), 'tipo_tela_id' => $option->id, 'referencia_tela_id' => $referencia->id, 'color' => 'Blanco', 'peso_inicial' => 20, 'valor_kilo' => 20000],
            ['codigo' => 'R-QA-UX-B-'.uniqid(), 'tipo_tela_id' => $option->id, 'referencia_tela_id' => $referencia->id, 'color' => 'Blanco', 'peso_inicial' => 30, 'valor_kilo' => 20000],
            ['codigo' => 'R-QA-UX-C-'.uniqid(), 'tipo_tela_id' => $option->id, 'referencia_tela_id' => $referencia->id, 'color' => 'Blanco', 'peso_inicial' => 50, 'valor_kilo' => 20000],
        ],
    ]);

    return $compra->rollos()->orderBy('codigo')->get()->all();
}

test('el resumen por estado agrupa correctamente cantidad y peso disponible', function () {
    Auth::guard('admin')->setUser(rollosUxCrearAdmin('Admin QA UX', [], 'all'));

    [$rolloA, $rolloB, $rolloC] = rollosUxCrearTresRollos();

    $cortador = rollosUxCrearAdmin('CORTADOR', []);
    $producto = Product::factory()->simple()->create();

    app(OrdenCorteService::class)->create([
        'rollo_ids' => [$rolloC->id], 'cortador_id' => $cortador->id, 'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [['product_id' => $producto->id, 'genero_linea' => 'Nino', 'talla' => 'U', 'cantidad_proyectada' => 10, 'cantidad_real_cortada' => 10]],
    ]);

    $porEstado = DB::table('bylopez_cc_rollos')
        ->whereIn('id', [$rolloA->id, $rolloB->id, $rolloC->id])
        ->select('estado', DB::raw('COUNT(*) as cantidad'), DB::raw('SUM(peso_disponible) as peso'))
        ->groupBy('estado')
        ->get()
        ->keyBy('estado');

    expect((int) $porEstado['disponible']->cantidad)->toBe(2)
        ->and((float) $porEstado['disponible']->peso)->toBe(50.0)
        ->and((int) $porEstado['reservado_en_corte']->cantidad)->toBe(1)
        ->and((float) $porEstado['reservado_en_corte']->peso)->toBe(50.0);
});

test('el filtro por query string estado devuelve solo los rollos de ese estado', function () {
    Auth::guard('admin')->setUser(rollosUxCrearAdmin('Admin QA UX', [], 'all'));

    [$rolloA, $rolloB, $rolloC] = rollosUxCrearTresRollos();

    $cortador = rollosUxCrearAdmin('CORTADOR', []);
    $producto = Product::factory()->simple()->create();

    app(OrdenCorteService::class)->create([
        'rollo_ids' => [$rolloC->id], 'cortador_id' => $cortador->id, 'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [['product_id' => $producto->id, 'genero_linea' => 'Nino', 'talla' => 'U', 'cantidad_proyectada' => 10, 'cantidad_real_cortada' => 10]],
    ]);

    request()->merge(['estado' => EstadoRollo::Disponible->value]);
    $ids = app(RolloDataGrid::class)->prepareQueryBuilder()
        ->whereIn('r.id', [$rolloA->id, $rolloB->id, $rolloC->id])
        ->pluck('r.id')->sort()->values()->all();

    expect($ids)->toBe(collect([$rolloA->id, $rolloB->id])->sort()->values()->all());

    request()->merge(['estado' => RolloDataGrid::FILTRO_AGOTADO_CERRADO]);
    $idsAgotadoCerrado = app(RolloDataGrid::class)->prepareQueryBuilder()
        ->whereIn('r.id', [$rolloA->id, $rolloB->id, $rolloC->id])
        ->pluck('r.id')->all();

    expect($idsAgotadoCerrado)->toBe([]);
});

test('un rollo reservado muestra su orden de corte activa con permiso, y sin permiso muestra texto plano', function () {
    Auth::guard('admin')->setUser(rollosUxCrearAdmin('Admin QA UX', [], 'all'));

    [$rolloA, , $rolloC] = rollosUxCrearTresRollos();
    $cortador = rollosUxCrearAdmin('CORTADOR', []);
    $producto = Product::factory()->simple()->create();

    $orden = app(OrdenCorteService::class)->create([
        'rollo_ids' => [$rolloC->id], 'cortador_id' => $cortador->id, 'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [['product_id' => $producto->id, 'genero_linea' => 'Nino', 'talla' => 'U', 'cantidad_proyectada' => 10, 'cantidad_real_cortada' => 10]],
    ]);

    $subquery = fn () => DB::table('bylopez_cc_orden_corte_rollos as ocr')
        ->join('bylopez_cc_ordenes_corte as oc', 'ocr.orden_corte_id', '=', 'oc.id')
        ->whereNotIn('oc.estado', ['cerrado', 'anulado'])
        ->select('ocr.rollo_id', 'oc.id as orden_corte_id', 'oc.codigo as orden_corte_codigo');

    $rowC = DB::table('bylopez_cc_rollos as r')->leftJoinSub($subquery(), 'reserva', 'r.id', '=', 'reserva.rollo_id')
        ->where('r.id', $rolloC->id)->select('r.id', 'reserva.orden_corte_id', 'reserva.orden_corte_codigo')->first();

    $rowA = DB::table('bylopez_cc_rollos as r')->leftJoinSub($subquery(), 'reserva', 'r.id', '=', 'reserva.rollo_id')
        ->where('r.id', $rolloA->id)->select('r.id', 'reserva.orden_corte_id', 'reserva.orden_corte_codigo')->first();

    $metodo = new ReflectionMethod(RolloDataGrid::class, 'ordenCorteEnlace');
    $metodo->setAccessible(true);
    $grid = app(RolloDataGrid::class);

    $htmlConPermiso = $metodo->invoke($grid, $rowC);
    expect($htmlConPermiso)->toContain(route('admin.corte_confeccion.ordenes_corte.show', $orden->id))
        ->and($htmlConPermiso)->toContain($orden->codigo);

    expect(trim($metodo->invoke($grid, $rowA)))->toBe('—');

    Auth::guard('admin')->setUser(rollosUxCrearAdmin('ASESOR UX QA', ['corte_confeccion.rollos']));

    $htmlSinPermiso = $metodo->invoke($grid, $rowC);
    expect($htmlSinPermiso)->not->toContain('<a')
        ->and($htmlSinPermiso)->toContain($orden->codigo);
});

test('el porcentaje disponible se calcula correctamente y evita division por cero', function () {
    $metodo = new ReflectionMethod(RolloDataGrid::class, 'porcentajeDisponible');
    $metodo->setAccessible(true);
    $grid = app(RolloDataGrid::class);

    expect($metodo->invoke($grid, (object) ['peso_inicial' => 20, 'peso_disponible' => 20]))->toBe('100,0 %')
        ->and($metodo->invoke($grid, (object) ['peso_inicial' => 40, 'peso_disponible' => 10]))->toBe('25,0 %')
        ->and($metodo->invoke($grid, (object) ['peso_inicial' => 0, 'peso_disponible' => 0]))->toBe('—');
});
