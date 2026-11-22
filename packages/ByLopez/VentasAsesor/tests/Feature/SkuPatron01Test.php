<?php

use ByLopez\VentasAsesor\Listeners\SincronizarSkuProducto;
use ByLopez\VentasAsesor\Services\Catalogo\CodigoSkuService;
use ByLopez\VentasAsesor\Services\Catalogo\RenombradorSkuService;
use ByLopez\VentasAsesor\Services\Venta\CatalogoVentaService;
use ByLopez\VentasAsesor\Support\Catalogo\BusquedaSku;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\Product\Models\Product;

require_once __DIR__.'/../helpersFase03b.php';

uses(TestCase::class);

/*
| SKU-01 — Doc. 33: código único por patrón «número de color + público, línea, talla» (115D-DZ / 115D-DZ-M). Solo lpz_saas_testing.
*/

function sku_valorEav(Product $producto, string $codigo, int $valor): void
{
    $atributo = DB::table('attributes')->where('code', $codigo)->first();
    $canal = $atributo->value_per_channel ? core()->getDefaultChannelCode() : null;
    DB::table('product_attribute_values')->updateOrInsert(
        ['product_id' => $producto->id, 'attribute_id' => $atributo->id, 'channel' => $canal, 'locale' => null],
        ['integer_value' => $valor, 'unique_id' => implode('|', array_filter([$canal, $producto->id, $atributo->id]))],
    );
}

/** @return array{0: Product, 1: list<Product>} */
function sku_catalogo(): array
{
    $padre = f03b_configurable(10000.0);

    return [$padre->fresh(), $padre->variants()->orderBy('id')->get()->all()];
}

function sku_opcion(string $atributo, string $texto): int
{
    $aid = DB::table('attributes')->where('code', $atributo)->value('id');
    $id = DB::table('attribute_options')->where('attribute_id', $aid)->where('admin_name', $texto)->value('id');

    return (int) ($id ?: DB::table('attribute_options')->insertGetId(['attribute_id' => $aid, 'admin_name' => $texto, 'sort_order' => 999]));
}

/** Configurable de fixture de UN color con tallas S, M, L… en la familia del patrón. @return array{0: Product, 1: list<Product>} */
function sku_configurable(string $color, string $nombre = 'Camiseta de prueba'): array
{
    [$padre, $variantes] = sku_catalogo();
    $tallas = ['S', 'M', 'L', 'XL', 'XXL', 'T-Unica'];

    foreach ($variantes as $i => $v) {
        sku_valorEav($v, 'color', sku_opcion('color', $color));
        sku_valorEav($v, 'size', sku_opcion('size', $tallas[$i]));
    }

    DB::table('product_flat')->where('product_id', $padre->id)->update(['name' => $nombre]);

    return [$padre->fresh(), array_map(fn ($v) => $v->fresh(), $variantes)];
}

beforeEach(function () {
    [$padre] = sku_catalogo();
    $familia = (string) DB::table('attribute_families')->where('id', $padre->attribute_family_id)->value('code');
    config(['ventasasesor.sku.familias' => [$familia => ['genero' => 'D', 'linea_defecto' => 'DZ', 'nombre' => 'Camiseta Piel Durazno {color} Mujer']]]);
    DB::table(CodigoSkuService::TABLA_COLORES)->delete();
    DB::table(CodigoSkuService::TABLA_COLORES)->insert(['attribute_option_id' => sku_opcion('color', 'Verde Cali'), 'codigo' => 115, 'created_at' => now(), 'updated_at' => now()]);
});

test('SKU01-1 al guardar, el producto y sus variantes quedan 115D-DZ / 115D-DZ-<talla>, también en product_flat y el atributo sku', function () {
    [$padre, $variantes] = sku_configurable('Verde Cali');

    (new SincronizarSkuProducto(app(RenombradorSkuService::class)))->handle($padre);

    $skus = DB::table('products')->whereIn('id', array_map(fn ($v) => $v->id, $variantes))->orderBy('id')->pluck('sku')->all();

    expect(DB::table('products')->where('id', $padre->id)->value('sku'))->toBe('115D-DZ')
        ->and($skus[0])->toBe('115D-DZ-S')
        ->and($skus[1])->toBe('115D-DZ-M')
        ->and(DB::table('product_flat')->where('product_id', $padre->id)->value('sku'))->toBe('115D-DZ')
        ->and(DB::table('product_attribute_values')->where('product_id', $variantes[0]->id)->where('attribute_id', DB::table('attributes')->where('code', 'sku')->value('id'))->value('text_value'))->toBe('115D-DZ-S');
});

test('SKU01-2 un color sin número recibe el siguiente libre; otro producto del mismo color y público lleva 115D-DZ2', function () {
    [$a] = sku_configurable('Verde Cali');
    [$b] = sku_configurable('Verde Cali');
    [$c, $vc] = sku_configurable($nuevo = 'Color Prueba Sku '.uniqid());
    $listener = new SincronizarSkuProducto(app(RenombradorSkuService::class));
    $maximo = (int) DB::table(CodigoSkuService::TABLA_COLORES)->max('codigo');   // los fixtures ya pudieron estrenar colores

    foreach ([$a, $b, $c] as $p) {
        $listener->handle($p);
    }

    $n = (int) DB::table(CodigoSkuService::TABLA_COLORES)->where('attribute_option_id', sku_opcion('color', $nuevo))->value('codigo');

    expect(DB::table('products')->where('id', $a->id)->value('sku'))->toBe('115D-DZ')
        ->and(DB::table('products')->where('id', $b->id)->value('sku'))->toBe('115D-DZ2')
        ->and($n)->toBe($maximo + 1)
        ->and(DB::table('products')->where('id', $c->id)->value('sku'))->toBe($n.'D-DZ')
        ->and(DB::table('products')->where('id', $vc[0]->id)->value('sku'))->toBe($n.'D-DZ-S');

    // Guardar otra vez no cambia nada (repetible).
    $listener->handle($b);
    expect(DB::table('products')->where('id', $b->id)->value('sku'))->toBe('115D-DZ2');
});

