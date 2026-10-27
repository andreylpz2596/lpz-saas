<?php

use ByLopez\CorteConfeccion\Services\VarianteConsultaService;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Core\Models\Channel;
use Webkul\Product\Models\Product;

/*
|--------------------------------------------------------------------------
| Fixtures autosuficientes para VarianteConsultaService (Fase 04B).
| Prefijo "vc_" para no chocar con helpers globales de otros archivos.
|--------------------------------------------------------------------------
*/

/**
 * Reutiliza el atributo real si ya existe en el catalogo (ej. "color" y
 * "size" ya existen en los datos reales -- "code" tiene unique key), o lo
 * crea si no (entorno de test sin ese seed). Mismo patron ya usado en
 * otros tests del paquete (ej. RolesYTalleresTest con "tipo_tela").
 */
function vc_crearAtributoSelect(string $code, string $adminName): Attribute
{
    return Attribute::firstWhere('code', $code)
        ?? Attribute::factory()->create([
            'code' => $code,
            'type' => 'select',
            'admin_name' => $adminName,
        ]);
}

function vc_crearOpcion(Attribute $attribute, string $adminName): AttributeOption
{
    return AttributeOption::factory()->for($attribute, 'attribute')->create([
        'admin_name' => $adminName,
    ]);
}

/**
 * Inserta la fila product_flat minima necesaria (no existe factory ni
 * observer que la cree automaticamente en este proyecto).
 */
function vc_crearProductFlat(int $productId, ?string $name, bool $status = true): void
{
    DB::table('product_flat')->insert([
        'product_id' => $productId,
        'sku' => 'VC-QA-'.$productId,
        'name' => $name,
        'status' => $status,
        'locale' => app()->getLocale(),
        'channel' => 'default',
    ]);
}

/**
 * Crea un configurable + un simple hijo, con sus ejes de variacion
 * (product_super_attributes) y valores (product_attribute_values), tal
 * como se confirmo con evidencia real en 04A_STOCK_VARIANTE_DISENO.md.
 *
 * $ejes: [[Attribute, AttributeOption], ...] -- ej. [[colorAttr, opcionVerde], [tallaAttr, opcionL]]
 */
function vc_crearVarianteConEjes(string $nombreConfigurable, string $skuSimple, array $ejes): array
{
    $configurable = Product::factory()->configurable()->create(['sku' => $skuSimple.'-CONF']);
    vc_crearProductFlat($configurable->id, $nombreConfigurable);

    $simple = Product::factory()->simple()->create([
        'sku' => $skuSimple,
        'parent_id' => $configurable->id,
    ]);
    vc_crearProductFlat($simple->id, $nombreConfigurable.' '.$skuSimple);

    foreach ($ejes as [$attribute, $option]) {
        DB::table('product_super_attributes')->insert([
            'product_id' => $configurable->id,
            'attribute_id' => $attribute->id,
        ]);

        DB::table('product_attribute_values')->insert([
            'product_id' => $simple->id,
            'attribute_id' => $attribute->id,
            'integer_value' => $option->id,
        ]);
    }

    return ['configurable' => $configurable, 'simple' => $simple];
}

/*
|--------------------------------------------------------------------------
| Bloque 1: resolucion base (sku/nombre/tipo/activo), sin atributos ni stock.
|--------------------------------------------------------------------------
*/

test('resuelve sku, nombre del configurable padre, tipo y activo para un simple', function () {
    $configurable = Product::factory()->configurable()->create();
    vc_crearProductFlat($configurable->id, 'Camiseta Piel Durazno Verde Cali Hombre');

    $simple = Product::factory()->simple()->create(['parent_id' => $configurable->id]);
    vc_crearProductFlat($simple->id, 'Camiseta Piel Durazno Verde Cali Hombre L', status: true);

    $resultado = app(VarianteConsultaService::class)->porProductIds([$simple->id]);

    expect($resultado)->toHaveCount(1);

    $fila = $resultado[$simple->id];

    expect($fila['product_id'])->toBe($simple->id)
        ->and($fila['sku'])->toBe($simple->sku)
        ->and($fila['nombre'])->toBe('Camiseta Piel Durazno Verde Cali Hombre') // del padre, no del simple
        ->and($fila['tipo_producto'])->toBe('simple')
        ->and($fila['activo'])->toBeTrue();
});

