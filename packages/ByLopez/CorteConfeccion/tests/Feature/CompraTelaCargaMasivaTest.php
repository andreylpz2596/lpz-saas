<?php

use ByLopez\CorteConfeccion\Models\CompraTela;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\Rollo;
use ByLopez\CorteConfeccion\Services\CompraTelaService;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use Illuminate\Support\Facades\Auth;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
|--------------------------------------------------------------------------
| Regresion: carga masiva de rollos en Compras de Tela. Prefijo ctm_ para
| no chocar con helpers de otros archivos de test.
|--------------------------------------------------------------------------
*/

function ctm_crearAdmin(string $nombreRol = 'Admin CTM', string $permissionType = 'all'): Admin
{
    $role = Role::factory()->create(['name' => $nombreRol.' '.uniqid(), 'permission_type' => $permissionType]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function ctm_colorOption(string $nombre): AttributeOption
{
    $atributoColor = Attribute::firstWhere('code', 'color')
        ?? Attribute::factory()->create(['code' => 'color', 'type' => 'select', 'admin_name' => 'Color']);

    return AttributeOption::factory()->for($atributoColor, 'attribute')->create(['admin_name' => $nombre]);
}

/**
 * Crea un proveedor real con dos referencias (Rojo Ref 444 y Negro Ref 221,
 * ambas 175g) sobre el mismo atributo tipo_tela, para reproducir escenarios
 * de agrupacion reales sin depender de otros archivos de test.
 */
function ctm_crearProveedorConReferencias(): array
{
    $atributoTipoTela = Attribute::firstWhere('code', 'tipo_tela')
        ?? Attribute::factory()->create(['code' => 'tipo_tela', 'type' => 'select', 'admin_name' => 'Tipo de tela']);

    $tipoTela = AttributeOption::factory()->for($atributoTipoTela, 'attribute')->create(['admin_name' => 'Piel durazno CTM '.uniqid()]);
    $rojo = ctm_colorOption('Rojo');
    $negro = ctm_colorOption('Negro');

    $proveedor = app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor CTM '.uniqid(),
        'nit' => (string) random_int(900000000, 999999999),
        'telefono' => '3000000000',
        'contacto' => 'QA',
        'direccion' => 'Calle QA',
        'tipo_tela_ids' => [$tipoTela->id],
        'referencias' => [
            ['tipo_tela_id' => $tipoTela->id, 'color_option_id' => $rojo->id, 'color' => 'Rojo', 'referencia' => '444', 'gramaje' => '175', 'valor_kilo_referencia' => 16500, 'estado' => 'activo'],
            ['tipo_tela_id' => $tipoTela->id, 'color_option_id' => $negro->id, 'color' => 'Negro', 'referencia' => '221', 'gramaje' => '175', 'valor_kilo_referencia' => 17000, 'estado' => 'activo'],
        ],
        'estado' => 'activo',
        'observacion' => null,
    ]);

    return [$proveedor, $tipoTela];
}

function ctm_rolloPayload(int $referenciaId, AttributeOption $tipoTela, string $color, string $referencia, string $gramaje, float $pesoInicial, float $valorKilo, ?int $id = null): array
{
    return array_filter([
        'id' => $id,
        'referencia_tela_id' => $referenciaId,
        'tipo_tela_id' => $tipoTela->id,
        'tipo_tela' => $tipoTela->admin_name,
        'color' => $color,
        'referencia' => $referencia,
        'gramaje' => $gramaje,
        'peso_inicial' => $pesoInicial,
        'valor_kilo' => $valorKilo,
        'observacion' => null,
    ], fn ($v) => $v !== null);
}

