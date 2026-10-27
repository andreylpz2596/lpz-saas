<?php

use ByLopez\CorteConfeccion\Models\ProveedorTela;
use ByLopez\CorteConfeccion\Models\ProveedorTelaReferencia;
use ByLopez\CorteConfeccion\Models\Rollo;
use ByLopez\CorteConfeccion\Services\CompraTelaService;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use Illuminate\Validation\ValidationException;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;

function f1a_atributoSelect(string $code, string $nombre): Attribute
{
    return Attribute::firstWhere('code', $code)
        ?? Attribute::factory()->create(['code' => $code, 'type' => 'select', 'admin_name' => $nombre]);
}

function f1a_tipoTela(string $nombre = 'Tela F1A'): AttributeOption
{
    return AttributeOption::factory()
        ->for(f1a_atributoSelect('tipo_tela', 'Tipo de tela'), 'attribute')
        ->create(['admin_name' => $nombre.' '.uniqid()]);
}

function f1a_color(string $nombre = 'Verde Botella'): AttributeOption
{
    return AttributeOption::factory()
        ->for(f1a_atributoSelect('color', 'Color'), 'attribute')
        ->create(['admin_name' => $nombre]);
}

function f1a_size(string $nombre = 'M'): AttributeOption
{
    return AttributeOption::factory()
        ->for(f1a_atributoSelect('size', 'Talla'), 'attribute')
        ->create(['admin_name' => $nombre.' '.uniqid()]);
}

function f1a_proveedorSinReferencias(AttributeOption $tipoTela): ProveedorTela
{
    return app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor F1A '.uniqid(),
        'nit' => (string) random_int(700000000, 799999999),
        'telefono' => '3000000000',
        'contacto' => 'QA F1A',
        'direccion' => 'Calle F1A',
        'tipo_tela_ids' => [$tipoTela->id],
        'referencias' => [],
        'estado' => 'activo',
        'observacion' => null,
    ]);
}

function f1a_crearProveedorConReferencia(AttributeOption $tipoTela, array $referenciaOverrides = []): ProveedorTela
{
    return app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor F1A '.uniqid(),
        'nit' => (string) random_int(800000000, 899999999),
        'telefono' => '3000000000',
        'contacto' => 'QA F1A',
        'direccion' => 'Calle F1A',
        'tipo_tela_ids' => [$tipoTela->id],
        'referencias' => [
            array_merge([
                'tipo_tela_id' => $tipoTela->id,
                'color_option_id' => f1a_color()->id,
                'color' => 'Texto del navegador',
                'referencia' => 'F1A-REF-'.uniqid(),
                'gramaje' => '180',
                'valor_kilo_referencia' => 20000,
                'estado' => 'activo',
            ], $referenciaOverrides),
        ],
        'estado' => 'activo',
        'observacion' => null,
    ]);
}

function f1a_compraPayload(ProveedorTela $proveedor, ProveedorTelaReferencia $referencia, AttributeOption $tipoTela): array
{
    return [
        'codigo' => 'CT-F1A-'.uniqid(),
        'proveedor_id' => $proveedor->id,
        'numero_factura' => null,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'observacion' => null,
        'rollos' => [[
            'codigo' => 'R-F1A-'.uniqid(),
            'tipo_tela_id' => $tipoTela->id,
            'referencia_tela_id' => $referencia->id,
            'tipo_tela' => $tipoTela->admin_name,
            'color' => $referencia->color,
            'referencia' => $referencia->referencia,
            'gramaje' => $referencia->gramaje,
            'peso_inicial' => 10,
            'valor_kilo' => 20000,
            'color_option_id' => f1a_color('Color Alterno')->id,
        ]],
    ];
}

test('F1-A: crear referencia nueva sin color_option_id es rechazado', function () {
    $tipoTela = f1a_tipoTela();

    expect(fn () => f1a_crearProveedorConReferencia($tipoTela, [
        'color_option_id' => null,
        'color' => 'Verde Botella',
    ]))->toThrow(ValidationException::class);
});

