<?php

namespace ByLopez\VentasAsesor\Services\Precios;

use ByLopez\VentasAsesor\Services\Rentabilidad\RentabilidadVentaService;
use ByLopez\VentasAsesor\Support\Costos\CostoComisionableCatalogo;
use ByLopez\VentasAsesor\Support\Precios\EvaluacionPrecioGrupo;
use ByLopez\VentasAsesor\Support\Precios\MotorPrecioGrupo;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\CustomerGroupProxy;
use Webkul\Product\Contracts\Product;
use Webkul\Product\Models\ProductProxy;

/**
 * «Costos y precios › Precios por grupo» (Doc. 32): CONSULTA de solo lectura.
 *
 * - Trabaja con UN grupo de clientes existente. Las variantes vendibles son los productos `simple` (con o sin padre); se
 *   agrupan por referencia+color = el configurable padre (en este catalogo cada configurable es linea × color).
 * - Filtros sacados del catalogo, sin listas fijas: Publico = categorias de primer nivel del arbol del canal; Referencia =
 *   familia de atributos; Tela = atributo de tela de Rentabilidad; Color = atributo `color`; Talla = cualquier super
 *   atributo distinto de color (talla de adulto o grupo de edad de nino), en el orden de sus opciones.
 * - El precio de cada fila lo calcula Bagisto (MotorPrecioGrupo). Lecturas por lote: variantes con sus valores EAV,
 *   reglas, reglas de catalogo e indice precargados; no hay consultas por variante.
 * - El costo solo se lee si quien consulta tiene permiso (`$conCostos`); sin costo NO se muestra 0.
 */
class ConsultaPreciosGrupoService
{
    public const POR_PAGINA = 20;

    /** @var array<string, mixed> lecturas del catalogo que no cambian dentro de la peticion */
    private array $memo = [];

    public function __construct(
        protected MotorPrecioGrupo $motor,
        protected CostoComisionableCatalogo $costos,
        protected RentabilidadVentaService $rentabilidad,
    ) {}

    /** Grupos editables: todos los de Bagisto menos el invitado (mismo criterio que ValidadorGrupoComercial). @return Collection<int, object> */
    public function grupos(): Collection
    {
        return $this->memo['grupos'] ??= $this->leerGrupos();
    }

