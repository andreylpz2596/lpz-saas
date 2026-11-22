<?php

namespace ByLopez\VentasAsesor\Services\Catalogo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Doc. 33 — Escribe el cambio de SKU / nombre / url_key de productos y lleva el SKU nuevo a las copias guardadas en el resto
 * de la base (pedidos, facturas, envíos, reembolsos, carritos, costos, comisiones, cortes…). Directo en la base, sin eventos de
 * Bagisto (no dispara listeners ni reindexa: el SKU no participa en precios ni stock, que van por product_id).
 *
 * - SKU: products.sku, el valor del atributo `sku` y product_flat.sku.
 * - Nombre y url_key: valores del atributo y product_flat. Un url_key que cambia deja un redireccionamiento 301 del anterior
 *   (url_rewrites, como Bagisto al editar), así los enlaces viejos siguen sirviendo.
 * - Copias: cualquier columna con «sku» en el nombre fuera del catálogo, cambiando SOLO el valor exacto viejo por el nuevo.
 *   NO se tocan las copias del ARCHIVO de los cargues (bylopez_cgi_*, salvo `producto_sku`, que es el SKU del catálogo): son
 *   la transcripción del archivo subido.
 */
class RenombradorSkuService
{
    /**
     * @param  array<int, array{sku?:string, name?:string, url_key?:string}>  $cambios  product_id => campos nuevos
     * @return array<string,int> filas tocadas por tabla.columna (copias)
     */
    public function aplicar(array $cambios): array
    {
        if ($cambios === []) {
            return [];
        }

        $attr = DB::table('attributes')->whereIn('code', ['sku', 'name', 'url_key'])->pluck('id', 'code');
        $actuales = DB::table('products')->whereIn('id', array_keys($cambios))->pluck('sku', 'id');
        $mapa = [];

        foreach ($cambios as $id => $c) {
            $id = (int) $id;

            if (isset($c['sku']) && $c['sku'] !== (string) ($actuales[$id] ?? '')) {
                $mapa[(string) $actuales[$id]] = $c['sku'];
                DB::table('products')->where('id', $id)->update(['sku' => $c['sku'], 'updated_at' => now()]);
                $this->valor($id, (int) $attr['sku'], $c['sku']);
                DB::table('product_flat')->where('product_id', $id)->update(['sku' => $c['sku']]);
            }

            if (isset($c['name'])) {
                $this->valor($id, (int) $attr['name'], $c['name']);
                DB::table('product_flat')->where('product_id', $id)->update(['name' => $c['name']]);
            }

            if (isset($c['url_key'])) {
                $this->urlKey($id, (int) $attr['url_key'], $c['url_key']);
            }
        }

        return $this->propagar($mapa);
    }

    /**
     * Lleva SKU viejo → nuevo a las copias guardadas en otras tablas.
     *
     * @param  array<string,string>  $mapa
     * @param  list<array{0:string,1:string}>|null  $columnas  por defecto, columnasCopia()
     */
    public function propagar(array $mapa, ?array $columnas = null): array
    {
        $mapa = array_filter($mapa, fn ($nuevo, $viejo) => $viejo !== '' && $nuevo !== $viejo, ARRAY_FILTER_USE_BOTH);
        $tocadas = [];

        if ($mapa === []) {
            return [];
        }

        foreach ($columnas ?? $this->columnasCopia() as [$tabla, $columna]) {
            $n = 0;

            foreach (array_chunk($mapa, 200, true) as $parte) {
                $casos = implode(' ', array_fill(0, count($parte), 'WHEN ? THEN ?'));
                $enlaces = [];

                foreach ($parte as $viejo => $nuevo) {
                    array_push($enlaces, (string) $viejo, (string) $nuevo);
                }

                $marcas = implode(',', array_fill(0, count($parte), '?'));
                $n += DB::update(
                    "UPDATE `{$tabla}` SET `{$columna}` = CASE BINARY `{$columna}` {$casos} ELSE `{$columna}` END WHERE `{$columna}` IN ({$marcas})",
                    [...$enlaces, ...array_map('strval', array_keys($parte))]
                );
            }

            if ($n > 0) {
                $tocadas[$tabla.'.'.$columna] = $n;
            }
        }

        return $tocadas;
    }

