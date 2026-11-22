<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\CustomerGroup;
use Webkul\Product\Helpers\Indexers\Price as IndexadorDePrecios;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductCustomerGroupPrice;

require_once __DIR__.'/helpersFase03b.php';

/*
| Helpers de «Precios por grupo» (solo lpz_saas_testing). Precios de fixture, no reglas de negocio.
*/

if (! function_exists('pg_atributo')) {
    function pg_atributo(string $codigo): int
    {
        return (int) DB::table('attributes')->where('code', $codigo)->value('id');
    }
}

if (! function_exists('pg_valorEav')) {
    /** Escribe (o borra con null) un valor EAV sin canal ni idioma, o con el canal por defecto si el atributo lo pide. */
    function pg_valorEav(Product $producto, string $codigo, mixed $valor, string $columna = 'float_value'): void
    {
        $atributo = DB::table('attributes')->where('code', $codigo)->first();
        $canal = $atributo->value_per_channel ? core()->getDefaultChannelCode() : null;
        $clave = ['product_id' => $producto->id, 'attribute_id' => $atributo->id, 'channel' => $canal, 'locale' => null];

        if ($valor === null) {
            DB::table('product_attribute_values')->where($clave)->delete();

            return;
        }

        DB::table('product_attribute_values')->updateOrInsert($clave, [
            $columna => $valor, 'unique_id' => implode('|', array_filter([$canal, $producto->id, $atributo->id])),
        ]);
    }
}

if (! function_exists('pg_precioBase')) {
    function pg_precioBase(Product $producto, float $precio): void
    {
        pg_valorEav($producto, 'price', $precio);
        DB::table('product_flat')->where('product_id', $producto->id)->update(['price' => $precio]);
    }
}

if (! function_exists('pg_precioEspecial')) {
    function pg_precioEspecial(Product $producto, ?float $precio, ?string $desde = null, ?string $hasta = null): void
    {
        pg_valorEav($producto, 'special_price', $precio);
        pg_valorEav($producto, 'special_price_from', $desde, 'date_value');
        pg_valorEav($producto, 'special_price_to', $hasta, 'date_value');
    }
}

if (! function_exists('pg_regla')) {
    /** Fila de product_customer_group_prices como la guarda Bagisto (unique_id = qty|producto|grupo, sin vacios). */
    function pg_regla(Product $variante, ?CustomerGroup $grupo, int $qty, string $tipo, float $valor): ProductCustomerGroupPrice
    {
        return ProductCustomerGroupPrice::query()->create([
            'product_id' => $variante->id, 'customer_group_id' => $grupo?->id, 'qty' => $qty,
            'value_type' => $tipo, 'value' => $valor,
            'unique_id' => implode('|', array_filter([$qty, $variante->id, $grupo?->id])),
        ]);
    }
}

if (! function_exists('pg_reindexar')) {
    /** Reindexa precios con el indexador de Bagisto (variantes y su padre). */
    function pg_reindexar(Product ...$productos): void
    {
        app(IndexadorDePrecios::class)->reindexRows(collect($productos)->map->fresh()->all());
    }
}

if (! function_exists('pg_motor')) {
    /** Precio que calcula el indexador de Bagisto para (variante, grupo, cantidad): el mismo de Configurable::validateCartItem. */
    function pg_motor(Product $variante, CustomerGroup $grupo, int $qty = 1): float
    {
        $v = $variante->fresh();

        return (float) $v->getTypeInstance()->getPriceIndexer()
            ->setChannel(core()->getDefaultChannel())->setCustomerGroup($grupo)->setProduct($v)
            ->getMinimalPrice($qty);
    }
}

if (! function_exists('pg_indice')) {
    /** min_price guardado en product_price_indices (el que usa getFinalPrice con cantidad 1). */
    function pg_indice(Product $producto, CustomerGroup $grupo): ?float
    {
        $v = DB::table('product_price_indices')->where([
            'product_id' => $producto->id, 'customer_group_id' => $grupo->id, 'channel_id' => core()->getDefaultChannel()->id,
        ])->value('min_price');

        return $v === null ? null : (float) $v;
    }
}

if (! function_exists('pg_catalogo')) {
    /** Configurable de fixture con sus variantes a un precio base comun, reindexado. @return array{0: Product, 1: list<Product>} */
    function pg_catalogo(float $base = 10000.0): array
    {
        $padre = f03b_configurable($base);
        $variantes = $padre->variants()->orderBy('id')->get()->all();

        foreach ($variantes as $v) {
            pg_precioBase($v, $base);
        }

        pg_reindexar($padre, ...$variantes);

        return [$padre->fresh(), array_map(fn ($v) => $v->fresh(), $variantes)];
    }
}
