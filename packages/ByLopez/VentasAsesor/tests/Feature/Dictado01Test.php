<?php

use ByLopez\VentasAsesor\Services\Catalogo\CodigoSkuService;
use ByLopez\VentasAsesor\Services\Venta\Dictado\ResolutorDictado;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../helpersFase03b.php';

uses(TestCase::class);

/*
| DIC-01 — Doc. 34: el texto dictado se valida contra el catalogo con el codigo unico (Doc. 33). Catalogo de fixture con codigos
| 9xx para no chocar con productos existentes de lpz_saas_testing.
*/

function dic01_opcion(string $texto): int
{
    $aid = DB::table('attributes')->where('code', 'color')->value('id');
    $id = DB::table('attribute_options')->where('attribute_id', $aid)->where('admin_name', $texto)->value('id');

    return (int) ($id ?: DB::table('attribute_options')->insertGetId(['attribute_id' => $aid, 'admin_name' => $texto, 'sort_order' => 999]));
}

/** Configurable con SKU del patron: padre «$base», variantes «$base-<talla>». @return array<string, int> talla → id de variante */
function dic01_producto(string $base, array $tallas, string $nombre): array
{
    $padre = f03b_configurable(10000.0);
    DB::table('products')->where('id', $padre->id)->update(['sku' => $base]);
    DB::table('product_flat')->where('product_id', $padre->id)->update(['name' => $nombre, 'status' => 1]);
    $ids = [];

    foreach ($padre->variants()->orderBy('id')->get()->values() as $i => $variante) {
        if (! isset($tallas[$i])) {
            break;
        }
        DB::table('products')->where('id', $variante->id)->update(['sku' => $base.'-'.$tallas[$i]]);
        $ids[$tallas[$i]] = (int) $variante->id;
    }

    return ['padre' => (int) $padre->id] + $ids;
}

