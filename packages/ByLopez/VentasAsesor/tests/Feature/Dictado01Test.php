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

    expect($talla['estado'])->toBe('error')->and($talla['mensaje'])->toBe('Azul Rey Dama no tiene talla XL.')
        ->and($linea['mensaje'])->toBe('No hay Rojo en Caballero.')
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
        ->assertOk()->assertJsonPath('linea.estado', 'error')->assertJsonPath('linea.mensaje', 'Azul Rey Dama no tiene talla XL.');

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
