<?php

namespace ByLopez\Cargues\Extensiones\ProductosCamisetas;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Escritura de camisetas por los REPOSITORIOS y EVENTOS de Bagisto (docs/17 §5), en el mismo orden que el administrador:
 *   padre:     create.before → ProductRepository::create(configurable, sin super atributos) + super atributos color/talla → create.after
 *              → update.before → ProductRepository::update (datos del padre, canal, categorias; sin variantes previas que borrar)
 *   variantes: ProductRepository::create(simple, parent_id) + update SOLO de los atributos indicados (como Configurable::createVariant)
 *   al final:  update.after del PADRE → product_flat del padre y sus variantes, indices de inventario, precio y busqueda, cache del catalogo.
 *
 * Nunca usa Configurable::create con super atributos (crearia el producto cartesiano color × talla) ni Configurable::update sobre un
 * padre EXISTENTE (borraria las variantes que no vinieran en los datos). No crea inventario: sin `inventories`, Bagisto no escribe stock.
 * Corre dentro de la transaccion del grupo (la abre la extension).
 */
class CreadorCamisetas
{
    /** Atributos que se escriben en una variante (el resto de la familia queda vacio). */
    protected const ATRIBUTOS_VARIANTE = ['sku', 'name', 'url_key', 'short_description', 'description', 'price', 'cost', 'weight', 'status', 'visible_individually', 'guest_checkout', 'manage_stock', 'new', 'featured'];

    public function __construct(protected ProductRepository $productos, protected CatalogoCamisetas $catalogo) {}

    /**
     * @param  array<string,mixed>  $padre  sku, nombre, familia_id, activo, categorias, atributos (codigo => option_id), eje_talla
     *                                      (codigo del atributo de talla del publico), descripcion_corta, descripcion
     * @return int id del padre creado
     */
    public function crearPadre(array $padre): int
    {
        $attr = (array) config('cargues.productos_camisetas.atributos');

        Event::dispatch('catalog.product.create.before');
        $p = $this->productos->create(['type' => 'configurable', 'attribute_family_id' => $padre['familia_id'], 'sku' => $padre['sku']]);
        $p->super_attributes()->attach([(int) $this->catalogo->atributo($attr['color'])->id, (int) $this->catalogo->atributo($padre['eje_talla'])->id]);
        Event::dispatch('catalog.product.create.after', $p);

        Event::dispatch('catalog.product.update.before', $p->id);
        $datos = [
            'sku' => $padre['sku'], 'name' => $padre['nombre'], 'url_key' => $this->urlKey($padre['nombre'], $padre['sku']),
            'status' => $padre['activo'], 'visible_individually' => true, 'guest_checkout' => true, 'new' => false, 'featured' => false,
            'manage_stock' => false, 'channel' => core()->getDefaultChannelCode(), 'locale' => core()->getDefaultLocaleCodeFromDefaultChannel(),
            'channels' => [core()->getDefaultChannel()->id], 'categories' => $padre['categorias'],
            // Obligatorias en el formulario de Bagisto: sin ellas el producto importado no se podria guardar desde el administrador.
            'short_description' => $padre['descripcion_corta'], 'description' => $padre['descripcion'],
        ];

        foreach ($padre['atributos'] as $codigo => $opcion) {
            if ($opcion !== null) {
                $datos[$codigo] = $opcion;
            }
        }

        // Padre recien creado: no tiene variantes, asi que Configurable::update no borra nada (no se le pasan `variants`).
        $this->productos->update($datos, $p->id);

        return (int) $p->id;
    }

    /**
     * @param  array<string,mixed>  $v  sku, nombre, precio, costo, peso, activo, color, talla, eje_talla, atributos (codigo => option_id),
     *                                  categorias, descripcion_corta, descripcion
     * @return int id de la variante
     */
    public function crearVariante(int $padreId, int $familiaId, array $v): int
    {
        $attr = (array) config('cargues.productos_camisetas.atributos');
        $attr['talla'] = $v['eje_talla'];
        $variante = $this->productos->create(['type' => 'simple', 'attribute_family_id' => $familiaId, 'sku' => $v['sku'], 'parent_id' => $padreId]);

        $datos = [
            'sku' => $v['sku'], 'name' => $v['nombre'], 'url_key' => $this->urlKey($v['nombre'], $v['sku']),
            // null = «no informado»: Bagisto no guarda el atributo (nunca un 0 inventado).
            'price' => $v['precio'], 'cost' => $v['costo'], 'weight' => $v['peso'],
            'status' => $v['activo'], 'visible_individually' => false, 'guest_checkout' => true, 'manage_stock' => true, 'new' => false, 'featured' => false,
            $attr['color'] => $v['color'], $attr['talla'] => $v['talla'],
            'short_description' => $v['descripcion_corta'], 'description' => $v['descripcion'],
            'channel' => core()->getDefaultChannelCode(), 'locale' => core()->getDefaultLocaleCodeFromDefaultChannel(),
        ];
        $codigos = [...self::ATRIBUTOS_VARIANTE, $attr['color'], $attr['talla']];

        foreach ($v['atributos'] as $codigo => $opcion) {
            if ($opcion !== null) {
                $datos[$codigo] = $opcion;
                $codigos[] = $codigo;
            }
        }

        // Con la lista de atributos, AbstractType::update guarda SOLO esos (sin tocar canales, inventario ni precios por grupo).
        $this->productos->update($datos, $variante->id, $codigos);
        $variante->categories()->sync($v['categorias']);

        return (int) $variante->id;
    }

    /** Reindexa el padre y sus variantes (flat, inventario, precio, busqueda y cache del catalogo), como al guardar en el administrador. */
    public function reindexar(int $padreId): void
    {
        Event::dispatch('catalog.product.update.after', $this->productos->find($padreId));
    }

    /** url_key unico: slug del nombre; si ya existe, se le agrega el SKU; nunca se reutiliza el de otro producto. */
    protected function urlKey(string $nombre, string $sku): string
    {
        $base = Str::slug($nombre) ?: Str::slug($sku);

        foreach ([$base, $base.'-'.Str::slug($sku)] as $candidato) {
            if (! $this->catalogo->urlKeyUsada($candidato)) {
                return $candidato;
            }
        }

        for ($i = 2; ; $i++) {
            if (! $this->catalogo->urlKeyUsada($base.'-'.Str::slug($sku).'-'.$i)) {
                return $base.'-'.Str::slug($sku).'-'.$i;
            }
        }
    }

    /** Bloquea la fila del padre existente hasta el final de la transaccion (dos cargas no agregan la misma combinacion a la vez). */
    public function bloquearPadre(int $padreId): void
    {
        DB::table('products')->where('id', $padreId)->lockForUpdate()->first(['id']);
    }
}