test('CASO A: crear compra con 5 rollos rojos iguales persiste 5 registros independientes con pesos y subtotal correctos', function () {
    $admin = ctm_crearAdmin();
    [$proveedor, $tipoTela] = ctm_crearProveedorConReferencias();
    $referenciaRoja = $proveedor->referenciasTela->firstWhere('color', 'Rojo');

    $pesos = [25, 22, 26, 22, 26];
    $rollos = collect($pesos)->map(fn ($peso) => ctm_rolloPayload($referenciaRoja->id, $tipoTela, 'Rojo', '444', '175', $peso, 16500))->all();

    $response = $this->actingAs($admin, 'admin')->post(route('admin.corte_confeccion.compras_tela.store'), [
        'codigo' => 'CT-CTM-'.uniqid(),
        'proveedor_id' => $proveedor->id,
        'numero_factura' => null,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'observacion' => null,
        'rollos' => $rollos,
    ]);

    $response->assertRedirect();
    $compra = CompraTela::where('proveedor_id', $proveedor->id)->latest('id')->first();

    expect($compra)->not->toBeNull();
    expect(Rollo::where('compra_tela_id', $compra->id)->count())->toBe(5);
    expect((float) $compra->peso_total)->toBe(121.0);
    expect((float) $compra->subtotal)->toBe(1996500.0);
    expect((float) $compra->total_factura)->toBe(1996500.0);
    expect((int) $compra->cantidad_rollos)->toBe(5);

    // Cada rollo es un registro fisico propio con su propio peso -- no una fila con "cantidad".
    $pesosPersistidos = Rollo::where('compra_tela_id', $compra->id)->orderBy('id')->pluck('peso_inicial')->map(fn ($p) => (float) $p)->all();
    expect($pesosPersistidos)->toBe([25.0, 22.0, 26.0, 22.0, 26.0]);
});

test('CASO B: tres grupos (rojo, negro, marfil via historico) se persisten como filas independientes y el total suma 16', function () {
    $admin = ctm_crearAdmin();
    [$proveedor, $tipoTela] = ctm_crearProveedorConReferencias();
    $referenciaRoja = $proveedor->referenciasTela->firstWhere('color', 'Rojo');
    $referenciaNegra = $proveedor->referenciasTela->firstWhere('color', 'Negro');

    $rollos = collect()
        ->concat(collect(range(1, 5))->map(fn () => ctm_rolloPayload($referenciaRoja->id, $tipoTela, 'Rojo', '444', '175', 10, 16500)))
        ->concat(collect(range(1, 8))->map(fn () => ctm_rolloPayload($referenciaNegra->id, $tipoTela, 'Negro', '221', '175', 10, 17000)))
        ->all();

    // "Marfil" simulado como tercer grupo con la misma referencia negra pero
    // valor_kilo distinto -- confirma en el mismo caso que grupos con
    // valor_kilo diferente no se mezclan (CASO D) y que el conteo total es correcto.
    $rollos = array_merge($rollos, collect(range(1, 3))->map(fn () => ctm_rolloPayload($referenciaNegra->id, $tipoTela, 'Negro', '221', '175', 10, 18000))->all());

    $response = $this->actingAs($admin, 'admin')->post(route('admin.corte_confeccion.compras_tela.store'), [
        'codigo' => 'CT-CTM-'.uniqid(),
        'proveedor_id' => $proveedor->id,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'rollos' => $rollos,
    ]);

    $response->assertRedirect();
    $compra = CompraTela::where('proveedor_id', $proveedor->id)->latest('id')->first();

    expect((int) $compra->cantidad_rollos)->toBe(16);
    expect(Rollo::where('compra_tela_id', $compra->id)->count())->toBe(16);
});