beforeEach(function () {
    DB::table(CodigoSkuService::TABLA_COLORES)->delete();
    foreach (['Rojo' => 910, 'Azul Rey' => 908, 'Azul Oscuro' => 907, 'Negro' => 906] as $color => $codigo) {
        DB::table(CodigoSkuService::TABLA_COLORES)->insert(['attribute_option_id' => dic01_opcion($color), 'codigo' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->rojoNino = dic01_producto('910N-DZ', ['2', '6'], 'Camiseta Piel Durazno Rojo Niño');
    $this->azulDama = dic01_producto('908D-DZ', ['S', 'M'], 'Camiseta Piel Durazno Azul Rey Mujer');
    $this->negroDama = dic01_producto('906D-DZ', ['M'], 'Camiseta Piel Durazno Negro Mujer');
    $this->negroCaballero = dic01_producto('906H-DZ', ['M'], 'Camiseta Piel Durazno Negro Hombre');
});

test('DIC01-1 el ejemplo de Camilo resuelve las tres variantes exactas', function () {
    $lineas = app(ResolutorDictado::class)->interpretar('roja 2-4, 2 unidades, roja 6-8, 10 unidades, azul rey talla S de dama, 15 unidades');

    expect(array_column($lineas, 'estado'))->toBe(['ok', 'ok', 'ok'])
        ->and(array_column($lineas, 'sku'))->toBe(['910N-DZ-2', '910N-DZ-6', '908D-DZ-S'])
        ->and(array_column($lineas, 'variante_id'))->toBe([$this->rojoNino['2'], $this->rojoNino['6'], $this->azulDama['S']])
        ->and(array_column($lineas, 'producto_id'))->toBe([$this->rojoNino['padre'], $this->rojoNino['padre'], $this->azulDama['padre']])
        ->and(array_column($lineas, 'cantidad'))->toBe([2, 10, 15])
        ->and($lineas[2]['nombre'])->toBe('Camiseta Piel Durazno Azul Rey Mujer');
});

test('DIC01-2 sin genero: si el color y la talla existen en una sola linea se toma con aviso; si en dos, se pregunta', function () {
    $resolutor = app(ResolutorDictado::class);

    [$azul] = $resolutor->interpretar('azul rey M 4');
    [$negro] = $resolutor->interpretar('negro M 4');

    expect($azul['estado'])->toBe('aviso')->and($azul['sku'])->toBe('908D-DZ-M')->and($azul['mensaje'])->toContain('Dama')
        ->and($negro['estado'])->toBe('error')->and($negro['variante_id'])->toBeNull()->and($negro['mensaje'])->toContain('dama o caballero');
});

test('DIC01-3 lo que no existe o no se entiende queda en error con el motivo', function () {
    $resolutor = app(ResolutorDictado::class);

    [$talla] = $resolutor->interpretar('azul rey talla XL de dama 3');
    [$linea] = $resolutor->interpretar('roja talla M de caballero 3');
    [$ambiguo] = $resolutor->interpretar('azul talla S de dama 3');

    expect($talla['estado'])->toBe('error')->and($talla['mensaje'])->toBe('La variante no existe: Azul Rey Dama no tiene talla XL.')->and($talla['tipo_error'])->toBe('catalogo')
        ->and([$talla['color'], $talla['genero'], $talla['talla_etiqueta'], $talla['cantidad']])->toBe(['Azul Rey', 'D', 'XL', 3])
        ->and($linea['mensaje'])->toBe('Ese color no existe para Caballero: no hay Rojo.')
        ->and($ambiguo['mensaje'])->toContain('¿Cuál color?')->and($ambiguo['mensaje'])->toContain('Azul Rey');
});

test('DIC01-4 la ruta exige el permiso de Nueva venta y devuelve las lineas', function () {
    $url = route('admin.ventas_asesor.nueva_venta.dictado.interpretar');

    $this->actingAs(f03b_admin('custom', ['ventas_asesor', 'ventas_asesor.pedidos']), 'admin')
        ->postJson($url, ['texto' => 'roja 2-4 2'])->assertUnauthorized();

    $this->actingAs(f03b_admin('custom', ['ventas_asesor', 'ventas_asesor.pedidos', 'ventas_asesor.pedidos.create']), 'admin')
        ->postJson($url, ['texto' => 'roja 2-4, 2 unidades'])
        ->assertOk()->assertJsonPath('lineas.0.sku', '910N-DZ-2')->assertJsonPath('lineas.0.cantidad', 2);

    $this->actingAs(f03b_admin(), 'admin')->postJson($url, ['texto' => ''])->assertUnprocessable();
});

test('DIC01-5 la pantalla de Nueva venta muestra «Dictar pedido» y se apaga con la configuracion', function () {
    $admin = f03b_admin();

    $this->actingAs($admin, 'admin')->get(route('admin.ventas_asesor.nueva_venta.pantalla'))
        ->assertOk()->assertSee('data-venta="dictado"', false)->assertSee(route('admin.ventas_asesor.nueva_venta.dictado.interpretar'), false);

    config()->set('ventasasesor.dictado.activo', false);
    $this->actingAs($admin, 'admin')->get(route('admin.ventas_asesor.nueva_venta.pantalla'))->assertOk()->assertDontSee('data-venta="dictado"', false);
    $this->actingAs($admin, 'admin')->postJson(route('admin.ventas_asesor.nueva_venta.dictado.interpretar'), ['texto' => 'roja 2-4 2'])->assertNotFound();
});

test('DIC01-6 corregir una linea con los selectores la valida otra vez contra el catalogo', function () {
    $url = route('admin.ventas_asesor.nueva_venta.dictado.corregir');
    $admin = f03b_admin();

    // ❌ «negro M 4» (dama o caballero) corregida eligiendo Caballero.
    $this->actingAs($admin, 'admin')
        ->postJson($url, ['texto' => 'negro M 4', 'color' => 'Negro', 'genero' => 'H', 'linea' => 'DZ', 'talla' => 'M', 'cantidad' => 4])
        ->assertOk()
        ->assertJsonPath('linea.estado', 'ok')
        ->assertJsonPath('linea.sku', '906H-DZ-M')
        ->assertJsonPath('linea.variante_id', $this->negroCaballero['M'])
        ->assertJsonPath('linea.producto_id', $this->negroCaballero['padre'])
        ->assertJsonPath('linea.cantidad', 4)
        ->assertJsonPath('linea.texto', 'negro M 4')
        ->assertJsonPath('linea.corregida', true);

    // Una combinacion que no existe sigue en error con el motivo.
    $this->actingAs($admin, 'admin')
        ->postJson($url, ['color' => 'Azul Rey', 'genero' => 'D', 'linea' => 'DZ', 'talla' => 'XL', 'cantidad' => 2])
        ->assertOk()->assertJsonPath('linea.estado', 'error')->assertJsonPath('linea.mensaje', 'La variante no existe: Azul Rey Dama no tiene talla XL.');

    // Datos fuera de lo que ofrecen los selectores: 422.
    $this->actingAs($admin, 'admin')->postJson($url, ['color' => 'Morado Inventado', 'genero' => 'D', 'linea' => 'DZ', 'talla' => 'M', 'cantidad' => 2])
        ->assertUnprocessable()->assertJsonValidationErrors('color');
    $this->actingAs($admin, 'admin')->postJson($url, ['color' => 'Negro', 'linea' => 'DZ', 'talla' => 'M', 'cantidad' => 2])
        ->assertUnprocessable()->assertJsonValidationErrors('genero');
    $this->actingAs($admin, 'admin')->postJson($url, ['color' => 'Negro', 'genero' => 'D', 'linea' => 'DZ', 'talla' => 'XXXL', 'cantidad' => 0])
        ->assertUnprocessable()->assertJsonValidationErrors(['talla', 'cantidad']);

    // Sin el permiso de Nueva venta no se puede.
    $this->actingAs(f03b_admin('custom', ['ventas_asesor', 'ventas_asesor.pedidos']), 'admin')
        ->postJson($url, ['color' => 'Negro', 'genero' => 'H', 'linea' => 'DZ', 'talla' => 'M', 'cantidad' => 4])->assertUnauthorized();
});

test('DIC01-7 interpretar devuelve los colores y tallas para los selectores; la pantalla trae Corregir y Omitir', function () {
    $admin = f03b_admin();

    $this->actingAs($admin, 'admin')->postJson(route('admin.ventas_asesor.nueva_venta.dictado.interpretar'), ['texto' => 'azul S 3'])
        ->assertOk()
        ->assertJsonPath('lineas.0.estado', 'error')
        ->assertJsonPath('colores', ['Negro', 'Azul Oscuro', 'Azul Rey', 'Rojo'])
        ->assertJsonPath('tallas.2', '2-4')
        ->assertJsonPath('tallas.U', 'Única');

    $this->actingAs($admin, 'admin')->get(route('admin.ventas_asesor.nueva_venta.pantalla'))
        ->assertOk()
        ->assertSee('data-venta="dictado-corregir"', false)
        ->assertSee('data-venta="dictado-omitir"', false)
        ->assertSee(route('admin.ventas_asesor.nueva_venta.dictado.corregir'), false);
});

test('DIC01-8 prioridad: «roja 68 niño 10 y negro dama M 2» da dos lineas exactas, sin numeros de otra linea', function () {
    $lineas = app(ResolutorDictado::class)->interpretar('roja 68 niño 10 y negro dama M 2');

    expect($lineas)->toHaveCount(2)
        ->and(array_map(fn ($l) => [$l['estado'], $l['sku'], $l['variante_id'], $l['genero'], $l['color'], $l['talla_etiqueta'], $l['cantidad'], $l['problemas'], $l['sin_asignar']], $lineas))
        ->toBe([
            ['ok', '910N-DZ-6', $this->rojoNino['6'], 'N', 'Rojo', '6-8', 10, [], []],
            ['ok', '906D-DZ-M', $this->negroDama['M'], 'D', 'Negro', 'M', 2, [], []],
        ])
        ->and($lineas[0]['fuentes']['talla'])->toBe('68');
});

test('DIC01-9 C/D: la duda talla-o-cantidad se pregunta; XXL se conserva aunque la variante no exista', function () {
    $resolutor = app(ResolutorDictado::class);

    [$duda] = $resolutor->interpretar('niño roja 68');
    [$xxl] = $resolutor->interpretar('caballero negro doble equis ele 3');

    expect($duda['estado'])->toBe('error')->and($duda['tipo_error'])->toBe('interpretacion')->and($duda['variante_id'])->toBeNull()
        ->and($duda['mensaje'])->toBe('¿«68» es talla 6-8 o 68 unidades?')
        ->and($xxl['talla'])->toBe('XXL')->and($xxl['tipo_error'])->toBe('catalogo')->and($xxl['variante_id'])->toBeNull()
        ->and($xxl['mensaje'])->toBe('La variante no existe: Negro Caballero no tiene talla XXL.');
});

test('DIC01-10 el catalogo de los selectores sale de los SKU reales (Crop top M, Caballero XXL) y se valida al corregir', function () {
    $xxl = dic01_producto('907H-DZ', ['XXL'], 'Camiseta Piel Durazno Azul Oscuro Hombre');
    $top = dic01_producto('907D-TP', ['M', 'U'], 'Crop Top Azul Oscuro');

    $resolutor = app(ResolutorDictado::class);
    $catalogo = $resolutor->catalogo();

    expect($catalogo['Azul Oscuro'])->toBe(['H' => ['XXL'], 'TP' => ['M', 'U']])->and($catalogo['Negro'])->toBe(['D' => ['M'], 'H' => ['M']])
        ->and($catalogo['Rojo'])->toBe(['N' => ['2', '6']]);

    [$dicho] = $resolutor->interpretar('caballero azul oscuro 2XL 3');
    [$topM] = $resolutor->interpretar('crop top azul oscuro talla M 2');
    [$topSinTalla] = $resolutor->interpretar('crop top azul oscuro 2');

    // Revisado 2026-11-26 (brief de Camilo: «crop top con M y U no asume Única»): antes se esperaba aviso con 907D-TP-U. Como
    // este color tiene M y Única, ahora se pregunta la talla con las dos opciones. Con solo Única se sigue tomando con aviso (DIC04-1).
    expect([$dicho['estado'], $dicho['sku'], $dicho['variante_id']])->toBe(['ok', '907H-DZ-XXL', $xxl['XXL']])
        ->and([$topM['estado'], $topM['sku'], $topM['variante_id']])->toBe(['ok', '907D-TP-M', $top['M']])
        ->and([$topSinTalla['estado'], $topSinTalla['sku'], $topSinTalla['talla'], $topSinTalla['opciones_talla']])->toBe(['error', null, null, ['M', 'U']])
        ->and($topSinTalla['mensaje'])->toBe('El crop top Azul Oscuro existe en M y Única: elige la talla.');

    $this->actingAs(f03b_admin(), 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.dictado.corregir'), ['color' => 'Azul Oscuro', 'linea' => 'TP', 'talla' => 'M', 'cantidad' => 2])
        ->assertOk()->assertJsonPath('linea.estado', 'ok')->assertJsonPath('linea.sku', '907D-TP-M');
});

test('DIC01-11 E: el tipo elegido completa solo lo que no se dijo; sin elegir se pregunta', function () {
    $resolutor = app(ResolutorDictado::class);

    [$sinElegir] = $resolutor->interpretar('negro M 4');
    [$elegido] = $resolutor->interpretar('negro M 4', 'H');
    [$dicho] = $resolutor->interpretar('negro dama M 4', 'H');

    expect($sinElegir['estado'])->toBe('error')->and($sinElegir['mensaje'])->toContain('dama o caballero')
        ->and([$elegido['estado'], $elegido['sku'], $elegido['tipo_por_contexto']])->toBe(['ok', '906H-DZ-M', true])
        ->and($elegido['nota'])->toContain('Caballero')
        ->and([$dicho['sku'], $dicho['tipo_por_contexto']])->toBe(['906D-DZ-M', false]);

    $this->actingAs(f03b_admin(), 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.dictado.interpretar'), ['texto' => 'negro M 4', 'tipo_defecto' => 'H'])
        ->assertOk()->assertJsonPath('lineas.0.sku', '906H-DZ-M')->assertJsonPath('catalogo.Negro.H', ['M']);
});

test('DIC01-12 la disponibilidad se informa por linea para orientar (stock y si se gestiona)', function () {
    f03b_stock(\Webkul\Product\Models\Product::find($this->negroDama['M']), 7);

    [$l] = app(ResolutorDictado::class)->interpretar('negro dama M 9');

    expect([$l['estado'], $l['cantidad'], $l['stock'], $l['gestiona_stock']])->toBe(['ok', 9, 7, true]);
});
