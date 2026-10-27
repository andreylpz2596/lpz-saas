<?php

use ByLopez\CorteConfeccion\Models\ProveedorTela;
use ByLopez\CorteConfeccion\Models\Rollo;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;

function f1c_migracionColor(): object
{
    return require base_path('packages/ByLopez/CorteConfeccion/src/Database/Migrations/2026_09_23_000001_add_color_option_id_to_referencias_y_rollos.php');
}

function f1c_atributoSelect(string $code, string $nombre): Attribute
{
    return Attribute::firstWhere('code', $code)
        ?? Attribute::factory()->create(['code' => $code, 'type' => 'select', 'admin_name' => $nombre]);
}

function f1c_opcionColor(string $nombre): AttributeOption
{
    return AttributeOption::factory()
        ->for(f1c_atributoSelect('color', 'Color'), 'attribute')
        ->create(['admin_name' => $nombre]);
}

function f1c_tipoTela(): AttributeOption
{
    return AttributeOption::factory()
        ->for(f1c_atributoSelect('tipo_tela', 'Tipo de tela'), 'attribute')
        ->create(['admin_name' => 'Tela F1C '.uniqid()]);
}

function f1c_proveedor(AttributeOption $tipoTela): ProveedorTela
{
    return app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor F1C '.uniqid(),
        'nit' => (string) random_int(400000000, 499999999),
        'telefono' => '3000000000',
        'contacto' => null,
        'direccion' => null,
        'tipo_tela_ids' => [$tipoTela->id],
        'referencias' => [],
        'estado' => 'activo',
        'observacion' => null,
    ]);
}

function f1c_rollo(array $overrides = []): Rollo
{
    return Rollo::query()->create(array_merge([
        'codigo' => 'R-F1C-'.uniqid(),
        'proveedor' => 'Proveedor F1C',
        'color' => 'Texto viejo',
        'tipo_tela' => 'Tela F1C',
        'peso_inicial' => 10,
        'peso_disponible' => 10,
        'valor_kilo' => 20000,
        'costo_total' => 200000,
        'fecha_compra' => now()->toDateString(),
        'estado' => 'disponible',
        'observacion' => null,
        'color_option_id' => null,
    ], $overrides));
}

test('F1-C: backfill historico de color_option_id aplica solo match estricto normalizado', function () {
    $tipoTela = f1c_tipoTela();
    $proveedor = f1c_proveedor($tipoTela);

    $sufijo = uniqid();
    $colorUnico = f1c_opcionColor('Rojo F1C '.$sufijo);
    $colorManual = f1c_opcionColor('Manual F1C '.$sufijo);
    $colorReferenciaRollo = f1c_opcionColor('Referencia F1C '.$sufijo);
    f1c_opcionColor('Duplicado F1C '.$sufijo);
    f1c_opcionColor('  duplicado f1c '.$sufijo.'  ');
    f1c_opcionColor('Verde Cali F1C '.$sufijo);
    f1c_opcionColor('Verde Oliva F1C '.$sufijo);
    f1c_opcionColor('Verde Menta F1C '.$sufijo);

    $referenciaUnica = $proveedor->referenciasTela()->create([
        'tipo_tela_id' => $tipoTela->id,
        'color' => 'rojo f1c '.$sufijo,
        'color_option_id' => null,
        'referencia' => 'REF-UNICA',
        'gramaje' => '180',
        'valor_kilo_referencia' => 20000,
        'estado' => 'activo',
    ]);

    $referenciaNormalizada = $proveedor->referenciasTela()->create([
        'tipo_tela_id' => $tipoTela->id,
        'color' => '  ROJO F1C '.$sufijo.'  ',
        'color_option_id' => null,
        'referencia' => 'REF-NORMALIZADA',
        'gramaje' => '180',
        'valor_kilo_referencia' => 20000,
        'estado' => 'activo',
    ]);

    $referenciaSinMatch = $proveedor->referenciasTela()->create([
        'tipo_tela_id' => $tipoTela->id,
        'color' => 'Verde botella F1C '.$sufijo,
        'color_option_id' => null,
        'referencia' => 'REF-SIN-MATCH',
        'gramaje' => '180',
        'valor_kilo_referencia' => 20000,
        'estado' => 'activo',
    ]);

    $referenciaDuplicada = $proveedor->referenciasTela()->create([
        'tipo_tela_id' => $tipoTela->id,
        'color' => 'DUPLICADO F1C '.$sufijo,
        'color_option_id' => null,
        'referencia' => 'REF-DUPLICADA',
        'gramaje' => '180',
        'valor_kilo_referencia' => 20000,
        'estado' => 'activo',
    ]);

    $referenciaParaRollo = $proveedor->referenciasTela()->create([
        'tipo_tela_id' => $tipoTela->id,
        'color' => 'Referencia F1C '.$sufijo,
        'color_option_id' => $colorReferenciaRollo->id,
        'referencia' => 'REF-ROLLO',
        'gramaje' => '180',
        'valor_kilo_referencia' => 20000,
        'estado' => 'activo',
    ]);

    $rolloConReferencia = f1c_rollo([
        'proveedor_tela_referencia_id' => $referenciaParaRollo->id,
        'proveedor_tela_id' => $proveedor->id,
        'tipo_tela_id' => $tipoTela->id,
        'color' => 'texto viejo sin relacion',
    ]);

    $rolloManualMatch = f1c_rollo([
        'color' => '  manual f1c '.$sufijo.'  ',
    ]);

    $rolloManualSinMatch = f1c_rollo([
        'color' => 'Sin match manual F1C '.$sufijo,
    ]);

    f1c_migracionColor()->backfillColorOptionIds();

    expect($referenciaUnica->refresh()->color_option_id)->toBe($colorUnico->id)
        ->and($referenciaNormalizada->refresh()->color_option_id)->toBe($colorUnico->id)
        ->and($referenciaSinMatch->refresh()->color_option_id)->toBeNull()
        ->and($referenciaDuplicada->refresh()->color_option_id)->toBeNull()
        ->and($rolloConReferencia->refresh()->color_option_id)->toBe($colorReferenciaRollo->id)
        ->and($rolloManualMatch->refresh()->color_option_id)->toBe($colorManual->id)
        ->and($rolloManualSinMatch->refresh()->color_option_id)->toBeNull();
});
