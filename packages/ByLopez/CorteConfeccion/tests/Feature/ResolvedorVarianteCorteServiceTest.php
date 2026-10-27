<?php

use ByLopez\CorteConfeccion\Enums\AdvertenciaResolucionVarianteCorte as Advertencia;
use ByLopez\CorteConfeccion\Enums\ErrorResolucionVarianteCorte as Error;
use ByLopez\CorteConfeccion\Exceptions\ConfiguracionCatalogoCorteException;
use ByLopez\CorteConfeccion\Services\ResolvedorVarianteCorteService;
use ByLopez\CorteConfeccion\Tests\Support\CatalogoCorteFixtures;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;

/*
|--------------------------------------------------------------------------
| ResolvedorVarianteCorteService (F2 de Orden de Corte V2).
| Fixtures en tests/Support/CatalogoCorteFixtures.php: linea = familia,
| configurable = linea x un color, tela por el atributo tipo_tela.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->fx = CatalogoCorteFixtures::nuevo();
    $this->resolvedor = app(ResolvedorVarianteCorteService::class);
});

test('CASO 1 - linea + color + talla resuelve el simple unico con snapshot', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $negro = $fx->opcion($fx->color, 'Negro');
    $tallas = $fx->tallas(['S', 'M', 'L', 'XL']);

    ['configurable' => $configurable, 'hijos' => $hijos] = $fx->configurableConEje(
        $hombre, $negro, $tallas, ['sku' => 'RC-NEGRO-H', 'nombre' => 'Camiseta Negro Hombre']
    );

    $resultado = $this->resolvedor->resolver($fx->tela->id, $hombre->id, $negro->id, $tallas['M']->id);

    expect($resultado->resuelta())->toBeTrue()
        ->and($resultado->error)->toBeNull()
        ->and($resultado->productId)->toBe($hijos['M']->id)
        ->and($resultado->sku)->toBe('RC-NEGRO-H-M')
        ->and($resultado->advertencias)->toBe([])
        ->and($resultado->configurable)->toBe([
            'id' => $configurable->id,
            'sku' => 'RC-NEGRO-H',
            'nombre' => 'Camiseta Negro Hombre',
            'activo' => true,
        ])
        ->and($resultado->snapshot)->toMatchArray([
            'attribute_family_id' => $hombre->id,
            'linea' => 'Camiseta Hombre RC',
            'color_option_id' => $negro->id,
            'color' => 'Negro',
            'eje_attribute_id' => $fx->size->id,
            'eje_code' => 'size',
            'eje_etiqueta' => $fx->size->admin_name,
            'eje_option_id' => $tallas['M']->id,
            'eje_valor' => 'M',
        ]);

    expect(Product::find($resultado->productId)->type)->toBe('simple');
});

test('CASO 2 - dos configurables de la misma familia y color: color_ambiguo con IDs, SKUs y nombres', function () {
    $fx = $this->fx;
    $mujer = $fx->familia('Camiseta Mujer RC');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $tallas = $fx->tallas(['S', 'M']);

    $a = $fx->configurableConEje($mujer, $rojo, $tallas, ['sku' => 'RC-110-DAMA', 'nombre' => 'Camiseta Rojo Mujer'])['configurable'];
    $b = $fx->configurableConEje($mujer, $rojo, $tallas, ['sku' => 'RC-124-D', 'nombre' => 'Camiseta Terracota Mujer'])['configurable'];

    $resultado = $this->resolvedor->resolver($fx->tela->id, $mujer->id, $rojo->id, $tallas['M']->id);

    expect($resultado->resuelta())->toBeFalse()
        ->and($resultado->error)->toBe(Error::ColorAmbiguo)
        ->and($resultado->productId)->toBeNull()
        ->and($resultado->sku)->toBeNull()
        ->and($resultado->detalle['configurables'])->toHaveCount(2)
        ->and(collect($resultado->detalle['configurables'])->pluck('id')->all())->toEqualCanonicalizing([$a->id, $b->id])
        ->and(collect($resultado->detalle['configurables'])->pluck('sku')->all())->toEqualCanonicalizing(['RC-110-DAMA', 'RC-124-D'])
        ->and(collect($resultado->detalle['configurables'])->pluck('nombre')->all())->toEqualCanonicalizing(['Camiseta Rojo Mujer', 'Camiseta Terracota Mujer']);
});

test('CASO 2b - la ambiguedad considera TODOS los hijos, aunque un configurable tenga todos sus hijos inactivos', function () {
    $fx = $this->fx;
    $mujer = $fx->familia('Camiseta Mujer RC');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $tallas = $fx->tallas(['S', 'M']);

    $fx->configurableConEje($mujer, $rojo, $tallas, ['sku' => 'RC-ACTIVO']);
    $inactivo = $fx->configurable($mujer, ['sku' => 'RC-INACTIVO', 'activo' => false]);
    $fx->hijo($inactivo, $rojo, $tallas['M'], ['activo' => false]);

    $resultado = $this->resolvedor->resolver($fx->tela->id, $mujer->id, $rojo->id, $tallas['M']->id);

    expect($resultado->error)->toBe(Error::ColorAmbiguo)
        ->and(collect($resultado->detalle['configurables'])->pluck('sku')->all())->toEqualCanonicalizing(['RC-ACTIVO', 'RC-INACTIVO']);
});

test('CASO 3 - mismo color y talla en familias distintas no interfieren', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $mujer = $fx->familia('Camiseta Mujer RC');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $tallas = $fx->tallas(['S', 'M']);

    $h = $fx->configurableConEje($hombre, $rojo, $tallas, ['sku' => 'RC-ROJO-H']);
    $m = $fx->configurableConEje($mujer, $rojo, $tallas, ['sku' => 'RC-ROJO-M']);

    $deHombre = $this->resolvedor->resolver($fx->tela->id, $hombre->id, $rojo->id, $tallas['M']->id);
    $deMujer = $this->resolvedor->resolver($fx->tela->id, $mujer->id, $rojo->id, $tallas['M']->id);

    expect($deHombre->productId)->toBe($h['hijos']['M']->id)
        ->and($deMujer->productId)->toBe($m['hijos']['M']->id)
        ->and($deHombre->productId)->not->toBe($deMujer->productId);
});

test('CASO 4 - cambiar el label (admin_name) del color: sigue resolviendo por ID', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $verde = $fx->opcion($fx->color, 'Verde');
    $tallas = $fx->tallas(['S', 'M']);

    $caso = $fx->configurableConEje($hombre, $verde, $tallas);

    DB::table('attribute_options')->where('id', $verde->id)->update(['admin_name' => 'Verde Cali']);

    $resultado = $this->resolvedor->resolver($fx->tela->id, $hombre->id, $verde->id, $tallas['S']->id);

    expect($resultado->resuelta())->toBeTrue()
        ->and($resultado->productId)->toBe($caso['hijos']['S']->id)
        ->and($resultado->snapshot['color'])->toBe('Verde Cali'); // la etiqueta viene del ID, no al reves
});

test('CASO 5 - configurable inactivo RESUELVE y devuelve advertencia', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $tallas = $fx->tallas(['S', 'M']);

    $caso = $fx->configurableConEje($hombre, $rojo, $tallas, ['activo' => false, 'flat' => true]);

    $resultado = $this->resolvedor->resolver($fx->tela->id, $hombre->id, $rojo->id, $tallas['M']->id);

    expect($resultado->resuelta())->toBeTrue()
        ->and($resultado->productId)->toBe($caso['hijos']['M']->id)
        ->and($resultado->advertencias)->toBe([Advertencia::ConfigurableInactivoEnTienda])
        ->and($resultado->configurable['activo'])->toBeFalse();
});

test('CASO 5b - el status sale del EAV, no de product_flat', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $tallas = $fx->tallas(['S']);

    // EAV activo pero product_flat desactualizado en 0: sin advertencia.
    $fx->configurableConEje($hombre, $rojo, $tallas, ['activo' => true, 'flat' => false]);

    $resultado = $this->resolvedor->resolver($fx->tela->id, $hombre->id, $rojo->id, $tallas['S']->id);

    expect($resultado->resuelta())->toBeTrue()
        ->and($resultado->advertencias)->toBe([]);
});

test('CASO 5c - status ausente en el EAV cuenta como inactivo en tienda, sin bloquear', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $tallas = $fx->tallas(['S']);

    $fx->configurableConEje($hombre, $rojo, $tallas, ['activo' => null, 'flat' => null]);

    $resultado = $this->resolvedor->resolver($fx->tela->id, $hombre->id, $rojo->id, $tallas['S']->id);

    expect($resultado->resuelta())->toBeTrue()
        ->and($resultado->advertencias)->toContain(Advertencia::ConfigurableInactivoEnTienda);
});

test('CASO 6 - simple inactivo RESUELVE y devuelve advertencia', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $tallas = $fx->tallas(['S', 'M']);

    $configurable = $fx->configurable($hombre, ['sku' => 'RC-ROJO']);
    $fx->hijo($configurable, $rojo, $tallas['S']);
    $inactivo = $fx->hijo($configurable, $rojo, $tallas['M'], ['activo' => false, 'flat' => true]);

    $resultado = $this->resolvedor->resolver($fx->tela->id, $hombre->id, $rojo->id, $tallas['M']->id);

    expect($resultado->resuelta())->toBeTrue()
        ->and($resultado->productId)->toBe($inactivo->id)
        ->and($resultado->advertencias)->toBe([Advertencia::VarianteInactivaEnTienda]);

    // El hermano activo no arrastra la advertencia.
    expect($this->resolvedor->resolver($fx->tela->id, $hombre->id, $rojo->id, $tallas['S']->id)->advertencias)->toBe([]);
});

test('CASO 7 - combinacion inexistente: talla_no_disponible', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $azul = $fx->opcion($fx->color, 'Azul Oscuro');
    $tallas = $fx->tallas(['S', 'M', 'XL']);

    $fx->configurableConEje($hombre, $azul, ['S' => $tallas['S'], 'M' => $tallas['M']], ['sku' => 'RC-AZUL']);

    $resultado = $this->resolvedor->resolver($fx->tela->id, $hombre->id, $azul->id, $tallas['XL']->id);

    expect($resultado->error)->toBe(Error::TallaNoDisponible)
        ->and($resultado->productId)->toBeNull()
        ->and($resultado->configurable['sku'])->toBe('RC-AZUL');
});

test('CASO 7b - color sin ningun configurable en la linea: sin_producto_para_color', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $azul = $fx->opcion($fx->color, 'Azul Oscuro');
    $cafe = $fx->opcion($fx->color, 'Cafe');
    $tallas = $fx->tallas(['S']);

    $fx->configurableConEje($hombre, $azul, $tallas);

    $resultado = $this->resolvedor->resolver($fx->tela->id, $hombre->id, $cafe->id, $tallas['S']->id);

    expect($resultado->error)->toBe(Error::SinProductoParaColor)
        ->and($resultado->detalle['configurables_candidatos'])->toBe(1);
});

test('CASO 8 - Nino: detecta grupo_de_edad_ninos como eje 2 y lo resuelve', function () {
    $fx = $this->fx;
    $nino = $fx->familia('Camiseta Nino RC');
    $grupoEdad = $fx->atributo('grupo_de_edad_ninos', 'grupo de edad (niños)');
    $negro = $fx->opcion($fx->color, 'Negro');
    $grupos = [
        '2-4' => $fx->opcion($grupoEdad, '2-4', 1),
        '6-8' => $fx->opcion($grupoEdad, '6-8', 2),
    ];

    $caso = $fx->configurableConEje($nino, $negro, $grupos, ['ejes' => [$fx->color, $grupoEdad]]);

    $opciones = $this->resolvedor->opcionesEje($fx->tela->id, $nino->id, $negro->id);

    expect($opciones['error'])->toBeNull()
        ->and($opciones['eje'])->toBe([
            'attribute_id' => $grupoEdad->id,
            'code' => 'grupo_de_edad_ninos',
            'etiqueta' => $grupoEdad->admin_name,
        ])
        ->and(array_column($opciones['opciones'], 'label'))->toBe(['2-4', '6-8']);

    $resultado = $this->resolvedor->resolver($fx->tela->id, $nino->id, $negro->id, $grupos['6-8']->id);

    expect($resultado->productId)->toBe($caso['hijos']['6-8']->id)
        ->and($resultado->snapshot['eje_code'])->toBe('grupo_de_edad_ninos')
        ->and($resultado->snapshot['eje_valor'])->toBe('6-8');
});

test('CASO 9 - una talla del atributo SIN hijo real (XXL) no aparece, y se ordena por sort_order', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $negro = $fx->opcion($fx->color, 'Negro');
    $tallas = $fx->tallas(['S', 'M', 'L', 'XL', 'XXL']);

    // Hijos creados fuera de orden: el orden final lo da sort_order, no el de insercion.
    $fx->configurableConEje($hombre, $negro, [
        'L' => $tallas['L'], 'S' => $tallas['S'], 'XL' => $tallas['XL'], 'M' => $tallas['M'],
    ]);

    $opciones = $this->resolvedor->opcionesEje($fx->tela->id, $hombre->id, $negro->id);

    expect(array_column($opciones['opciones'], 'label'))->toBe(['S', 'M', 'L', 'XL'])
        ->and(array_column($opciones['opciones'], 'label'))->not->toContain('XXL')
        ->and(array_column($opciones['opciones'], 'variantes'))->toBe([1, 1, 1, 1]);
});

test('CASO 9b - configurable con hijos de dos colores (caso real 107DURZ-H): cada color ve solo sus propias tallas', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $azul = $fx->opcion($fx->color, 'Azul Oscuro');
    $lila = $fx->opcion($fx->color, 'Lila');
    $tallas = $fx->tallas(['S', 'M', 'L', 'XL']);

    $configurable = $fx->configurable($hombre, ['sku' => 'RC-107-H']);
    $fx->hijo($configurable, $azul, $tallas['S']);
    $fx->hijo($configurable, $azul, $tallas['M']);
    $fx->hijo($configurable, $azul, $tallas['L']);
    $xlLila = $fx->hijo($configurable, $lila, $tallas['XL']);

    $deAzul = $this->resolvedor->opcionesEje($fx->tela->id, $hombre->id, $azul->id);
    $deLila = $this->resolvedor->opcionesEje($fx->tela->id, $hombre->id, $lila->id);

    expect(array_column($deAzul['opciones'], 'label'))->toBe(['S', 'M', 'L'])
        ->and(array_column($deLila['opciones'], 'label'))->toBe(['XL'])
        ->and($this->resolvedor->resolver($fx->tela->id, $hombre->id, $azul->id, $tallas['XL']->id)->error)->toBe(Error::TallaNoDisponible)
        ->and($this->resolvedor->resolver($fx->tela->id, $hombre->id, $lila->id, $tallas['XL']->id)->productId)->toBe($xlLila->id);
});

test('CASO 10 - al agregar un hijo XXL, la opcion aparece (sin cache entre llamadas)', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $negro = $fx->opcion($fx->color, 'Negro');
    $tallas = $fx->tallas(['S', 'M', 'XXL']);

    $caso = $fx->configurableConEje($hombre, $negro, ['S' => $tallas['S'], 'M' => $tallas['M']]);

    expect(array_column($this->resolvedor->opcionesEje($fx->tela->id, $hombre->id, $negro->id)['opciones'], 'label'))
        ->toBe(['S', 'M']);

    $fx->hijo($caso['configurable'], $negro, $tallas['XXL']);

    expect(array_column($this->resolvedor->opcionesEje($fx->tela->id, $hombre->id, $negro->id)['opciones'], 'label'))
        ->toBe(['S', 'M', 'XXL']);
});

test('CASO 11 - configurable con 3 ejes: ejes_no_soportados y no se lista como linea', function () {
    $fx = $this->fx;
    $solo3 = $fx->familia('Linea 3 ejes RC');
    $mixta = $fx->familia('Linea mixta RC');
    $manga = $fx->atributo('manga_rc', 'Manga RC');
    $negro = $fx->opcion($fx->color, 'Negro');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $tallas = $fx->tallas(['S']);

    $fx->configurableConEje($solo3, $negro, $tallas, ['sku' => 'RC-3EJES', 'ejes' => [$fx->color, $fx->size, $manga]]);
    $fx->configurableConEje($mixta, $rojo, $tallas, ['sku' => 'RC-OK']);
    $fx->configurableConEje($mixta, $negro, $tallas, ['sku' => 'RC-3EJES-B', 'ejes' => [$fx->color, $fx->size, $manga]]);

    $resultado = $this->resolvedor->resolver($fx->tela->id, $solo3->id, $negro->id, $tallas['S']->id);

    expect($resultado->error)->toBe(Error::EjesNoSoportados)
        ->and($resultado->detalle['ejes'])->toEqualCanonicalizing(['color', 'size', 'manga_rc'])
        ->and($resultado->detalle['motivo'])->toContain('3 super atributos')
        ->and($resultado->configurable['sku'])->toBe('RC-3EJES');

    $lineas = collect($this->resolvedor->lineasPorTipoTela($fx->tela->id))->keyBy('attribute_family_id');

    expect($lineas->has($solo3->id))->toBeFalse()   // solo tiene configurables no soportados
        ->and($lineas[$mixta->id]['configurables'])->toBe(1)
        ->and($lineas[$mixta->id]['configurables_no_soportados'])->toBe(1);
});

test('CASO 11b - configurable sin el super atributo color tampoco se soporta', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $negro = $fx->opcion($fx->color, 'Negro');
    $tallas = $fx->tallas(['S']);

    $fx->configurableConEje($hombre, $negro, $tallas, ['ejes' => [$fx->size]]);

    $resultado = $this->resolvedor->resolver($fx->tela->id, $hombre->id, $negro->id, $tallas['S']->id);

    expect($resultado->error)->toBe(Error::EjesNoSoportados)
        ->and($resultado->detalle['motivo'])->toContain('color');
});

test('CASO 12 - configurable sin tipo_tela o con otra tela: no es candidato', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $negro = $fx->opcion($fx->color, 'Negro');
    $otraTela = $fx->opcion($fx->tipoTela, 'Otra tela RC');
    $tallas = $fx->tallas(['S']);

    $fx->configurableConEje($hombre, $negro, $tallas, ['sku' => 'RC-SIN-TELA', 'tela' => false]);
    $fx->configurableConEje($hombre, $negro, $tallas, ['sku' => 'RC-OTRA-TELA', 'tela' => $otraTela]);

    $resultado = $this->resolvedor->resolver($fx->tela->id, $hombre->id, $negro->id, $tallas['S']->id);

    expect($resultado->error)->toBe(Error::SinProductoParaColor)
        ->and($resultado->detalle['configurables_candidatos'])->toBe(0)
        ->and($this->resolvedor->lineasPorTipoTela($fx->tela->id))->toBe([])
        ->and(collect($this->resolvedor->lineasPorTipoTela($otraTela->id))->pluck('attribute_family_id')->all())->toBe([$hombre->id]);
});

test('CASO 13 - varias familias para la misma tela: lista todas las lineas validas', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $nino = $fx->familia('Camisetas Nino RC');
    $crop = $fx->familia('Crop Top RC');
    $ajena = $fx->familia('Familia de otra tela RC');
    $grupoEdad = $fx->atributo('grupo_de_edad_ninos', 'grupo de edad (niños)');
    $negro = $fx->opcion($fx->color, 'Negro');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $otraTela = $fx->opcion($fx->tipoTela, 'Otra tela RC');
    $tallas = $fx->tallas(['S', 'M']);
    $grupos = ['2-4' => $fx->opcion($grupoEdad, '2-4', 1)];

    $fx->configurableConEje($hombre, $negro, $tallas);
    $fx->configurableConEje($hombre, $rojo, $tallas);
    $fx->configurableConEje($nino, $negro, $grupos, ['ejes' => [$fx->color, $grupoEdad]]);
    $fx->configurableConEje($crop, $negro, ['M' => $tallas['M']]);
    $fx->configurableConEje($ajena, $negro, $tallas, ['tela' => $otraTela]);

    $lineas = $this->resolvedor->lineasPorTipoTela($fx->tela->id);

    expect(array_column($lineas, 'attribute_family_id'))->toEqualCanonicalizing([$hombre->id, $nino->id, $crop->id])
        // ordenadas por nombre de familia
        ->and(array_column($lineas, 'nombre'))->toBe(['Camiseta Hombre RC', 'Camisetas Nino RC', 'Crop Top RC']);

    $porFamilia = collect($lineas)->keyBy('attribute_family_id');

    expect($porFamilia[$hombre->id]['configurables'])->toBe(2)
        ->and($porFamilia[$hombre->id]['configurables_no_soportados'])->toBe(0)
        ->and($porFamilia[$hombre->id]['codigo'])->toBe($hombre->code)
        ->and($porFamilia[$hombre->id]['ejes'])->toBe([['attribute_id' => $fx->size->id, 'code' => 'size', 'etiqueta' => $fx->size->admin_name]])
        ->and($porFamilia[$nino->id]['ejes'][0]['code'])->toBe('grupo_de_edad_ninos');
});

test('CASO 14 - nunca devuelve un configurable como producto final', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $negro = $fx->opcion($fx->color, 'Negro');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $tallas = $fx->tallas(['S', 'M']);

    $conHijos = $fx->configurableConEje($hombre, $negro, $tallas);
    $sinHijos = $fx->configurable($hombre, ['sku' => 'RC-SIN-HIJOS']);   // configurable sin ningun simple
    $sinHijosRojo = $fx->configurable($fx->familia('Linea vacia RC'), ['sku' => 'RC-VACIO']);

    $resultados = $this->resolvedor->resolverLote($fx->tela->id, [
        'ok' => ['attribute_family_id' => $hombre->id, 'color_option_id' => $negro->id, 'eje_option_id' => $tallas['S']->id],
        'sin_color' => ['attribute_family_id' => $hombre->id, 'color_option_id' => $rojo->id, 'eje_option_id' => $tallas['S']->id],
        'linea_vacia' => ['attribute_family_id' => $sinHijosRojo->attribute_family_id, 'color_option_id' => $negro->id, 'eje_option_id' => $tallas['S']->id],
    ]);

    $configurableIds = [$conHijos['configurable']->id, $sinHijos->id, $sinHijosRojo->id];

    expect($resultados['ok']->productId)->toBe($conHijos['hijos']['S']->id)
        ->and($resultados['sin_color']->productId)->toBeNull()
        ->and($resultados['linea_vacia']->productId)->toBeNull();

    foreach ($resultados as $resultado) {
        if ($resultado->productId !== null) {
            expect($configurableIds)->not->toContain($resultado->productId)
                ->and(Product::find($resultado->productId)->type)->toBe('simple');
        }
    }
});

test('CASO 15 - configurable + color + eje 2 duplicado: variante_duplicada', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $negro = $fx->opcion($fx->color, 'Negro');
    $tallas = $fx->tallas(['S', 'M']);

    $caso = $fx->configurableConEje($hombre, $negro, $tallas);
    $duplicado = $fx->hijo($caso['configurable'], $negro, $tallas['M'], ['sku' => 'RC-DUP-M']);

    $resultado = $this->resolvedor->resolver($fx->tela->id, $hombre->id, $negro->id, $tallas['M']->id);

    expect($resultado->error)->toBe(Error::VarianteDuplicada)
        ->and($resultado->productId)->toBeNull()
        ->and(collect($resultado->detalle['simples'])->pluck('id')->all())->toEqualCanonicalizing([$caso['hijos']['M']->id, $duplicado->id])
        // la otra talla del mismo configurable sigue resolviendo
        ->and($this->resolvedor->resolver($fx->tela->id, $hombre->id, $negro->id, $tallas['S']->id)->productId)->toBe($caso['hijos']['S']->id);
});

test('CASO 16 - dos colores con el MISMO admin_name se distinguen por ID (nunca por texto)', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $rojoA = $fx->opcion($fx->color, 'Rojo');
    $rojoB = $fx->opcion($fx->color, 'Rojo');
    $tallas = $fx->tallas(['S']);

    $a = $fx->configurableConEje($hombre, $rojoA, $tallas, ['sku' => 'RC-ROJO-A']);
    $b = $fx->configurableConEje($hombre, $rojoB, $tallas, ['sku' => 'RC-ROJO-B']);

    $deA = $this->resolvedor->resolver($fx->tela->id, $hombre->id, $rojoA->id, $tallas['S']->id);
    $deB = $this->resolvedor->resolver($fx->tela->id, $hombre->id, $rojoB->id, $tallas['S']->id);

    expect($deA->productId)->toBe($a['hijos']['S']->id)
        ->and($deB->productId)->toBe($b['hijos']['S']->id)
        ->and($deA->snapshot['color'])->toBe($deB->snapshot['color']);
});

test('CASO 17 - falta un atributo estructural: falla cerrado con error claro de configuracion', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $negro = $fx->opcion($fx->color, 'Negro');
    $tallas = $fx->tallas(['S']);
    $fx->configurableConEje($hombre, $negro, $tallas);

    foreach (['color', 'tipo_tela', 'status'] as $code) {
        DB::table('attributes')->where('code', $code)->update(['code' => $code.'_renombrado']);

        expect(fn () => $this->resolvedor->resolver($fx->tela->id, $hombre->id, $negro->id, $tallas['S']->id))
            ->toThrow(ConfiguracionCatalogoCorteException::class, $code);

        DB::table('attributes')->where('code', $code.'_renombrado')->update(['code' => $code]);
    }

    // Restaurados: vuelve a resolver.
    expect($this->resolvedor->resolver($fx->tela->id, $hombre->id, $negro->id, $tallas['S']->id)->resuelta())->toBeTrue();
});

test('CASO 18 - filtros opcionales estructurados desambiguan una linea (familia + cuello)', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $cuello = $fx->atributo('cuello', 'Cuello');
    $redondo = $fx->opcion($cuello, 'Redondo');
    $enV = $fx->opcion($cuello, 'V');
    $negro = $fx->opcion($fx->color, 'Negro');
    $tallas = $fx->tallas(['S']);

    $r = $fx->configurableConEje($hombre, $negro, $tallas, ['sku' => 'RC-REDONDO', 'valores' => [$redondo]]);
    $v = $fx->configurableConEje($hombre, $negro, $tallas, ['sku' => 'RC-V', 'valores' => [$enV]]);

    // Sin filtro: la linea es solo la familia y hay dos configurables negros.
    expect($this->resolvedor->resolver($fx->tela->id, $hombre->id, $negro->id, $tallas['S']->id)->error)->toBe(Error::ColorAmbiguo);

    // Con filtro de cuello la linea queda definida sin ambiguedad.
    $conV = $this->resolvedor->resolver($fx->tela->id, $hombre->id, $negro->id, $tallas['S']->id, ['cuello' => $enV->id]);
    $conRedondo = $this->resolvedor->resolver($fx->tela->id, $hombre->id, $negro->id, $tallas['S']->id, ['cuello' => $redondo->id]);

    expect($conV->productId)->toBe($v['hijos']['S']->id)
        ->and($conRedondo->productId)->toBe($r['hijos']['S']->id);

    // Un filtro sobre un atributo inexistente es un error de configuracion, no "sin resultados".
    expect(fn () => $this->resolvedor->resolver($fx->tela->id, $hombre->id, $negro->id, $tallas['S']->id, ['atributo_que_no_existe' => 1]))
        ->toThrow(ConfiguracionCatalogoCorteException::class, 'atributo_que_no_existe');
});

test('CASO 19 - un simple de otra familia que su configurable incumple la postcondicion', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $otraFamilia = $fx->familia('Familia ajena RC');
    $negro = $fx->opcion($fx->color, 'Negro');
    $tallas = $fx->tallas(['S']);

    $configurable = $fx->configurable($hombre);
    $hijo = $fx->hijo($configurable, $negro, $tallas['S'], ['familia' => $otraFamilia->id]);

    $resultado = $this->resolvedor->resolver($fx->tela->id, $hombre->id, $negro->id, $tallas['S']->id);

    expect($resultado->error)->toBe(Error::PostcondicionIncumplida)
        ->and($resultado->productId)->toBeNull()
        ->and($resultado->detalle['simple']['id'])->toBe($hijo->id)
        ->and($resultado->detalle['attribute_family_id_esperada'])->toBe($hombre->id);
});

test('CASO 20 - opcionesEje propaga el error cuando el color es ambiguo y no lista opciones', function () {
    $fx = $this->fx;
    $mujer = $fx->familia('Camiseta Mujer RC');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $tallas = $fx->tallas(['S', 'M']);

    $fx->configurableConEje($mujer, $rojo, $tallas);
    $fx->configurableConEje($mujer, $rojo, $tallas);

    $opciones = $this->resolvedor->opcionesEje($fx->tela->id, $mujer->id, $rojo->id);

    expect($opciones['error'])->toBe(Error::ColorAmbiguo)
        ->and($opciones['opciones'])->toBe([])
        ->and($opciones['configurable'])->toBeNull()
        ->and($opciones['detalle']['configurables'])->toHaveCount(2);
});

test('CASO 21 - los lotes conservan las claves de entrada y mezclan aciertos con errores', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $negro = $fx->opcion($fx->color, 'Negro');
    $tallas = $fx->tallas(['S', 'M', 'L']);

    $caso = $fx->configurableConEje($hombre, $negro, ['S' => $tallas['S'], 'M' => $tallas['M']]);

    $resultados = $this->resolvedor->resolverLote($fx->tela->id, [
        'celda-negro-S' => ['attribute_family_id' => $hombre->id, 'color_option_id' => $negro->id, 'eje_option_id' => $tallas['S']->id],
        'celda-negro-L' => ['attribute_family_id' => $hombre->id, 'color_option_id' => $negro->id, 'eje_option_id' => $tallas['L']->id],
    ]);

    expect(array_keys($resultados))->toBe(['celda-negro-S', 'celda-negro-L'])
        ->and($resultados['celda-negro-S']->productId)->toBe($caso['hijos']['S']->id)
        ->and($resultados['celda-negro-L']->error)->toBe(Error::TallaNoDisponible);

    $lote = $this->resolvedor->opcionesEjeLote($fx->tela->id, [
        'h-negro' => ['attribute_family_id' => $hombre->id, 'color_option_id' => $negro->id],
    ]);

    expect(array_column($lote['h-negro']['opciones'], 'label'))->toBe(['S', 'M']);

    // toArray() es serializable (F4 lo enviara al frontend).
    expect($resultados['celda-negro-S']->toArray())->toMatchArray(['resuelta' => true, 'error' => null, 'advertencias' => []])
        ->and($resultados['celda-negro-L']->toArray())->toMatchArray(['resuelta' => false, 'error' => 'talla_no_disponible']);
});

test('CASO 22 - el numero de consultas es fijo, no crece con la cantidad de celdas (sin N+1)', function () {
    $fx = $this->fx;
    $hombre = $fx->familia('Camiseta Hombre RC');
    $tallas = $fx->tallas(['S', 'M', 'L', 'XL']);

    $combinaciones = [];

    for ($i = 1; $i <= 15; $i++) {
        $color = $fx->opcion($fx->color, 'Color RC '.$i);
        $fx->configurableConEje($hombre, $color, $tallas);

        foreach ($tallas as $talla) {
            $combinaciones[] = [
                'attribute_family_id' => $hombre->id,
                'color_option_id' => $color->id,
                'eje_option_id' => $talla->id,
            ];
        }
    }

    $pocas = array_slice($combinaciones, 0, 4);

    // Calentamiento: resuelve el canal por defecto y cualquier cache interno de Bagisto.
    $this->resolvedor->resolverLote($fx->tela->id, $pocas);

    DB::enableQueryLog();

    DB::flushQueryLog();
    $resultadosPocas = $this->resolvedor->resolverLote($fx->tela->id, $pocas);
    $queriesPocas = count(DB::getQueryLog());

    DB::flushQueryLog();
    $resultadosTodas = $this->resolvedor->resolverLote($fx->tela->id, $combinaciones);
    $queriesTodas = count(DB::getQueryLog());

    DB::flushQueryLog();
    $this->resolvedor->opcionesEjeLote($fx->tela->id, array_map(
        fn (array $c) => ['attribute_family_id' => $c['attribute_family_id'], 'color_option_id' => $c['color_option_id']],
        $combinaciones
    ));
    $queriesOpciones = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($resultadosPocas)->toHaveCount(4)
        ->and($resultadosTodas)->toHaveCount(60)
        ->and(collect($resultadosTodas)->every(fn ($r) => $r->resuelta()))->toBeTrue()
        ->and($queriesTodas)->toBe($queriesPocas)
        ->and($queriesOpciones)->toBe($queriesPocas)
        ->and($queriesTodas)->toBeLessThanOrEqual(10);
});