test('un simple huerfano (sin parent_id) usa su propio nombre como fallback', function () {
    $simple = Product::factory()->simple()->create(['parent_id' => null]);
    vc_crearProductFlat($simple->id, 'Producto huerfano QA');

    $resultado = app(VarianteConsultaService::class)->porProductIds([$simple->id]);

    expect($resultado[$simple->id]['nombre'])->toBe('Producto huerfano QA');
});

/*
|--------------------------------------------------------------------------
| Bloque 2: atributos configurables (color/talla), caso adulto e infantil.
|--------------------------------------------------------------------------
*/

test('CASO 1 - variante adulta normal: sku + color + Talla + resuelve correctamente', function () {
    $color = vc_crearAtributoSelect('color', 'Color');
    $talla = vc_crearAtributoSelect('size', 'Talla');
    $opcionVerde = vc_crearOpcion($color, 'Verde Cali');
    $opcionL = vc_crearOpcion($talla, 'L');

    ['simple' => $simple] = vc_crearVarianteConEjes(
        'Camiseta Piel Durazno Verde Cali Hombre',
        'QA-'.uniqid(),
        [[$color, $opcionVerde], [$talla, $opcionL]]
    );

    $fila = app(VarianteConsultaService::class)->porProductIds([$simple->id])[$simple->id];

    expect($fila['sku'])->toBe($simple->sku)
        ->and($fila['color'])->toBe('Verde Cali')
        ->and($fila['talla'])->toBe('L')
        ->and($fila['talla_etiqueta'])->toBe($talla->admin_name);
});

test('CASO 2 - variante infantil: grupo_de_edad_ninos se resuelve y no se rotula como Talla', function () {
    $color = vc_crearAtributoSelect('color', 'Color');
    $grupoEdad = vc_crearAtributoSelect('grupo_de_edad_ninos', 'grupo de edad (niños)');
    $opcionAzul = vc_crearOpcion($color, 'Azul Rey');
    $opcion2a4 = vc_crearOpcion($grupoEdad, '2-4 años');

    ['simple' => $simple] = vc_crearVarianteConEjes(
        'Camiseta Infantil Azul Rey',
        'QA-'.uniqid(),
        [[$color, $opcionAzul], [$grupoEdad, $opcion2a4]]
    );

    $fila = app(VarianteConsultaService::class)->porProductIds([$simple->id])[$simple->id];

    expect($fila['color'])->toBe('Azul Rey')
        ->and($fila['talla'])->toBe('2-4 años')
        ->and($fila['talla_etiqueta'])->toBe('grupo de edad (niños)')
        ->and($fila['talla_etiqueta'])->not->toBe('Talla');
});

test('CASO 3 - varias variantes en una misma llamada: todos los resultados correctos', function () {
    $color = vc_crearAtributoSelect('color', 'Color');
    $talla = vc_crearAtributoSelect('size', 'Talla');
    $verde = vc_crearOpcion($color, 'Verde Cali');
    $opcionL = vc_crearOpcion($talla, 'L');
    $opcionM = vc_crearOpcion($talla, 'M');

    $configurable = Product::factory()->configurable()->create();
    vc_crearProductFlat($configurable->id, 'Camiseta Verde Cali Hombre');

    $simpleL = Product::factory()->simple()->create(['parent_id' => $configurable->id]);
    vc_crearProductFlat($simpleL->id, 'Camiseta Verde Cali Hombre L');
    $simpleM = Product::factory()->simple()->create(['parent_id' => $configurable->id]);
    vc_crearProductFlat($simpleM->id, 'Camiseta Verde Cali Hombre M');

    DB::table('product_super_attributes')->insert([
        ['product_id' => $configurable->id, 'attribute_id' => $color->id],
        ['product_id' => $configurable->id, 'attribute_id' => $talla->id],
    ]);

    foreach ([[$simpleL, $opcionL], [$simpleM, $opcionM]] as [$simple, $opcionTalla]) {
        DB::table('product_attribute_values')->insert([
            ['product_id' => $simple->id, 'attribute_id' => $color->id, 'integer_value' => $verde->id],
            ['product_id' => $simple->id, 'attribute_id' => $talla->id, 'integer_value' => $opcionTalla->id],
        ]);
    }

    $resultado = app(VarianteConsultaService::class)->porProductIds([$simpleL->id, $simpleM->id]);

    expect($resultado)->toHaveCount(2)
        ->and($resultado[$simpleL->id]['talla'])->toBe('L')
        ->and($resultado[$simpleM->id]['talla'])->toBe('M')
        ->and($resultado[$simpleL->id]['color'])->toBe('Verde Cali')
        ->and($resultado[$simpleM->id]['color'])->toBe('Verde Cali');
});