    /** @return list<array{0:string,1:string}> */
    public function columnasCopia(): array
    {
        $filas = DB::select(
            "SELECT table_name AS t, column_name AS c FROM information_schema.columns
             WHERE table_schema = DATABASE() AND column_name LIKE '%sku%' AND data_type IN ('varchar','char','text','tinytext','mediumtext')
             ORDER BY table_name, column_name"
        );
        $salida = [];

        foreach ($filas as $f) {
            $t = (string) $f->t;
            $c = (string) $f->c;

            if (in_array($t, ['products', 'product_flat'], true) || $c === 'sku_variante'
                || (str_starts_with($t, 'bylopez_cgi_') && $c !== 'producto_sku')) {
                continue;
            }

            $salida[] = [$t, $c];
        }

        return $salida;
    }

    /** Escribe el valor de texto de un atributo en TODAS sus filas (canal/idioma) o crea la fila sin canal ni idioma. */
    protected function valor(int $productoId, int $atributoId, string $texto): void
    {
        $n = DB::table('product_attribute_values')->where('product_id', $productoId)->where('attribute_id', $atributoId)->update(['text_value' => $texto]);

        if ($n === 0 && ! DB::table('product_attribute_values')->where('product_id', $productoId)->where('attribute_id', $atributoId)->exists()) {
            DB::table('product_attribute_values')->insert([
                'product_id' => $productoId, 'attribute_id' => $atributoId, 'text_value' => $texto, 'unique_id' => $productoId.'|'.$atributoId,
            ]);
        }
    }

    protected function urlKey(int $productoId, int $atributoId, string $nuevo): void
    {
        $viejos = DB::table('product_attribute_values')->where('product_id', $productoId)->where('attribute_id', $atributoId)
            ->pluck('text_value')->filter()->unique()->all();

        if ($viejos === [$nuevo]) {
            return;
        }

        $this->valor($productoId, $atributoId, $nuevo);
        DB::table('product_flat')->where('product_id', $productoId)->update(['url_key' => $nuevo]);

        if (! Schema::hasTable('url_rewrites')) {
            return;
        }

        $locale = core()->getDefaultLocaleCodeFromDefaultChannel();
        // La dirección nueva ya no puede ser un redireccionamiento hacia otra.
        DB::table('url_rewrites')->where('entity_type', 'product')->where('request_path', $nuevo)->delete();

        foreach ($viejos as $viejo) {
            if ($viejo === $nuevo) {
                continue;
            }

            // Sin cadenas: los que apuntaban al viejo apuntan al nuevo.
            DB::table('url_rewrites')->where('entity_type', 'product')->where('target_path', $viejo)->update(['target_path' => $nuevo, 'updated_at' => now()]);
            DB::table('url_rewrites')->where('entity_type', 'product')->where('request_path', $viejo)->delete();
            DB::table('url_rewrites')->insert([
                'entity_type' => 'product', 'request_path' => $viejo, 'target_path' => $nuevo, 'redirect_type' => 301,
                'locale' => $locale, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * url_key libre derivado del nombre (agrega -2, -3… si otro producto ya lo usa).
     *
     * @param  array<string,int>  $reservados  url_key => product_id ya repartidos en esta tanda
     * @param  list<int>  $seRenombran  productos cuyo url_key ACTUAL se va a reemplazar en esta tanda (no cuentan como ocupados)
     */
    public function urlKeyLibre(string $nombre, int $productoId, array $reservados = [], array $seRenombran = []): string
    {
        $base = Str::slug($nombre) ?: 'producto-'.$productoId;
        $attr = (int) DB::table('attributes')->where('code', 'url_key')->value('id');

        for ($n = 1; ; $n++) {
            $candidato = $n === 1 ? $base : $base.'-'.$n;
            $usado = (isset($reservados[$candidato]) && $reservados[$candidato] !== $productoId)
                || DB::table('product_attribute_values')->where('attribute_id', $attr)->where('text_value', $candidato)
                    ->whereNotIn('product_id', [$productoId, ...$seRenombran])->exists();

            if (! $usado) {
                return $candidato;
            }
        }
    }
}