test('SKU01-3 un producto copiado («Copia de …») toma el nombre de la plantilla con su color real y un url_key sin «copia-de-»', function () {
    [$padre, $variantes] = sku_configurable('Verde Cali', 'Copia de Camiseta Piel Durazno Azul Rey Mujer');
    DB::table('product_flat')->where('product_id', $variantes[0]->id)->update(['name' => 'Copia de Camiseta Azul Rey Mujer S', 'url_key' => 'copia-de-camiseta-azul-rey-mujer-s']);

    (new SincronizarSkuProducto(app(RenombradorSkuService::class)))->handle($padre);

    expect(DB::table('product_flat')->where('product_id', $padre->id)->value('name'))->toBe('Camiseta Piel Durazno Verde Cali Mujer')
        ->and(DB::table('product_flat')->where('product_id', $variantes[0]->id)->value('name'))->toBe('Camiseta Piel Durazno Verde Cali Mujer S')
        ->and(DB::table('product_flat')->where('product_id', $variantes[0]->id)->value('url_key'))->toStartWith('camiseta-piel-durazno-verde-cali-mujer-s');
});

test('SKU01-4 el SKU nuevo reemplaza SOLO el valor exacto viejo en las copias guardadas', function () {
    DB::statement('CREATE TEMPORARY TABLE zz_sku_copias (id INT AUTO_INCREMENT PRIMARY KEY, sku VARCHAR(100))');
    DB::table('zz_sku_copias')->insert([['sku' => '115DURZ-DAMA-M'], ['sku' => '115DURZ-DAMA-MM'], ['sku' => 'otro']]);

    $n = app(RenombradorSkuService::class)->propagar(['115DURZ-DAMA-M' => '115D-DZ-M'], [['zz_sku_copias', 'sku']]);

    expect(DB::table('zz_sku_copias')->orderBy('id')->pluck('sku')->all())->toBe(['115D-DZ-M', '115DURZ-DAMA-MM', 'otro'])
        ->and($n)->toBe(['zz_sku_copias.sku' => 1])
        ->and(collect(app(RenombradorSkuService::class)->columnasCopia())->map(fn ($c) => implode('.', $c))->all())
        ->toContain('order_items.sku', 'invoice_items.sku', 'shipment_items.sku')
        ->not->toContain('products.sku', 'product_flat.sku', 'bylopez_cgi_productos_camisetas.sku_variante');
});

test('SKU01-5 búsqueda rápida: «115d», «115 d m», «115D-DZ-M» y «115n6» se interpretan sin importar guiones ni espacios', function () {
    expect(BusquedaSku::interpretar('115d'))->toBe(['producto' => '115D-%', 'variante' => null])
        ->and(BusquedaSku::interpretar('115 d m'))->toBe(['producto' => '115D-%', 'variante' => '115D-%-M'])
        ->and(BusquedaSku::interpretar('115D-DZ-M'))->toBe(['producto' => '115D-DZ%', 'variante' => '115D-DZ%-M'])
        ->and(BusquedaSku::interpretar('115dxl'))->toBe(['producto' => '115D-%', 'variante' => '115D-%-XL'])
        ->and(BusquedaSku::interpretar('115n6'))->toBe(['producto' => '115N-%', 'variante' => '115N-%-6'])
        ->and(BusquedaSku::interpretar('Negro'))->toBeNull()
        ->and(BusquedaSku::interpretar('115'))->toBeNull();
});

test('SKU01-6 Nueva venta: «115 d» trae el producto 115D-DZ', function () {
    [$padre] = sku_configurable('Verde Cali');
    (new SincronizarSkuProducto(app(RenombradorSkuService::class)))->handle($padre);

    expect(collect(app(CatalogoVentaService::class)->buscarProductos('115 d'))->pluck('sku')->all())->toContain('115D-DZ');
});

test('SKU01-7 el comando: vista previa no escribe; --aplicar renombra con nombre de plantilla y deja 301 del url_key anterior', function () {
    [$padre, $variantes] = sku_configurable('Verde Cali', 'Camiseta  vieja');
    $urlAntes = DB::table('product_flat')->where('product_id', $padre->id)->value('url_key');

    Artisan::call('ventas-asesor:sku-normalizar', ['--sin-etiquetas' => true, '--csv' => sys_get_temp_dir().'/sku_prueba_vista.csv']);
    expect(DB::table('products')->where('id', $padre->id)->value('sku'))->not->toBe('115D-DZ');

    Artisan::call('ventas-asesor:sku-normalizar', ['--aplicar' => true, '--sin-etiquetas' => true, '--csv' => sys_get_temp_dir().'/sku_prueba.csv']);

    $nuevaUrl = DB::table('product_flat')->where('product_id', $padre->id)->value('url_key');

    expect(DB::table('products')->where('id', $padre->id)->value('sku'))->toBe('115D-DZ')
        ->and(DB::table('products')->where('id', $variantes[1]->id)->value('sku'))->toBe('115D-DZ-M')
        ->and(DB::table('product_flat')->where('product_id', $padre->id)->value('name'))->toBe('Camiseta Piel Durazno Verde Cali Mujer')
        ->and($nuevaUrl)->toStartWith('camiseta-piel-durazno-verde-cali-mujer')
        ->and(DB::table('url_rewrites')->where('request_path', $urlAntes)->value('target_path'))->toBe($nuevaUrl);
});