test('CASO 7 - atributo color ausente: fallback a null sin romper la operacion', function () {
    $simple = Product::factory()->simple()->create(['parent_id' => null]);
    vc_crearProductFlat($simple->id, 'Producto sin atributos QA');

    $fila = app(VarianteConsultaService::class)->porProductIds([$simple->id])[$simple->id];

    expect($fila['color'])->toBeNull()
        ->and($fila['talla'])->toBeNull()
        ->and($fila['talla_etiqueta'])->toBeNull();
});

test('CASO 8 - opcion asignada sin admin_name: fallback "No definido"', function () {
    $color = vc_crearAtributoSelect('color', 'Color');
    $opcionSinNombre = vc_crearOpcion($color, '');

    ['simple' => $simple] = vc_crearVarianteConEjes(
        'Producto opcion sin nombre QA',
        'QA-'.uniqid(),
        [[$color, $opcionSinNombre]]
    );

    $fila = app(VarianteConsultaService::class)->porProductIds([$simple->id])[$simple->id];

    expect($fila['color'])->toBe('No definido');
});

/*
|--------------------------------------------------------------------------
| Bloque 3: stock (0 vs null, channel explicito vs default).
|--------------------------------------------------------------------------
*/

test('CASO 5 - stock = 0 devuelve entero 0, no null', function () {
    $simple = Product::factory()->simple()->create(['parent_id' => null]);
    vc_crearProductFlat($simple->id, 'Producto stock cero QA');

    $canalDefault = core()->getDefaultChannel();
    DB::table('product_inventory_indices')->insert([
        'product_id' => $simple->id,
        'channel_id' => $canalDefault->id,
        'qty' => 0,
    ]);

    $fila = app(VarianteConsultaService::class)->porProductIds([$simple->id])[$simple->id];

    expect($fila['stock_actual'])->toBe(0)->not->toBeNull();
});

test('CASO 6 - sin fila de inventory index: stock_actual es null, no 0', function () {
    $simple = Product::factory()->simple()->create(['parent_id' => null]);
    vc_crearProductFlat($simple->id, 'Producto sin indice QA');

    $fila = app(VarianteConsultaService::class)->porProductIds([$simple->id])[$simple->id];

    expect($fila['stock_actual'])->toBeNull();
});

test('CASO 13 - channel null usa el default channel', function () {
    $simple = Product::factory()->simple()->create(['parent_id' => null]);
    vc_crearProductFlat($simple->id, 'Producto channel default QA');

    $canalDefault = core()->getDefaultChannel();
    DB::table('product_inventory_indices')->insert([
        'product_id' => $simple->id,
        'channel_id' => $canalDefault->id,
        'qty' => 130,
    ]);

    $fila = app(VarianteConsultaService::class)->porProductIds([$simple->id])[$simple->id];

    expect($fila['stock_actual'])->toBe(130);
});

test('CASO 12 - channel explicito usa exactamente ese channel, no el default', function () {
    $simple = Product::factory()->simple()->create(['parent_id' => null]);
    vc_crearProductFlat($simple->id, 'Producto multi-channel QA');

    $canalDefault = core()->getDefaultChannel();
    $otroCanal = Channel::factory()->create();

    DB::table('product_inventory_indices')->insert([
        ['product_id' => $simple->id, 'channel_id' => $canalDefault->id, 'qty' => 130],
        ['product_id' => $simple->id, 'channel_id' => $otroCanal->id, 'qty' => 25],
    ]);

    $filaDefault = app(VarianteConsultaService::class)->porProductIds([$simple->id])[$simple->id];
    $filaExplicita = app(VarianteConsultaService::class)->porProductIds([$simple->id], $otroCanal->id)[$simple->id];

    expect($filaDefault['stock_actual'])->toBe(130)
        ->and($filaExplicita['stock_actual'])->toBe(25);
});