test('CASO E: editar una compra existente sin cambios no crea duplicados y conserva los IDs', function () {
    $admin = ctm_crearAdmin();
    [$proveedor, $tipoTela] = ctm_crearProveedorConReferencias();
    $referenciaRoja = $proveedor->referenciasTela->firstWhere('color', 'Rojo');

    Auth::guard('admin')->setUser($admin);
    $compra = app(CompraTelaService::class)->create([
        'codigo' => 'CT-CTM-'.uniqid(),
        'proveedor_id' => $proveedor->id,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'rollos' => collect(range(1, 3))->map(fn () => ctm_rolloPayload($referenciaRoja->id, $tipoTela, 'Rojo', '444', '175', 20, 16500))->all(),
    ]);

    $idsOriginales = $compra->rollos->pluck('id')->sort()->values()->all();

    $rollosParaActualizar = $compra->rollos->map(fn (Rollo $rollo) => ctm_rolloPayload($referenciaRoja->id, $tipoTela, 'Rojo', '444', '175', (float) $rollo->peso_inicial, (float) $rollo->valor_kilo, $rollo->id))->all();

    $response = $this->actingAs($admin, 'admin')->put(route('admin.corte_confeccion.compras_tela.update', $compra->id), [
        'codigo' => $compra->codigo,
        'proveedor_id' => $proveedor->id,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'rollos' => $rollosParaActualizar,
    ]);

    $response->assertRedirect();

    $compra->refresh();
    expect(Rollo::where('compra_tela_id', $compra->id)->count())->toBe(3);
    expect($compra->rollos->pluck('id')->sort()->values()->all())->toBe($idsOriginales);
});

test('agregar nuevos rollos a una compra existente los suma sin afectar los ya persistidos', function () {
    $admin = ctm_crearAdmin();
    [$proveedor, $tipoTela] = ctm_crearProveedorConReferencias();
    $referenciaRoja = $proveedor->referenciasTela->firstWhere('color', 'Rojo');

    Auth::guard('admin')->setUser($admin);
    $compra = app(CompraTelaService::class)->create([
        'codigo' => 'CT-CTM-'.uniqid(),
        'proveedor_id' => $proveedor->id,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'rollos' => collect(range(1, 2))->map(fn () => ctm_rolloPayload($referenciaRoja->id, $tipoTela, 'Rojo', '444', '175', 20, 16500))->all(),
    ]);

    $idOriginal1 = $compra->rollos[0]->id;
    $idOriginal2 = $compra->rollos[1]->id;

    $rollosActualizados = array_merge(
        [
            ctm_rolloPayload($referenciaRoja->id, $tipoTela, 'Rojo', '444', '175', 20, 16500, $idOriginal1),
            ctm_rolloPayload($referenciaRoja->id, $tipoTela, 'Rojo', '444', '175', 20, 16500, $idOriginal2),
        ],
        collect(range(1, 3))->map(fn () => ctm_rolloPayload($referenciaRoja->id, $tipoTela, 'Rojo', '444', '175', 15, 16500))->all()
    );

    $response = $this->actingAs($admin, 'admin')->put(route('admin.corte_confeccion.compras_tela.update', $compra->id), [
        'codigo' => $compra->codigo,
        'proveedor_id' => $proveedor->id,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'rollos' => $rollosActualizados,
    ]);

    $response->assertRedirect();
    $compra->refresh();

    expect(Rollo::where('compra_tela_id', $compra->id)->count())->toBe(5);
    expect(Rollo::whereKey($idOriginal1)->exists())->toBeTrue();
    expect(Rollo::whereKey($idOriginal2)->exists())->toBeTrue();
    expect((int) $compra->cantidad_rollos)->toBe(5);
});

test('quitar un rollo de una compra existente en edicion lo elimina y actualiza los totales', function () {
    $admin = ctm_crearAdmin();
    [$proveedor, $tipoTela] = ctm_crearProveedorConReferencias();
    $referenciaRoja = $proveedor->referenciasTela->firstWhere('color', 'Rojo');

    Auth::guard('admin')->setUser($admin);
    $compra = app(CompraTelaService::class)->create([
        'codigo' => 'CT-CTM-'.uniqid(),
        'proveedor_id' => $proveedor->id,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'rollos' => collect(range(1, 3))->map(fn () => ctm_rolloPayload($referenciaRoja->id, $tipoTela, 'Rojo', '444', '175', 20, 16500))->all(),
    ]);

    $rolloAEliminar = $compra->rollos[0];
    $rollosRestantes = $compra->rollos->skip(1)->map(fn (Rollo $rollo) => ctm_rolloPayload($referenciaRoja->id, $tipoTela, 'Rojo', '444', '175', (float) $rollo->peso_inicial, (float) $rollo->valor_kilo, $rollo->id))->all();

    $response = $this->actingAs($admin, 'admin')->put(route('admin.corte_confeccion.compras_tela.update', $compra->id), [
        'codigo' => $compra->codigo,
        'proveedor_id' => $proveedor->id,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'rollos' => $rollosRestantes,
    ]);

    $response->assertRedirect();
    $compra->refresh();

    expect(Rollo::whereKey($rolloAEliminar->id)->exists())->toBeFalse();
    expect((int) $compra->cantidad_rollos)->toBe(2);
    expect((float) $compra->peso_total)->toBe(40.0);
});

