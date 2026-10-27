<?php

use ByLopez\CorteConfeccion\Enums\AdvertenciaResolucionVarianteCorte as Advertencia;
use ByLopez\CorteConfeccion\Enums\ErrorResolucionVarianteCorte as Error;
use ByLopez\CorteConfeccion\Services\ResolvedorVarianteCorteService;
use ByLopez\CorteConfeccion\Tests\Support\CatalogoCorteFixtures;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Diagnostico de catalogo de SOLO LECTURA (F2 de Orden de Corte V2).
| Los helpers viven en tests/Support/CatalogoCorteFixtures.php.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->fx = CatalogoCorteFixtures::nuevo();
    $this->resolvedor = app(ResolvedorVarianteCorteService::class);
});

/**
 * Hallazgos de un codigo dado dentro del diagnostico acotado a la tela del test.
 */
function dc_hallazgos(array $diagnostico, string $codigo): array
{
    return array_values(array_filter($diagnostico['hallazgos'], fn (array $h) => $h['codigo'] === $codigo));
}

test('DIAG 1 - color_ambiguo: misma familia/tela/color en mas de un configurable', function () {
    $fx = $this->fx;
    $mujer = $fx->familia('Camiseta Mujer RC');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $tallas = $fx->tallas(['S']);

    $fx->configurableConEje($mujer, $rojo, $tallas, ['sku' => 'RC-110-DAMA', 'nombre' => 'Rojo Mujer']);
    $fx->configurableConEje($mujer, $rojo, $tallas, ['sku' => 'RC-124-D', 'nombre' => 'Terracota Mujer']);

    $diagnostico = $this->resolvedor->diagnosticarCatalogo($fx->tela->id);
    $ambiguos = dc_hallazgos($diagnostico, 'color_ambiguo');

    expect($ambiguos)->toHaveCount(1)
        ->and($ambiguos[0]['severidad'])->toBe('error')
        ->and($ambiguos[0]['familia'])->toBe($mujer->code)
        ->and(array_column($ambiguos[0]['configurables'], 'sku'))->toEqualCanonicalizing(['RC-110-DAMA', 'RC-124-D'])
        ->and($ambiguos[0]['detalle']['color'])->toBe('Rojo')
        ->and($diagnostico['totales']['errores'])->toBe(1);
});

test('DIAG 2 - configurable con hijos de varios colores', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $azul = $fx->opcion($fx->color, 'Azul Oscuro');
    $lila = $fx->opcion($fx->color, 'Lila');
    $tallas = $fx->tallas(['S', 'M']);

    $configurable = $fx->configurable($hombre, ['sku' => 'RC-107-H', 'nombre' => 'Azul Oscuro Hombre']);
    $fx->hijo($configurable, $azul, $tallas['S']);
    $fx->hijo($configurable, $lila, $tallas['M']);

    $hallazgos = dc_hallazgos($this->resolvedor->diagnosticarCatalogo($fx->tela->id), 'configurable_multicolor');

    expect($hallazgos)->toHaveCount(1)
        ->and($hallazgos[0]['severidad'])->toBe('error')
        ->and($hallazgos[0]['configurables'][0]['sku'])->toBe('RC-107-H')
        ->and($hallazgos[0]['detalle']['colores'])->toEqualCanonicalizing(['Azul Oscuro', 'Lila'])
        // Trae lo necesario para revisarlo: hijos con color y talla.
        ->and(collect($hallazgos[0]['configurables'][0]['hijos'])->map(fn ($h) => $h['color'].'/'.$h['eje'])->all())
        ->toEqualCanonicalizing(['Azul Oscuro/S', 'Lila/M']);
});