test('CASO 9 - product_id inexistente: fila con todos los campos null, sin excepcion', function () {
    $resultado = app(VarianteConsultaService::class)->porProductIds([999999999]);

    expect($resultado)->toHaveCount(1);

    $fila = $resultado[999999999];

    expect($fila['product_id'])->toBe(999999999)
        ->and($fila['sku'])->toBeNull()
        ->and($fila['nombre'])->toBeNull()
        ->and($fila['color'])->toBeNull()
        ->and($fila['talla'])->toBeNull()
        ->and($fila['stock_actual'])->toBeNull()
        ->and($fila['tipo_producto'])->toBeNull()
        ->and($fila['activo'])->toBeNull();
});

test('CASO 10 - product_id configurable: sin excepcion, color/talla/stock quedan null y tipo_producto lo identifica', function () {
    $configurable = Product::factory()->configurable()->create();
    vc_crearProductFlat($configurable->id, 'Configurable QA pasado por error');

    $color = vc_crearAtributoSelect('color', 'Color');
    DB::table('product_super_attributes')->insert([
        'product_id' => $configurable->id,
        'attribute_id' => $color->id,
    ]);

    $fila = app(VarianteConsultaService::class)->porProductIds([$configurable->id])[$configurable->id];

    expect($fila['tipo_producto'])->toBe('configurable')
        ->and($fila['sku'])->toBe($configurable->sku)
        ->and($fila['color'])->toBeNull() // el configurable no tiene product_attribute_values propio para su propio eje
        ->and($fila['talla'])->toBeNull()
        ->and($fila['stock_actual'])->toBeNull(); // Bagisto nunca indexa inventario de configurables
});

test('CASO 11 - IDs repetidos no generan resultados duplicados ni inconsistentes', function () {
    $simple = Product::factory()->simple()->create(['parent_id' => null]);
    vc_crearProductFlat($simple->id, 'Producto duplicado QA');

    $resultado = app(VarianteConsultaService::class)->porProductIds([$simple->id, $simple->id, $simple->id]);

    expect($resultado)->toHaveCount(1)
        ->and($resultado[$simple->id]['sku'])->toBe($simple->sku);
});

/*
|--------------------------------------------------------------------------
| Bloque 4: rendimiento -- CASO 4 (sin N+1).
|--------------------------------------------------------------------------
*/

/**
 * Crea $cantidad variantes completas (configurable + simple + color/talla +
 * stock) para poblar el escenario de conteo de queries.
 */
function vc_crearNVariantes(int $cantidad): array
{
    $color = vc_crearAtributoSelect('color', 'Color');
    $talla = vc_crearAtributoSelect('size', 'Talla');
    $opcionColor = vc_crearOpcion($color, 'Verde Cali QA');
    $canalDefault = core()->getDefaultChannel();

    $ids = [];

    for ($i = 0; $i < $cantidad; $i++) {
        $opcionTalla = vc_crearOpcion($talla, 'Talla-'.$i);

        ['simple' => $simple] = vc_crearVarianteConEjes(
            'Producto batch QA '.$i,
            'QA-BATCH-'.uniqid(),
            [[$color, $opcionColor], [$talla, $opcionTalla]]
        );

        DB::table('product_inventory_indices')->insert([
            'product_id' => $simple->id,
            'channel_id' => $canalDefault->id,
            'qty' => 10 + $i,
        ]);

        $ids[] = $simple->id;
    }

    return $ids;
}

test('CASO 4 - el numero de consultas SQL no crece linealmente con la cantidad de product_ids', function () {
    $idsPocos = vc_crearNVariantes(3);
    $idsMuchos = vc_crearNVariantes(30);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $resultadoPocos = app(VarianteConsultaService::class)->porProductIds($idsPocos);
    $queriesPocos = count(DB::getQueryLog());

    DB::flushQueryLog();
    $resultadoMuchos = app(VarianteConsultaService::class)->porProductIds($idsMuchos);
    $queriesMuchos = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($resultadoPocos)->toHaveCount(3)
        ->and($resultadoMuchos)->toHaveCount(30)
        // La cantidad de queries para 30 ids no debe superar por mucho la de 3
        // ids -- si hubiera N+1, 30 dispararia ~10x mas consultas que 3.
        ->and($queriesMuchos)->toBeLessThanOrEqual($queriesPocos + 1);
});