test('descuento e impuesto afectan el total final sin alterar el subtotal de rollos', function () {
    $admin = ctm_crearAdmin();
    [$proveedor, $tipoTela] = ctm_crearProveedorConReferencias();
    $referenciaRoja = $proveedor->referenciasTela->firstWhere('color', 'Rojo');

    $rollos = collect(range(1, 2))->map(fn () => ctm_rolloPayload($referenciaRoja->id, $tipoTela, 'Rojo', '444', '175', 10, 16500))->all();

    $response = $this->actingAs($admin, 'admin')->post(route('admin.corte_confeccion.compras_tela.store'), [
        'codigo' => 'CT-CTM-'.uniqid(),
        'proveedor_id' => $proveedor->id,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 10000,
        'impuesto' => 5000,
        'rollos' => $rollos,
    ]);

    $response->assertRedirect();
    $compra = CompraTela::where('proveedor_id', $proveedor->id)->latest('id')->first();

    expect((float) $compra->subtotal)->toBe(330000.0);
    expect((float) $compra->total_factura)->toBe(325000.0);
});

test('un rollo ya usado en una orden de corte no puede eliminarse de la compra y el resto de la operacion falla de forma atomica', function () {
    $admin = ctm_crearAdmin();
    [$proveedor, $tipoTela] = ctm_crearProveedorConReferencias();
    $referenciaRoja = $proveedor->referenciasTela->firstWhere('color', 'Rojo');

    Auth::guard('admin')->setUser($admin);
    $compra = app(CompraTelaService::class)->create([
        'codigo' => 'CT-CTM-'.uniqid(),
        'proveedor_id' => $proveedor->id,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'rollos' => collect(range(1, 2))->map(fn () => ctm_rolloPayload($referenciaRoja->id, $tipoTela, 'Rojo', '444', '175', 20, 16500))->all(),
    ]);

    $rolloUsado = $compra->rollos[0];

    // Simula que el rollo ya tiene una orden de corte asociada (sin pasar por
    // el flujo completo de Orden de Corte, que no es objeto de esta prueba).
    OrdenCorte::create([
        'codigo' => 'OC-CTM-'.uniqid(),
        'rollo_id' => $rolloUsado->id,
        'cortador_nombre' => 'Cortador CTM',
        'cortador_nombre_snapshot' => 'Cortador CTM',
        'color' => 'Rojo',
        'genero_linea' => 'Unisex',
        'peso_entregado' => 0,
        'peso_total_rollos' => 20,
        'peso_sobrante_total' => 0,
        'merma_total' => 0,
        'peso_consumido_total' => 0,
        'total_piezas_cortadas' => 0,
        'peso_promedio_pieza' => 0,
        'estado' => 'pendiente_entrega',
    ]);

    // Se envia la actualizacion SIN el rollo usado (como si el usuario lo hubiera quitado).
    $rollosSinElUsado = [
        ctm_rolloPayload($referenciaRoja->id, $tipoTela, 'Rojo', '444', '175', 20, 16500, $compra->rollos[1]->id),
    ];

    $response = $this->actingAs($admin, 'admin')->put(route('admin.corte_confeccion.compras_tela.update', $compra->id), [
        'codigo' => $compra->codigo,
        'proveedor_id' => $proveedor->id,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'rollos' => $rollosSinElUsado,
    ]);

    $response->assertSessionHasErrors('rollos');
    expect(Rollo::whereKey($rolloUsado->id)->exists())->toBeTrue();
    expect(Rollo::where('compra_tela_id', $compra->id)->count())->toBe(2);
});