test('DIAG 3 - configurable sin tipo_tela: advertencia y no es candidato de ninguna tela', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $negro = $fx->opcion($fx->color, 'Negro');
    $tallas = $fx->tallas(['S']);

    $fx->configurableConEje($hombre, $negro, $tallas, ['sku' => 'RC-SIN-TELA', 'tela' => false]);

    // Sin acotar por tela se ve; acotado a la tela del test no aparece (no es candidato).
    $sinAcotar = dc_hallazgos($this->resolvedor->diagnosticarCatalogo(), 'configurable_sin_tipo_tela');
    $acotado = dc_hallazgos($this->resolvedor->diagnosticarCatalogo($fx->tela->id), 'configurable_sin_tipo_tela');

    expect(array_column(array_merge(...array_column($sinAcotar, 'configurables')), 'sku'))->toContain('RC-SIN-TELA')
        ->and($sinAcotar[0]['severidad'])->toBe('advertencia')
        ->and($acotado)->toBe([]);
});

test('DIAG 4 - configurable con mas de 2 super atributos: ejes_no_soportados', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $manga = $fx->atributo('manga_rc', 'Manga RC');
    $negro = $fx->opcion($fx->color, 'Negro');
    $tallas = $fx->tallas(['S']);

    $fx->configurableConEje($hombre, $negro, $tallas, ['sku' => 'RC-3EJES', 'ejes' => [$fx->color, $fx->size, $manga]]);

    $hallazgos = dc_hallazgos($this->resolvedor->diagnosticarCatalogo($fx->tela->id), Error::EjesNoSoportados->value);

    expect($hallazgos)->toHaveCount(1)
        ->and($hallazgos[0]['severidad'])->toBe('error')
        ->and($hallazgos[0]['detalle']['motivo'])->toContain('3 super atributos')
        ->and($hallazgos[0]['configurables'][0]['ejes'])->toEqualCanonicalizing(['color', 'size', 'manga_rc']);
});

test('DIAG 5 - simple sin color', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $tallas = $fx->tallas(['S']);

    $configurable = $fx->configurable($hombre, ['sku' => 'RC-CONF']);
    $fx->hijo($configurable, null, $tallas['S'], ['sku' => 'RC-SIN-COLOR']);

    $hallazgos = dc_hallazgos($this->resolvedor->diagnosticarCatalogo($fx->tela->id), 'simple_sin_color');

    expect($hallazgos)->toHaveCount(1)
        ->and($hallazgos[0]['severidad'])->toBe('error')
        ->and($hallazgos[0]['simples'][0]['sku'])->toBe('RC-SIN-COLOR')
        ->and($hallazgos[0]['simples'][0]['configurable_sku'])->toBe('RC-CONF');
});

test('DIAG 6 - simple sin valor del eje 2', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $negro = $fx->opcion($fx->color, 'Negro');

    $configurable = $fx->configurable($hombre, ['sku' => 'RC-CONF']);
    $fx->hijo($configurable, $negro, null, ['sku' => 'RC-SIN-TALLA']);

    $hallazgos = dc_hallazgos($this->resolvedor->diagnosticarCatalogo($fx->tela->id), 'simple_sin_eje');

    expect($hallazgos)->toHaveCount(1)
        ->and($hallazgos[0]['severidad'])->toBe('error')
        ->and($hallazgos[0]['simples'][0]['sku'])->toBe('RC-SIN-TALLA')
        ->and($hallazgos[0]['detalle']['atributo'])->toBe('size');
});

test('DIAG 7 - configurable + color + eje 2 con mas de un simple: variante_duplicada', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $negro = $fx->opcion($fx->color, 'Negro');
    $tallas = $fx->tallas(['M']);

    $caso = $fx->configurableConEje($hombre, $negro, $tallas);
    $duplicado = $fx->hijo($caso['configurable'], $negro, $tallas['M'], ['sku' => 'RC-DUP-M']);

    $hallazgos = dc_hallazgos($this->resolvedor->diagnosticarCatalogo($fx->tela->id), Error::VarianteDuplicada->value);

    expect($hallazgos)->toHaveCount(1)
        ->and($hallazgos[0]['severidad'])->toBe('error')
        ->and(array_column($hallazgos[0]['simples'], 'id'))->toEqualCanonicalizing([$caso['hijos']['M']->id, $duplicado->id]);
});

