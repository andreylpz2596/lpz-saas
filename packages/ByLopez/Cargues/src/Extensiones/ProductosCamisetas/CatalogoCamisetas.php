<?php

namespace ByLopez\Cargues\Extensiones\ProductosCamisetas;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lectura del catalogo de Bagisto para el tipo «Productos — Camisetas piel de durazno» (docs/17). SOLO LEE. Todo se resuelve por
 * CODIGO (atributos, familias) o por TEXTO EXACTO normalizado (opciones, categorias): nunca por id, porque los ids cambian entre entornos.
 * Normalizar = minusculas, sin tildes y espacios simples; NO se quitan guiones ni se aceptan coincidencias parciales.
 * Una instancia por analisis (cache en memoria): el plan se vuelve a calcular con una instancia nueva al aplicar.
 */
class CatalogoCamisetas
{
    /** @var array<string, ?object> */
    protected array $atributos = [];

    /** @var array<string, array<string, list<int>>> */
    protected array $opciones = [];

    /** @var array<int, array<int, string>> option_id => texto, por atributo */
    protected array $textos = [];

    /** @var array<string, ?array{id:int, nombre:string, atributos:list<string>}> */
    protected array $familias = [];

    public static function clave(?string $texto): string
    {
        $t = Str::ascii(mb_strtolower(trim((string) $texto)));

        return trim((string) preg_replace('/\s+/', ' ', $t));
    }

    public function atributo(string $codigo): ?object
    {
        return $this->atributos[$codigo] ??= DB::table('attributes')->where('code', $codigo)->first(['id', 'code', 'admin_name', 'type']);
    }

    /**
     * Opcion de un atributo select por su texto (nombre administrativo o traduccion). Exacta tras normalizar.
     *
     * @return array{0: ?int, 1: 'ok'|'desconocida'|'ambigua'|'sin_atributo'}
     */
    public function opcion(string $codigoAtributo, string $texto): array
    {
        $a = $this->atributo($codigoAtributo);

        if (! $a) {
            return [null, 'sin_atributo'];
        }

        if (! isset($this->opciones[$codigoAtributo])) {
            $mapa = [];
            $textos = [];

            foreach (DB::table('attribute_options')->where('attribute_id', $a->id)->get(['id', 'admin_name']) as $o) {
                $mapa[self::clave($o->admin_name)][] = (int) $o->id;
                $textos[(int) $o->id] = (string) $o->admin_name;
            }

            $traducciones = DB::table('attribute_option_translations as t')->join('attribute_options as o', 'o.id', '=', 't.attribute_option_id')
                ->where('o.attribute_id', $a->id)->whereNotNull('t.label')->get(['t.attribute_option_id', 't.label']);

            foreach ($traducciones as $t) {
                $mapa[self::clave($t->label)][] = (int) $t->attribute_option_id;
            }

            $this->opciones[$codigoAtributo] = array_map(fn ($ids) => array_values(array_unique($ids)), $mapa);
            $this->textos[$a->id] = $textos;
        }

        $ids = $this->opciones[$codigoAtributo][self::clave($texto)] ?? [];

        return match (count($ids)) {
            0 => [null, 'desconocida'],
            1 => [$ids[0], 'ok'],
            default => [null, 'ambigua'],
        };
    }

    /** Texto administrativo de una opcion (para mostrar lo que hay guardado). */
    public function textoOpcion(string $codigoAtributo, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $this->opcion($codigoAtributo, '');
        $a = $this->atributo($codigoAtributo);

        return $a ? ($this->textos[$a->id][$id] ?? '#'.$id) : null;
    }

    /** @return ?array{id:int, nombre:string, atributos:list<string>} */
    public function familia(string $codigo): ?array
    {
        if (! array_key_exists($codigo, $this->familias)) {
            $f = DB::table('attribute_families')->where('code', $codigo)->first(['id', 'name']);
            $this->familias[$codigo] = $f ? [
                'id' => (int) $f->id, 'nombre' => trim((string) $f->name),
                'atributos' => DB::table('attribute_group_mappings as m')->join('attribute_groups as g', 'g.id', '=', 'm.attribute_group_id')
                    ->join('attributes as a', 'a.id', '=', 'm.attribute_id')->where('g.attribute_family_id', $f->id)->pluck('a.code')->all(),
            ] : null;
        }

        return $this->familias[$codigo];
    }

    public function nombreFamilia(int $id): string
    {
        return trim((string) DB::table('attribute_families')->where('id', $id)->value('name')) ?: '#'.$id;
    }

