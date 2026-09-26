<?php

use Webkul\Customer\Models\Customer;
use Webkul\Customer\Models\CustomerGroup;
use Webkul\Faker\Helpers\Product as ProductFaker;
use Webkul\Product\Helpers\Indexers\Inventory as IndexadorDeInventario;
use Webkul\Product\Helpers\Indexers\Price as IndexadorDePrecios;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductCustomerGroupPrice;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
| Helpers Fase 03B (solo lpz_saas_testing). Precios de fixture, no reglas de negocio.
*/

if (! function_exists('f03b_grupo')) {
    function f03b_grupo(string $nombre = 'G'): CustomerGroup
    {
        return CustomerGroup::factory()->create([
            'code' => 'f03b_'.strtolower($nombre).'_'.uniqid(), 'name' => 'F03B '.$nombre, 'is_user_defined' => 1,
        ]);
    }
}

if (! function_exists('f03b_cliente')) {
    function f03b_cliente(?CustomerGroup $grupo = null, array $extra = []): Customer
    {
        return Customer::factory()->create(array_merge(['customer_group_id' => ($grupo ?? f03b_grupo())->id], $extra));
    }
}

if (! function_exists('f03b_admin')) {
    function f03b_admin(string $tipo = 'all', array $permisos = []): Admin
    {
        $rol = Role::factory()->create([
            'name' => 'F03B rol '.uniqid(), 'permission_type' => $tipo, 'permissions' => $tipo === 'all' ? null : $permisos,
        ]);

        return Admin::factory()->create(['role_id' => $rol->id]);
    }
}

if (! function_exists('f03b_configurable')) {
    function f03b_configurable(float $base = 8500.0): Product
    {
        return (new ProductFaker([
            'attributes' => [5 => 'new', 6 => 'featured', 11 => 'price', 26 => 'guest_checkout'],
            'attribute_value' => [
                'new' => ['boolean_value' => true], 'featured' => ['boolean_value' => true],
                'price' => ['float_value' => $base], 'guest_checkout' => ['boolean_value' => true],
            ],
        ]))->getConfigurableProductFactory()->create();
    }
}

if (! function_exists('f03b_descuentoDeGrupo')) {
    /** Precio de grupo de Bagisto (tipo 'discount' = porcentaje) para una variante, y reindexado. */
    function f03b_descuentoDeGrupo(Product $variante, CustomerGroup $grupo, float $porcentaje, Product ...$reindexar): void
    {
        ProductCustomerGroupPrice::factory()->create([
            'product_id' => $variante->id, 'customer_group_id' => $grupo->id,
            'qty' => 1, 'value_type' => 'discount', 'value' => $porcentaje,
        ]);

        app(IndexadorDePrecios::class)->reindexBatch(collect([$variante, ...$reindexar])->map->fresh());
    }
}

if (! function_exists('f03b_stock')) {
    /** Inventario del origen por defecto y reindexado con el indexador de Bagisto. */
    function f03b_stock(Product $variante, int $qty): void
    {
        $origen = core()->getDefaultChannel()->inventory_sources()->where('status', 1)->first();

        $variante->inventories()->updateOrCreate(
            ['inventory_source_id' => $origen->id, 'vendor_id' => 0],
            ['qty' => $qty],
        );

        app(IndexadorDeInventario::class)->reindexBatch([$variante->fresh()]);
    }
}
