<?php

namespace ByLopez\CorteConfeccion\Tests\Support;

use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Product\Models\Product;

/**
 * Fixtures autosuficientes para ResolvedorVarianteCorteService y su
 * diagnostico. Replica la estructura real del catalogo de camisetas (ver
 * docs/ORDEN_CORTE_CREACION_V2_AUDITORIA_02.md):
 *
 *  - linea = AttributeFamily;
 *  - configurable = linea x UN color, con super atributos color + un eje 2;
 *  - simples hijos con color y eje 2 en product_attribute_values;
 *  - tipo_tela como atributo select del configurable;
 *  - status en el EAV por canal (value_per_channel) y, opcionalmente, en
 *    product_flat.
 *
 * Todo se crea dentro de la transaccion de la prueba (DatabaseTransactions).
 * Reutiliza los atributos reales (color, size, tipo_tela, status) si ya
 * existen: el code es unico, y los IDs cambian entre ambientes.
 */
class CatalogoCorteFixtures
{
    public Attribute $color;

    public Attribute $size;

    public Attribute $tipoTela;

    public Attribute $status;

    public AttributeOption $tela;

    public string $canal;

    public static function nuevo(): static
    {
        $fixtures = new static;

        $fixtures->color = static::atributo('color', 'Color');
        $fixtures->size = static::atributo('size', 'Talla');
        $fixtures->tipoTela = static::atributo('tipo_tela', 'Tipo Tela');
        $fixtures->status = static::atributo('status', 'Estado', 'boolean');
        $fixtures->tela = static::opcion($fixtures->tipoTela, 'Tela RC '.uniqid());
        $fixtures->canal = core()->getDefaultChannel()->code;

        return $fixtures;
    }

    public static function atributo(string $code, string $adminName, string $type = 'select'): Attribute
    {
        return Attribute::firstWhere('code', $code)
            ?? Attribute::factory()->create(['code' => $code, 'type' => $type, 'admin_name' => $adminName]);
    }

    public static function opcion(Attribute $atributo, string $adminName, int $sortOrder = 0): AttributeOption
    {
        return AttributeOption::factory()->for($atributo, 'attribute')->create([
            'admin_name' => $adminName,
            'sort_order' => $sortOrder,
        ]);
    }

    public function familia(string $nombre): AttributeFamily
    {
        return AttributeFamily::factory()->create(['code' => 'rc_'.uniqid(), 'name' => $nombre]);
    }

    /**
     * Opciones de talla (atributo size) con sort_order creciente segun el orden dado.
     *
     * @param  list<string>  $etiquetas
     * @return array<string, AttributeOption>
     */
    public function tallas(array $etiquetas): array
    {
        $tallas = [];

        foreach (array_values($etiquetas) as $i => $etiqueta) {
            $tallas[$etiqueta] = static::opcion($this->size, $etiqueta, $i + 1);
        }

        return $tallas;
    }

    /**
     * Configurable de una linea.
     *
     * Opciones:
     *  sku      SKU del configurable
     *  nombre   nombre en product_flat
     *  tela     AttributeOption de tipo_tela | false (sin tipo_tela). Por defecto $this->tela
     *  ejes     list<Attribute> super atributos. Por defecto [color, size]
     *  valores  list<AttributeOption> valores extra del configurable (p. ej. cuello)
     *  activo   bool status EAV | null (sin fila de status). Por defecto true
     *  flat     bool status en product_flat | null (sin fila de status). Por defecto = activo
     */
    public function configurable(AttributeFamily $familia, array $opciones = []): Product
    {
        $configurable = Product::factory()->configurable()->create([
            'sku' => $opciones['sku'] ?? 'RC-'.strtoupper(uniqid()),
            'attribute_family_id' => $familia->id,
        ]);

        foreach ($opciones['ejes'] ?? [$this->color, $this->size] as $eje) {
            DB::table('product_super_attributes')->insert([
                'product_id' => $configurable->id,
                'attribute_id' => $eje->id,
            ]);
        }

        $tela = array_key_exists('tela', $opciones) ? $opciones['tela'] : $this->tela;

        if ($tela) {
            $this->valor($configurable, $this->tipoTela->id, $tela);
        }

        foreach ($opciones['valores'] ?? [] as $opcion) {
            $this->valor($configurable, $opcion->attribute_id, $opcion);
        }

        $activo = array_key_exists('activo', $opciones) ? $opciones['activo'] : true;
        $this->estado($configurable, $activo);
        $this->productFlat($configurable, $opciones['nombre'] ?? 'Camiseta '.$configurable->sku, array_key_exists('flat', $opciones) ? $opciones['flat'] : $activo);

        return $configurable;
    }

    /**
     * Simple hijo de un configurable. $eje puede ser null (hijo sin valor de eje 2).
     *
     * Opciones: sku, familia (attribute_family_id del hijo), activo (como en
     * configurable()), flat (solo crea fila en product_flat si se indica).
     */
    public function hijo(Product $configurable, ?AttributeOption $color, ?AttributeOption $eje, array $opciones = []): Product
    {
        $hijo = Product::factory()->simple()->create([
            'sku' => $opciones['sku'] ?? $configurable->sku.'-'.strtoupper(uniqid()),
            'parent_id' => $configurable->id,
            'attribute_family_id' => $opciones['familia'] ?? $configurable->attribute_family_id,
        ]);

        if ($color) {
            $this->valor($hijo, $this->color->id, $color);
        }

        if ($eje) {
            $this->valor($hijo, $eje->attribute_id, $eje);
        }

        $this->estado($hijo, array_key_exists('activo', $opciones) ? $opciones['activo'] : true);

        if (array_key_exists('flat', $opciones)) {
            $this->productFlat($hijo, null, $opciones['flat']);
        }

        return $hijo;
    }

    /**
     * Configurable de un color con un hijo por cada valor del eje 2 dado.
     *
     * @param  array<string, AttributeOption>  $ejeOpciones  etiqueta => opcion
     * @return array{configurable: Product, hijos: array<string, Product>}
     */
    public function configurableConEje(AttributeFamily $familia, AttributeOption $color, array $ejeOpciones, array $opciones = []): array
    {
        $configurable = $this->configurable($familia, $opciones + ['nombre' => 'Camiseta '.$color->admin_name.' '.$familia->name]);

        $hijos = [];

        foreach ($ejeOpciones as $etiqueta => $ejeOpcion) {
            $hijos[$etiqueta] = $this->hijo($configurable, $color, $ejeOpcion, ['sku' => $configurable->sku.'-'.$etiqueta]);
        }

        return ['configurable' => $configurable, 'hijos' => $hijos];
    }

    protected function valor(Product $producto, int $attributeId, AttributeOption $opcion): void
    {
        DB::table('product_attribute_values')->insert([
            'product_id' => $producto->id,
            'attribute_id' => $attributeId,
            'integer_value' => $opcion->id,
        ]);
    }

    protected function estado(Product $producto, ?bool $activo): void
    {
        if ($activo === null) {
            return;
        }

        DB::table('product_attribute_values')->insert([
            'product_id' => $producto->id,
            'attribute_id' => $this->status->id,
            'channel' => $this->canal,
            'boolean_value' => $activo,
        ]);
    }

    protected function productFlat(Product $producto, ?string $nombre, ?bool $status): void
    {
        DB::table('product_flat')->insert([
            'product_id' => $producto->id,
            'sku' => $producto->sku,
            'name' => $nombre,
            'status' => $status,
            'locale' => app()->getLocale(),
            'channel' => $this->canal,
        ]);
    }
}