    private function leerGrupos(): Collection
    {
        $invitado = core()->getGuestCustomerGroup()?->id;

        return CustomerGroupProxy::modelClass()::query()
            ->when($invitado !== null, fn ($q) => $q->where('id', '!=', $invitado))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    public function grupo(?int $id): ?object
    {
        return $id === null ? null : $this->grupos()->firstWhere('id', $id);
    }

    /** @return array{publicos: array<int, string>, familias: array<int, string>, telas: array<int, string>, colores: array<int, string>, tallas: array<int, string>} */
    public function opciones(): array
    {
        $usadas = fn (array $atributos) => DB::table('product_attribute_values as v')
            ->join('attribute_options as o', 'o.id', '=', 'v.integer_value')
            ->whereIn('v.attribute_id', $atributos)
            ->distinct()
            ->orderBy('o.sort_order')->orderBy('o.admin_name')
            ->pluck('o.admin_name', 'o.id')
            ->map(fn ($n) => trim((string) $n))
            ->all();

        return [
            'publicos' => collect($this->publicos())->mapWithKeys(fn ($p) => [$p->id => $p->nombre])->all(),
            'familias' => DB::table('attribute_families')->orderBy('name')->pluck('name', 'id')->map(fn ($n) => trim((string) $n))->all(),
            'telas' => ($tela = $this->atributoTela()) === null ? [] : $usadas([$tela]),
            'colores' => ($color = $this->atributoColor()) === null ? [] : $usadas([$color]),
            'tallas' => ($tallas = $this->atributosTalla()) === [] ? [] : $usadas($tallas),
        ];
    }

    /**
     * Ids de TODAS las variantes que cumplen los filtros (para «Seleccionar todos los resultados»), en orden de pantalla.
     *
     * @param  array<string, mixed>  $filtros
     * @return list<int>
     */
    public function idsDelFiltro(array $filtros): array
    {
        return $this->consulta($filtros)
            ->orderByRaw('COALESCE(ppf.name, pf.name, p.sku)')->orderBy('ref')->orderBy('p.id')
            ->pluck('p.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Pagina de referencias (padre + color) con sus variantes filtradas y el precio de cada una para el grupo y la
     * cantidad consultada.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{referencias: LengthAwarePaginator, total_variantes: int}
     */
    public function listar(object $grupo, array $filtros, int $pagina, bool $conCostos): array
    {
        $filas = $this->consulta($filtros)
            ->orderByRaw('COALESCE(ppf.name, pf.name, p.sku)')->orderBy('ref')->orderBy('p.id')
            ->get(['p.id', DB::raw('COALESCE(p.parent_id, p.id) as ref')]);

        $refs = $filas->pluck('ref')->map(fn ($r) => (int) $r)->unique()->values();
        $pagina = max(1, $pagina);
        $porPagina = max(1, (int) config('ventasasesor.precios_grupo.por_pagina', self::POR_PAGINA));
        $enPagina = $refs->forPage($pagina, $porPagina)->values()->all();
        $ids = $filas->filter(fn ($f) => in_array((int) $f->ref, $enPagina, true))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $referencias = $this->referencias($grupo, $ids, (int) ($filtros['qty'] ?? 1), $conCostos);

        return [
            'referencias' => new LengthAwarePaginator(
                $referencias,
                $refs->count(),
                $porPagina,
                $pagina,
                ['path' => route('admin.ventas_asesor.precios_grupo.index')],
            ),
            'total_variantes' => $filas->count(),
        ];
    }

    /**
     * Referencias armadas para pantalla o vista previa, en el orden de `$ids` agrupado por referencia.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function referencias(object $grupo, array $ids, int $qty, bool $conCostos): array
    {
        if ($ids === []) {
            return [];
        }

        $variantes = $this->variantes($ids);
        $datos = $this->datosDeCatalogo($variantes);
        $costos = $conCostos ? $this->costos->costosDe($ids) : [];
        $salida = [];

        foreach ($ids as $id) {
            $v = $variantes->get($id);

            if ($v === null) {
                continue;
            }

            $d = $datos['variantes'][$id];
            $ref = $d['ref'];

            $salida[$ref] ??= array_merge($datos['referencias'][$ref], ['variantes' => []]);
            $salida[$ref]['variantes'][] = $this->fila($v, $grupo, $qty, $d, $conCostos ? ['costo' => $costos[$id] ?? null] : null);
        }

        return array_values(array_map(function (array $r) {
            usort($r['variantes'], fn ($a, $b) => [$a['talla_orden'], $a['sku']] <=> [$b['talla_orden'], $b['sku']]);

            return array_merge($r, ['resumen' => $this->resumen($r['variantes'])]);
        }, $salida));
    }

    /**
     * Variantes `simple` con lo que necesita el indexador de Bagisto, precargado. @param list<int> $ids
     *
     * @return Collection<int, Product> id => variante
     */
    public function variantes(array $ids): Collection
    {
        return ProductProxy::modelClass()::query()
            ->with(['attribute_family', 'attribute_values', 'customer_group_prices', 'catalog_rule_prices', 'price_indices'])
            ->where('type', 'simple')
            ->whereIn('id', $ids)
            ->get()
            ->each(fn ($p) => $this->motor->preparar($p))
            ->keyBy(fn ($p) => (int) $p->id);
    }

    /**
     * Nombre, SKU, talla, color, tela, familia y publico de variantes y referencias, por lote.
     *
     * @param  Collection<int, Product>  $variantes
     * @return array{variantes: array<int, array<string, mixed>>, referencias: array<int, array<string, mixed>>}
     */
    public function datosDeCatalogo(Collection $variantes): array
    {
        $ids = $variantes->keys()->all();
        $padres = $variantes->mapWithKeys(fn ($v) => [(int) $v->id => $v->parent_id === null ? null : (int) $v->parent_id])->all();
        $refs = array_values(array_unique(array_map(fn ($id) => $padres[$id] ?? $id, $ids)));
        $canal = core()->getDefaultChannelCode();
        $idioma = core()->getDefaultLocaleCodeFromDefaultChannel();

        $flat = DB::table('product_flat')->whereIn('product_id', array_merge($ids, $refs))
            ->where('channel', $canal)->where('locale', $idioma)->get(['product_id', 'name', 'sku', 'status'])->keyBy('product_id');
        $productos = DB::table('products as p')->leftJoin('attribute_families as af', 'af.id', '=', 'p.attribute_family_id')
            ->whereIn('p.id', $refs)->get(['p.id', 'p.sku', 'af.name as familia'])->keyBy('id');

        $color = $this->atributoColor();
        $tallas = $this->atributosTalla();
        $opciones = DB::table('product_attribute_values as v')->join('attribute_options as o', 'o.id', '=', 'v.integer_value')
            ->whereIn('v.product_id', $ids)->whereIn('v.attribute_id', array_filter([$color, ...$tallas]))
            ->get(['v.product_id', 'v.attribute_id', 'o.id', 'o.admin_name', 'o.sort_order']);
        $telas = $this->rentabilidad->telasDe($ids);
        $nombresTela = DB::table('attribute_options')->whereIn('id', array_filter(array_values($telas)))->pluck('admin_name', 'id');
        $publicos = $this->publicosDe($refs);

        $salidaV = [];
        $porProducto = [];

        foreach ($opciones as $o) {
            $porProducto[(int) $o->product_id][] = $o;
        }

        foreach ($ids as $id) {
            $c = null;
            $t = null;

            foreach ($porProducto[$id] ?? [] as $o) {
                if ((int) $o->attribute_id === $color) {
                    $c ??= $o;
                } elseif (in_array((int) $o->attribute_id, $tallas, true)) {
                    $t ??= $o;
                }
            }

            $f = $flat->get($id);

            $salidaV[$id] = [
                'ref' => $padres[$id] ?? $id,
                'nombre' => (string) ($f->name ?? $variantes[$id]->sku),
                'activa' => $f === null ? true : (bool) $f->status,
                'color' => $c ? trim((string) $c->admin_name) : null,
                'talla' => $t ? trim((string) $t->admin_name) : null,
                'talla_orden' => $t ? (int) $t->sort_order : PHP_INT_MAX,
                'tela' => ($telas[$id] ?? null) === null ? null : trim((string) ($nombresTela[$telas[$id]] ?? '')),
            ];
        }

        $salidaR = [];
        $porRef = [];

        foreach ($salidaV as $v) {
            $porRef[$v['ref']][] = $v;
        }

        foreach ($refs as $ref) {
            $suyas = $porRef[$ref] ?? [];
            $colores = array_values(array_unique(array_filter(array_column($suyas, 'color'))));
            $telasRef = array_values(array_unique(array_filter(array_column($suyas, 'tela'))));
            $f = $flat->get($ref);

            $salidaR[$ref] = [
                'id' => $ref,
                'nombre' => (string) ($f->name ?? $productos[$ref]->sku ?? $ref),
                'sku' => (string) ($productos[$ref]->sku ?? ''),
                'familia' => $productos[$ref]->familia ?? null,
                'color' => count($colores) === 1 ? $colores[0] : null,
                'colores' => $colores,
                'tela' => count($telasRef) === 1 ? $telasRef[0] : null,
                'publicos' => $publicos[$ref] ?? [],
            ];
        }

        return ['variantes' => $salidaV, 'referencias' => $salidaR];
    }

    /**
     * Comparacion SECUNDARIA (bajo demanda, desde el editor): precio de la variante en cada grupo para esa cantidad.
     *
     * @return list<array{nombre: string, precio: float, origen: EvaluacionPrecioGrupo, actual: bool}>
     */
    public function otrosGrupos(int $varianteId, int $grupoActual, int $qty): array
    {
        $v = $this->variantes([$varianteId])->first();

        if ($v === null) {
            return [];
        }

        return $this->grupos()->map(fn ($g) => [
            'nombre' => (string) $g->name,
            'precio' => ($e = $this->motor->evaluar($v, $g, max(1, $qty)))->final,
            'origen' => $e,
            'actual' => (int) $g->id === $grupoActual,
        ])->values()->all();
    }

    /**
     * Una variante en pantalla: reglas del grupo (y de «todos los grupos»), precio a la cantidad consultada con su origen,
     * aviso de indice desactualizado y, con permiso, costo y diferencia.
     *
     * @param  array<string, mixed>  $d
     * @param  array{costo: ?float}|null  $costo
     * @return array<string, mixed>
     */
    public function fila(Product $v, object $grupo, int $qty, array $d, ?array $costo): array
    {
        $grupoId = (int) $grupo->id;
        $eval = $this->motor->evaluar($v, $grupo, $qty);
        $sinEfecto = $this->motor->escalonesSinEfecto($v, $grupoId);

        $reglas = $v->customer_group_prices
            ->filter(fn ($r) => $r->customer_group_id !== null && (int) $r->customer_group_id === $grupoId)
            ->sortBy(fn ($r) => [(int) $r->qty, (int) $r->id])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'qty' => (int) $r->qty,
                'tipo' => (string) $r->value_type,
                'valor' => (float) $r->value,
                'precio' => $this->motor->evaluar($v, $grupo, max(1, (int) $r->qty))->final,
                'sin_efecto' => in_array((int) $r->qty, $sinEfecto, true),
            ])->values()->all();

        $todos = $v->customer_group_prices->whereNull('customer_group_id')->sortBy('qty')
            ->map(fn ($r) => ['qty' => (int) $r->qty, 'tipo' => (string) $r->value_type, 'valor' => (float) $r->value])->values()->all();

        $indice = $v->price_indices
            ->where('channel_id', core()->getCurrentChannel()->id)
            ->where('customer_group_id', $grupoId)
            ->first();
        $alUno = $qty === 1 ? $eval : $this->motor->evaluar($v, $grupo, 1);

        $fila = array_merge($d, [
            'id' => (int) $v->id,
            'sku' => (string) $v->sku,
            'base' => $eval->base,
            'reglas' => $reglas,
            'reglas_todos' => $todos,
            'eval' => $eval,
            'indice_desactualizado' => $indice === null || abs((float) $indice->min_price - $alUno->final) > 0.00005,
            'con_costos' => $costo !== null,
            'costo' => null,
            'diferencia' => null,
        ]);

        if ($costo !== null && $costo['costo'] !== null) {
            $fila['costo'] = (float) $costo['costo'];
            $fila['diferencia'] = round($eval->final - (float) $costo['costo'], 2);
        }

        return $fila;
    }

    /**
     * Resumen de la referencia: precio a la cantidad consultada (uno o «Precios variados») y estado de las reglas.
     *
     * @param  list<array<string, mixed>>  $variantes
     * @return array{precio_min: float, precio_max: float, variado: bool, sin_regla: int, reglas_iguales: bool, reglas: list<array<string, mixed>>}
     */
    public function resumen(array $variantes): array
    {
        $precios = array_map(fn ($v) => $v['eval']->final, $variantes);
        $firmas = array_map(fn ($v) => json_encode(array_map(fn ($r) => [$r['qty'], $r['tipo'], round($r['valor'], 4)], $v['reglas'])), $variantes);

        return [
            'precio_min' => min($precios),
            'precio_max' => max($precios),
            'variado' => abs(max($precios) - min($precios)) > 0.00005,
            'sin_regla' => count(array_filter($variantes, fn ($v) => $v['reglas'] === [])),
            'reglas_iguales' => count(array_unique($firmas)) === 1,
            'reglas' => $variantes[0]['reglas'] ?? [],
        ];
    }

    /** @param  array<string, mixed>  $filtros */
    protected function consulta(array $filtros): Builder
    {
        $canal = core()->getDefaultChannelCode();
        $idioma = core()->getDefaultLocaleCodeFromDefaultChannel();
        $grupo = isset($filtros['grupo']) ? (int) $filtros['grupo'] : null;

        $q = DB::table('products as p')
            ->leftJoin('products as padre', 'padre.id', '=', 'p.parent_id')
            ->leftJoin('product_flat as pf', fn ($j) => $j->on('pf.product_id', '=', 'p.id')->where('pf.channel', $canal)->where('pf.locale', $idioma))
            ->leftJoin('product_flat as ppf', fn ($j) => $j->on('ppf.product_id', '=', 'padre.id')->where('ppf.channel', $canal)->where('ppf.locale', $idioma))
            ->where('p.type', 'simple')
            ->select(['p.id', DB::raw('COALESCE(p.parent_id, p.id) as ref')]);

        if (! empty($filtros['familia'])) {
            $q->whereRaw('COALESCE(padre.attribute_family_id, p.attribute_family_id) = ?', [(int) $filtros['familia']]);
        }

        if (! empty($filtros['publico']) && ($publico = collect($this->publicos())->firstWhere('id', (int) $filtros['publico']))) {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('product_categories as pc')
                ->join('categories as c', 'c.id', '=', 'pc.category_id')
                ->whereRaw('pc.product_id = COALESCE(p.parent_id, p.id)')
                ->whereBetween('c._lft', [$publico->lft, $publico->rgt]));
        }

        foreach (['color' => [$this->atributoColor()], 'talla' => $this->atributosTalla()] as $clave => $atributos) {
            if (! empty($filtros[$clave]) && array_filter($atributos) !== []) {
                $q->whereExists(fn ($e) => $e->selectRaw('1')->from('product_attribute_values as av')
                    ->whereColumn('av.product_id', 'p.id')
                    ->whereIn('av.attribute_id', array_filter($atributos))
                    ->where('av.integer_value', (int) $filtros[$clave]));
            }
        }

        if (! empty($filtros['tela']) && ($tela = $this->atributoTela()) !== null) {
            // Misma regla que RentabilidadVentaService::telasDe: la tela de la variante y, si no tiene, la del padre.
            $q->where(function ($w) use ($tela, $filtros) {
                $propia = fn ($e) => $e->selectRaw('1')->from('product_attribute_values as tv')
                    ->whereColumn('tv.product_id', 'p.id')->where('tv.attribute_id', $tela)->whereNotNull('tv.integer_value');

                $w->whereExists(fn ($e) => $propia($e)->where('tv.integer_value', (int) $filtros['tela']))
                    ->orWhere(fn ($o) => $o->whereNotExists($propia)
                        ->whereExists(fn ($e) => $e->selectRaw('1')->from('product_attribute_values as tp')
                            ->whereColumn('tp.product_id', 'p.parent_id')->where('tp.attribute_id', $tela)
                            ->where('tp.integer_value', (int) $filtros['tela'])));
            });
        }

        if (! empty($filtros['q'])) {
            $texto = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $filtros['q']).'%';
            $q->where(fn ($w) => $w->where('p.sku', 'like', $texto)->orWhere('padre.sku', 'like', $texto)
                ->orWhere('pf.name', 'like', $texto)->orWhere('ppf.name', 'like', $texto));
        }