    /**
     * Categorias por NOMBRE exacto (normalizado) en el idioma por defecto. Un nombre ausente o repetido no se resuelve.
     *
     * @return array{0: list<int>, 1: list<string>} [ids, nombres no resueltos]
     */
    public function categorias(array $nombres): array
    {
        $ids = [];
        $faltan = [];
        $locale = core()->getDefaultLocaleCodeFromDefaultChannel();
        $todas = DB::table('category_translations')->where('locale', $locale)->get(['category_id', 'name']);

        foreach ($nombres as $n) {
            $c = $todas->filter(fn ($t) => self::clave($t->name) === self::clave($n))->pluck('category_id')->unique();

            if ($c->count() === 1) {
                $ids[] = (int) $c->first();
            } else {
                $faltan[] = $n;
            }
        }

        return [$ids, $faltan];
    }

    /**
     * Productos por SKU EXACTO (la columna `products.sku` es unica; la comparacion de la BD no distingue mayusculas).
     *
     * @return array<string, object> clave(sku) => {id, sku, type, parent_id, attribute_family_id}
     */
    public function productosPorSku(array $skus): array
    {
        $r = [];

        foreach (array_chunk(array_values(array_unique(array_filter($skus, fn ($s) => $s !== ''))), 500) as $lote) {
            foreach (DB::table('products')->whereIn('sku', $lote)->get(['id', 'sku', 'type', 'parent_id', 'attribute_family_id']) as $p) {
                $r[mb_strtolower((string) $p->sku)] = $p;
            }
        }

        return $r;
    }

    /** @return list<string> codigos de los super atributos de un configurable */
    public function superAtributos(int $productoId): array
    {
        return DB::table('product_super_attributes as s')->join('attributes as a', 'a.id', '=', 's.attribute_id')
            ->where('s.product_id', $productoId)->orderBy('a.code')->pluck('a.code')->all();
    }

    /**
     * Valores guardados de unos productos (atributos globales o del canal/idioma por defecto).
     *
     * @return array<int, array<string, mixed>> product_id => codigo => valor
     */
    public function valores(array $productIds, array $codigos): array
    {
        if ($productIds === []) {
            return [];
        }

        $locale = core()->getDefaultLocaleCodeFromDefaultChannel();
        $canal = core()->getDefaultChannelCode();
        $filas = DB::table('product_attribute_values as v')->join('attributes as a', 'a.id', '=', 'v.attribute_id')
            ->whereIn('v.product_id', $productIds)->whereIn('a.code', $codigos)
            ->where(fn ($q) => $q->whereNull('v.locale')->orWhere('v.locale', $locale))
            ->where(fn ($q) => $q->whereNull('v.channel')->orWhere('v.channel', $canal))
            ->get(['v.product_id', 'a.code', 'a.type', 'v.text_value', 'v.integer_value', 'v.float_value', 'v.boolean_value']);
        $r = [];

        foreach ($filas as $f) {
            $r[(int) $f->product_id][$f->code] = match ($f->type) {
                'select' => $f->integer_value === null ? null : (int) $f->integer_value,
                'price' => $f->float_value === null ? null : (float) $f->float_value,
                'boolean' => $f->boolean_value === null ? null : (bool) $f->boolean_value,
                default => $f->text_value,
            };
        }

        return $r;
    }

    /** @return list<object> variantes reales {id, sku} de un configurable */
    public function variantes(int $padreId): array
    {
        return DB::table('products')->where('parent_id', $padreId)->orderBy('id')->get(['id', 'sku'])->all();
    }

    /**
     * Colores que YA tiene la familia (linea): color option_id => [id, sku] del primer configurable de primer nivel cuyas variantes
     * lo llevan. Nueva venta muestra UN producto por linea y color, asi que un producto nuevo en un color ocupado no seria elegible alli.
     *
     * @return array<int, array{0:int, 1:string}>
     */
    public function coloresOcupados(int $familiaId, string $codigoColor): array
    {
        $a = $this->atributo($codigoColor);

        if (! $a) {
            return [];
        }

        $r = [];
        $filas = DB::table('products as p')->join('products as h', 'h.parent_id', '=', 'p.id')
            ->join('product_attribute_values as v', fn ($j) => $j->on('v.product_id', '=', 'h.id')->where('v.attribute_id', $a->id))
            ->where('p.attribute_family_id', $familiaId)->whereNull('p.parent_id')->where('p.type', 'configurable')->whereNotNull('v.integer_value')
            ->orderBy('p.id')->get(['v.integer_value', 'p.id', 'p.sku']);

        foreach ($filas as $f) {
            $r[(int) $f->integer_value] ??= [(int) $f->id, (string) $f->sku];
        }

        return $r;
    }

    /** ¿Existe ya ese url_key (en cualquier producto, idioma por defecto)? */
    public function urlKeyUsada(string $urlKey): bool
    {
        $a = $this->atributo('url_key');

        return $a && DB::table('product_attribute_values')->where('attribute_id', $a->id)->where('text_value', $urlKey)->exists();
    }
}
