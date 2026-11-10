<?php

namespace ByLopez\CorteConfeccion\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de SOLO LECTURA que resuelve, para una lista de product_id
 * "simple" de Bagisto, los datos que la pantalla de distribucion (Fase 03C)
 * necesita mostrar: SKU, nombre, color, talla (o el eje equivalente que
 * corresponda) y stock vendible actual.
 *
 * Ningun otro punto del modulo (Controller, Blade, Vue, Request, otro
 * Service) debe consultar directamente las tablas de packages/Webkul/Product
 * para esto -- todo pasa por aqui. Diseñado y aprobado con evidencia real en
 * docs/confeccion/04A_STOCK_VARIANTE_DISENO.md.
 *
 * Nunca escribe, nunca ejecuta indexadores, nunca modifica packages/Webkul.
 */
class VarianteConsultaService
{
    /**
     * Color hexadecimal (swatch del atributo `color` de Bagisto) por etiqueta
     * de color en minusculas. Solo lectura y solo visual: nunca participa en
     * la identidad de una variante. Opciones sin swatch valido no aparecen.
     *
     * @return array<string, string>
     */
    public function hexPorColor(): array
    {
        return DB::table('attribute_options')
            ->join('attributes', 'attributes.id', '=', 'attribute_options.attribute_id')
            ->where('attributes.code', 'color')
            ->whereNotNull('attribute_options.swatch_value')
            ->select('attribute_options.admin_name', 'attribute_options.swatch_value')
            ->get()
            ->filter(fn ($opcion) => preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $opcion->swatch_value))
            ->mapWithKeys(fn ($opcion) => [mb_strtolower(trim((string) $opcion->admin_name)) => $opcion->swatch_value])
            ->all();
    }

    public function porProductIds(iterable $productIds, ?int $channelId = null): Collection
    {
        $ids = collect($productIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $resultado = $ids->mapWithKeys(fn ($id) => [$id => $this->filaVacia($id)]);

        if ($ids->isEmpty()) {
            return $resultado;
        }

        $locale = app()->getLocale();

        $productos = DB::table('products')
            ->leftJoin('product_flat', function ($join) use ($locale) {
                $join->on('product_flat.product_id', '=', 'products.id')
                    ->where('product_flat.locale', $locale);
            })
            ->whereIn('products.id', $ids)
            ->select(
                'products.id',
                'products.sku',
                'products.type',
                'products.parent_id',
                'product_flat.name as nombre_propio',
                'product_flat.status as activo'
            )
            ->get()
            ->keyBy('id');

        $parentIds = $productos->pluck('parent_id')->filter()->unique()->values();

        $nombresPadre = $parentIds->isEmpty()
            ? collect()
            : DB::table('product_flat')
                ->whereIn('product_id', $parentIds)
                ->where('locale', $locale)
                ->pluck('name', 'product_id');

        // Ejes de variacion reales por configurable padre (product_id aqui
        // es el ID del configurable, no del simple). El "code" de cada
        // atributo es lo unico fiable para identificar cual es "color":
        // el segundo eje NO siempre es "size"/Talla (ver 04A_STOCK_VARIANTE_DISENO.md
        // §2.5 -- una linea infantil real usa "grupo_de_edad_ninos" en su lugar),
        // por eso se resuelve dinamicamente y nunca por un attribute_id fijo.
        $ejesPorConfigurable = $parentIds->isEmpty()
            ? collect()
            : DB::table('product_super_attributes')
                ->join('attributes', 'attributes.id', '=', 'product_super_attributes.attribute_id')
                ->whereIn('product_super_attributes.product_id', $parentIds)
                ->select(
                    'product_super_attributes.product_id as configurable_id',
                    'attributes.id as attribute_id',
                    'attributes.code',
                    'attributes.admin_name'
                )
                ->get()
                ->groupBy('configurable_id');

        $attributeIds = $ejesPorConfigurable->flatten(1)->pluck('attribute_id')->unique()->values();

        // Valor guardado (integer_value = attribute_options.id) resuelto ya
        // contra su label (admin_name) en la misma consulta -- nunca se usa
        // integer_value directamente como si fuera el texto visible.
        $valoresPorProducto = $attributeIds->isEmpty()
            ? collect()
            : DB::table('product_attribute_values')
                ->leftJoin('attribute_options', 'attribute_options.id', '=', 'product_attribute_values.integer_value')
                ->whereIn('product_attribute_values.product_id', $ids)
                ->whereIn('product_attribute_values.attribute_id', $attributeIds)
                ->select(
                    'product_attribute_values.product_id',
                    'product_attribute_values.attribute_id',
                    'product_attribute_values.integer_value as option_id',
                    'attribute_options.admin_name as option_admin_name'
                )
                ->get()
                ->groupBy('product_id');

        $stocksPorProducto = $this->stockVendible($ids, $channelId);

        foreach ($ids as $id) {
            $producto = $productos->get($id);

            if (! $producto) {
                continue;
            }

            $nombre = ($producto->parent_id ? $nombresPadre->get($producto->parent_id) : null)
                ?? $producto->nombre_propio;

            $ejesConfigurable = $producto->parent_id
                ? ($ejesPorConfigurable->get($producto->parent_id) ?? collect())
                : collect();

            $ejeColor = $ejesConfigurable->firstWhere('code', 'color');
            $ejeTalla = $ejesConfigurable->first(fn ($eje) => $eje->code !== 'color');

            $valoresProducto = $valoresPorProducto->get($id) ?? collect();

            $resultado[$id] = array_merge($resultado[$id], [
                'sku' => $producto->sku,
                'nombre' => $nombre,
                'color' => $ejeColor ? $this->resolverLabel($valoresProducto, $ejeColor->attribute_id) : null,
                'talla' => $ejeTalla ? $this->resolverLabel($valoresProducto, $ejeTalla->attribute_id) : null,
                'talla_etiqueta' => $ejeTalla?->admin_name,
                'stock_actual' => $stocksPorProducto->has($id) ? (int) $stocksPorProducto->get($id) : null,
                'tipo_producto' => $producto->type,
                'activo' => $producto->activo === null ? null : (bool) $producto->activo,
            ]);
        }

        return $resultado;
    }

    /**
     * Stock vendible por variante: UNICA lectura del modulo (distribucion, sugerencia por stock).
     *
     * Canal resuelto UNA sola vez para todo el lote. Si no se recibe explicito, se resuelve con getDefaultChannel()
     * (determinista, via config('app.channel')) y NUNCA con getCurrentChannel() (depende del Host HTTP de la request --
     * incorrecto para un contexto Admin, ver docs/confeccion/04A_STOCK_VARIANTE_DISENO.md §6).
     *
     * product_inventory_indices.qty YA es stock vendible/indexado (fisico de fuentes activas del canal, menos lo ya
     * reservado) -- nunca product_inventories.qty (fisico bruto) y nunca se vuelven a restar reservas. Los productos
     * `configurable` nunca tienen fila aqui (Bagisto los excluye de la reindexacion). Sin fila = sin dato (no 0).
     *
     * @return Collection<int, int> product_id => qty, solo las variantes con indice
     */
    public function stockVendible(iterable $productIds, ?int $channelId = null): Collection
    {
        $ids = collect($productIds)->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values();
        $channelResuelto = $channelId ?? core()->getDefaultChannel()?->id;

        if ($ids->isEmpty() || ! $channelResuelto) {
            return collect();
        }

        return DB::table('product_inventory_indices')
            ->whereIn('product_id', $ids)
            ->where('channel_id', $channelResuelto)
            ->pluck('qty', 'product_id')
            ->mapWithKeys(fn ($qty, $id) => [(int) $id => (int) $qty]);
    }

    /**
     * Resuelve el label visible de un atributo tipo select para un
     * producto: `null` si el atributo no tiene valor asignado (no se pudo
     * resolver nada); `"No definido"` si el valor SI esta asignado pero la
     * opcion resuelta no tiene admin_name (caso distinto, ver
     * docs/confeccion/04A_STOCK_VARIANTE_DISENO.md §10) -- nunca se
     * confunden ambos casos.
     */
    protected function resolverLabel(Collection $valoresProducto, int $attributeId): ?string
    {
        $valor = $valoresProducto->firstWhere('attribute_id', $attributeId);

        if (! $valor || $valor->option_id === null) {
            return null;
        }

        return $valor->option_admin_name ?: 'No definido';
    }

    protected function filaVacia(int $id): array
    {
        return [
            'product_id' => $id,
            'sku' => null,
            'nombre' => null,
            'color' => null,
            'talla' => null,
            'talla_etiqueta' => null,
            'stock_actual' => null,
            'tipo_producto' => null,
            'activo' => null,
        ];
    }
}