test('DIAG 8 - status EAV distinto de product_flat: advertencia informativa, no error', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $tallas = $fx->tallas(['S']);

    // Caso real 110DURZ-H: EAV inactivo, product_flat activo.
    $fx->configurableConEje($hombre, $rojo, $tallas, ['sku' => 'RC-110-H', 'activo' => false, 'flat' => true]);

    $diagnostico = $this->resolvedor->diagnosticarCatalogo($fx->tela->id);
    $hallazgos = dc_hallazgos($diagnostico, 'status_desalineado_eav_flat');

    expect($hallazgos)->toHaveCount(1)
        ->and($hallazgos[0]['severidad'])->toBe('advertencia')
        ->and($hallazgos[0]['configurables'][0]['sku'])->toBe('RC-110-H')
        ->and($hallazgos[0]['detalle']['status_eav'])->toBeFalse()
        ->and($hallazgos[0]['detalle']['status_product_flat'])->toBe([app()->getLocale() => true])
        ->and($diagnostico['totales']['errores'])->toBe(0);
});

test('DIAG 9 - configurables y simples inactivos son advertencias, NO errores de produccion', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $tallas = $fx->tallas(['S', 'M']);

    $configurable = $fx->configurable($hombre, ['sku' => 'RC-INACTIVO', 'activo' => false]);
    $fx->hijo($configurable, $rojo, $tallas['S'], ['sku' => 'RC-INACTIVO-S', 'activo' => false]);
    $fx->hijo($configurable, $rojo, $tallas['M'], ['sku' => 'RC-INACTIVO-M']);

    $diagnostico = $this->resolvedor->diagnosticarCatalogo($fx->tela->id);

    expect($diagnostico['totales']['errores'])->toBe(0)
        ->and($diagnostico['totales']['advertencias'])->toBe(2)
        ->and($diagnostico['por_codigo'])->toBe([
            Advertencia::ConfigurableInactivoEnTienda->value => 1,
            Advertencia::VarianteInactivaEnTienda->value => 1,
        ])
        ->and(dc_hallazgos($diagnostico, Advertencia::VarianteInactivaEnTienda->value)[0]['simples'][0]['sku'])->toBe('RC-INACTIVO-S');

    // Y aun asi resuelve para produccion.
    expect($this->resolvedor->resolver($fx->tela->id, $hombre->id, $rojo->id, $tallas['S']->id)->resuelta())->toBeTrue();
});

test('DIAG 10 - un catalogo sano no produce hallazgos', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $negro = $fx->opcion($fx->color, 'Negro');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $tallas = $fx->tallas(['S', 'M', 'L']);

    $fx->configurableConEje($hombre, $negro, $tallas);
    $fx->configurableConEje($hombre, $rojo, $tallas);

    $diagnostico = $this->resolvedor->diagnosticarCatalogo($fx->tela->id);

    expect($diagnostico['hallazgos'])->toBe([])
        ->and($diagnostico['configurables_afectados'])->toBe([])
        ->and($diagnostico['totales'])->toBe(['configurables' => 2, 'simples' => 6, 'errores' => 0, 'advertencias' => 0]);
});

test('DIAG 11 - simple de una familia distinta a la de su configurable', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $otra = $fx->familia('Familia ajena RC');
    $negro = $fx->opcion($fx->color, 'Negro');
    $tallas = $fx->tallas(['S']);

    $configurable = $fx->configurable($hombre);
    $fx->hijo($configurable, $negro, $tallas['S'], ['sku' => 'RC-AJENO', 'familia' => $otra->id]);

    $hallazgos = dc_hallazgos($this->resolvedor->diagnosticarCatalogo($fx->tela->id), 'simple_familia_distinta_de_padre');

    expect($hallazgos)->toHaveCount(1)
        ->and($hallazgos[0]['simples'][0]['sku'])->toBe('RC-AJENO');
});