        // Con / sin precio especifico DEL GRUPO elegido (cualquier escalon). `sin_regla` se acepta por compatibilidad.
        $regla = $filtros['regla'] ?? (! empty($filtros['sin_regla']) ? 'sin' : null);

        if (in_array($regla, ['con', 'sin'], true) && $grupo !== null) {
            $existe = fn ($e) => $e->selectRaw('1')->from('product_customer_group_prices as g')
                ->whereColumn('g.product_id', 'p.id')->where('g.customer_group_id', $grupo);
            $regla === 'sin' ? $q->whereNotExists($existe) : $q->whereExists($existe);
        }

        return $q;
    }

    /** Categorias de primer nivel del arbol del canal (Publico). @return list<object{id: int, nombre: string, lft: int, rgt: int}> */
    protected function publicos(): array
    {
        return $this->memo['publicos'] ??= $this->leerPublicos();
    }

    /** @return list<object> */
    private function leerPublicos(): array
    {
        $raiz = core()->getDefaultChannel()->root_category_id;
        $idioma = core()->getDefaultLocaleCodeFromDefaultChannel();

        return DB::table('categories as c')
            ->leftJoin('category_translations as t', fn ($j) => $j->on('t.category_id', '=', 'c.id')->where('t.locale', $idioma))
            ->where('c.parent_id', $raiz)
            ->orderBy('c.position')->orderBy('c._lft')
            ->get(['c.id', 't.name', 'c._lft', 'c._rgt'])
            ->map(fn ($c) => (object) ['id' => (int) $c->id, 'nombre' => trim((string) ($c->name ?? $c->id)), 'lft' => (int) $c->_lft, 'rgt' => (int) $c->_rgt])
            ->filter(fn ($c) => DB::table('product_categories as pc')->join('categories as d', 'd.id', '=', 'pc.category_id')
                ->whereBetween('d._lft', [$c->lft, $c->rgt])->exists())
            ->values()
            ->all();
    }

    /** @param list<int> $refs @return array<int, list<string>> */
    protected function publicosDe(array $refs): array
    {
        $publicos = $this->publicos();

        if ($publicos === [] || $refs === []) {
            return [];
        }

        $salida = [];

        foreach (DB::table('product_categories as pc')->join('categories as c', 'c.id', '=', 'pc.category_id')
            ->whereIn('pc.product_id', $refs)->get(['pc.product_id', 'c._lft']) as $fila) {
            foreach ($publicos as $p) {
                if ($fila->_lft >= $p->lft && $fila->_lft <= $p->rgt) {
                    $salida[(int) $fila->product_id][$p->id] = $p->nombre;
                }
            }
        }

        return array_map(fn ($a) => array_values($a), $salida);
    }

    protected function atributoColor(): ?int
    {
        if (! array_key_exists('color', $this->memo)) {
            $id = DB::table('attributes')->where('code', 'color')->value('id');
            $this->memo['color'] = $id === null ? null : (int) $id;
        }

        return $this->memo['color'];
    }

    protected function atributoTela(): ?int
    {
        if (! array_key_exists('tela', $this->memo)) {
            $id = DB::table('attributes')->where('code', (string) config('ventasasesor.rentabilidad.atributo_tela', 'tipo_tela'))->value('id');
            $this->memo['tela'] = $id === null ? null : (int) $id;
        }

        return $this->memo['tela'];
    }

    /** Super atributos en uso distintos de color: la «talla» (adulto) o el grupo de edad (nino). @return list<int> */
    protected function atributosTalla(): array
    {
        return $this->memo['tallas'] ??= DB::table('product_super_attributes')->distinct()
            ->when($this->atributoColor() !== null, fn ($q) => $q->where('attribute_id', '!=', $this->atributoColor()))
            ->pluck('attribute_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /** Etiqueta del origen (para pantalla y vista previa). */
    public static function etiquetaOrigen(EvaluacionPrecioGrupo $e): string
    {
        return trans('ventasasesor::app.precios.origen.'.$e->origen, [
            'qty' => $e->regla['qty'] ?? 1,
        ]);
    }
}