test('F1-A: crear referencia con opcion de size es rechazado', function () {
    $tipoTela = f1a_tipoTela();
    $size = f1a_size();

    expect(fn () => f1a_crearProveedorConReferencia($tipoTela, [
        'color_option_id' => $size->id,
        'color' => 'M',
    ]))->toThrow(ValidationException::class);
});

test('F1-A: crear referencia con opcion real de color es permitido', function () {
    $tipoTela = f1a_tipoTela();
    $color = f1a_color('Azul F1A');

    $proveedor = f1a_crearProveedorConReferencia($tipoTela, [
        'color_option_id' => $color->id,
        'color' => 'Texto ignorado',
    ]);

    $referencia = $proveedor->referenciasTela()->first();

    expect($referencia->color_option_id)->toBe($color->id);
});

test('F1-A: referencia historica con color_option_id null sigue siendo legible', function () {
    $tipoTela = f1a_tipoTela();
    $proveedor = f1a_proveedorSinReferencias($tipoTela);

    $referencia = $proveedor->referenciasTela()->create([
        'tipo_tela_id' => $tipoTela->id,
        'color_option_id' => null,
        'color' => 'Verde botella',
        'referencia' => 'HIST-F1A',
        'gramaje' => '180',
        'valor_kilo_referencia' => 20000,
        'estado' => 'activo',
    ]);

    $persistida = ProveedorTelaReferencia::query()->findOrFail($referencia->id);

    expect($persistida->color)->toBe('Verde botella')
        ->and($persistida->color_option_id)->toBeNull()
        ->and($proveedor->referenciasTela()->pluck('id')->all())->toBe([$referencia->id]);
});

test('F1-A: compra con referencia historica sin color vinculado es bloqueada', function () {
    $tipoTela = f1a_tipoTela();
    $proveedor = f1a_proveedorSinReferencias($tipoTela);
    $referencia = $proveedor->referenciasTela()->create([
        'tipo_tela_id' => $tipoTela->id,
        'color_option_id' => null,
        'color' => 'Verde botella',
        'referencia' => 'HIST-COMPRA-F1A',
        'gramaje' => '180',
        'valor_kilo_referencia' => 20000,
        'estado' => 'activo',
    ]);

    $rollosAntes = Rollo::query()->count();

    try {
        app(CompraTelaService::class)->create(f1a_compraPayload($proveedor, $referencia, $tipoTela));
        $this->fail('La compra debio ser bloqueada por referencia sin color vinculado.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['rollos'][0])->toBe(trans('corteconfeccion::app.errors.referencia-color-sin-vincular'));
    }

    expect(Rollo::query()->count())->toBe($rollosAntes);
});

test('F1-A: compra con referencia vinculada copia exactamente color_option_id al rollo', function () {
    $tipoTela = f1a_tipoTela();
    $color = f1a_color('Rojo F1A');
    $proveedor = f1a_crearProveedorConReferencia($tipoTela, ['color_option_id' => $color->id]);
    $referencia = $proveedor->referenciasTela()->first();

    $compra = app(CompraTelaService::class)->create(f1a_compraPayload($proveedor, $referencia, $tipoTela));
    $rollo = $compra->rollos()->first();

    expect($rollo->color_option_id)->toBe($color->id);
});

test('F1-A: el snapshot color se deriva en backend de la opcion Bagisto', function () {
    $tipoTela = f1a_tipoTela();
    $color = f1a_color('Verde Botella F1A');

    $proveedor = f1a_crearProveedorConReferencia($tipoTela, [
        'color_option_id' => $color->id,
        'color' => 'Texto libre manipulado',
    ]);

    $referencia = $proveedor->referenciasTela()->first();

    expect($referencia->color)->toBe('Verde Botella F1A')
        ->and($referencia->color_option_id)->toBe($color->id);
});