test('DIAG 12 - los configurables afectados agrupan los codigos por configurable, errores primero', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $azul = $fx->opcion($fx->color, 'Azul Oscuro');
    $lila = $fx->opcion($fx->color, 'Lila');
    $tallas = $fx->tallas(['S', 'M']);

    $fx->configurableConEje($hombre, $lila, $tallas, ['sku' => 'RC-201-LILA']);
    $mixto = $fx->configurable($hombre, ['sku' => 'RC-107', 'activo' => false]);
    $fx->hijo($mixto, $azul, $tallas['S']);
    $fx->hijo($mixto, $lila, $tallas['M']);

    $diagnostico = $this->resolvedor->diagnosticarCatalogo($fx->tela->id);
    $afectados = collect($diagnostico['configurables_afectados'])->keyBy(fn ($a) => $a['configurable']['sku']);

    expect($afectados->keys()->all())->toBe(['RC-107', 'RC-201-LILA'])
        ->and($afectados['RC-107']['errores'])->toEqualCanonicalizing(['color_ambiguo', 'configurable_multicolor'])
        ->and($afectados['RC-107']['advertencias'])->toBe(['configurable_inactivo_en_tienda'])
        ->and($afectados['RC-201-LILA']['errores'])->toBe(['color_ambiguo'])
        ->and($diagnostico['hallazgos'][0]['severidad'])->toBe('error');
});

test('DIAG 13 - el diagnostico es de solo lectura: no modifica ninguna tabla del catalogo', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $tallas = $fx->tallas(['S']);

    $fx->configurableConEje($hombre, $rojo, $tallas, ['activo' => false, 'flat' => true]);
    $fx->configurableConEje($hombre, $rojo, $tallas);

    $huella = fn () => collect(['products', 'product_attribute_values', 'product_super_attributes', 'product_flat', 'attribute_options'])
        ->mapWithKeys(fn (string $tabla) => [$tabla => [DB::table($tabla)->count(), md5(DB::table($tabla)->get()->toJson())]])
        ->all();

    $antes = $huella();

    $this->resolvedor->diagnosticarCatalogo($fx->tela->id);
    $this->resolvedor->diagnosticarCatalogo();
    Artisan::call('corteconfeccion:diagnosticar-catalogo', ['--tipo-tela' => $fx->tela->id]);

    expect($huella())->toBe($antes);
});

test('DIAG 14 - comando artisan: salida JSON, filtro por SKU y validacion de --tipo-tela', function () {
    $fx = $this->fx;
    $mujer = $fx->familia('Camiseta Mujer RC');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $negro = $fx->opcion($fx->color, 'Negro');
    $tallas = $fx->tallas(['S']);

    $fx->configurableConEje($mujer, $rojo, $tallas, ['sku' => 'RC-110-DAMA']);
    $fx->configurableConEje($mujer, $rojo, $tallas, ['sku' => 'RC-124-D']);
    $fx->configurableConEje($mujer, $negro, $tallas, ['sku' => 'RC-106-DAMA', 'activo' => false]);

    expect(Artisan::call('corteconfeccion:diagnosticar-catalogo', ['--tipo-tela' => $fx->tela->id, '--json' => true]))->toBe(0);

    $completo = json_decode(Artisan::output(), true);

    expect($completo['alcance']['tipo_tela_option_id'])->toBe($fx->tela->id)
        ->and($completo['por_codigo'])->toMatchArray(['color_ambiguo' => 1, 'configurable_inactivo_en_tienda' => 1]);

    expect(Artisan::call('corteconfeccion:diagnosticar-catalogo', ['--tipo-tela' => $fx->tela->id, '--sku' => ['RC-106-DAMA'], '--json' => true]))->toBe(0);

    $filtrado = json_decode(Artisan::output(), true);

    expect(array_keys($filtrado['por_codigo']))->toBe(['configurable_inactivo_en_tienda'])
        ->and(array_column(array_column($filtrado['configurables_afectados'], 'configurable'), 'sku'))->toBe(['RC-106-DAMA']);

    // Salida legible: no revienta y menciona los SKU implicados.
    expect(Artisan::call('corteconfeccion:diagnosticar-catalogo', ['--tipo-tela' => $fx->tela->id]))->toBe(0)
        ->and(Artisan::output())->toContain('color_ambiguo')->toContain('RC-124-D');

    expect(Artisan::call('corteconfeccion:diagnosticar-catalogo', ['--tipo-tela' => 'abc']))->toBe(2);
});
