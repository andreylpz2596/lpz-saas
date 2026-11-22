<?php

namespace ByLopez\VentasAsesor\Services\Catalogo;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc. 33 — Código único (SKU) por patrón: NÚMERO DE COLOR + PÚBLICO, LÍNEA y TALLA (config `ventasasesor.sku`).
 *
 *   Padre 115D-DZ · Variante 115D-DZ-M · Niño 115N-DZ-6 · Crop top 106D-TP-U
 *
 * - El número del color sale de `bylopez_va_sku_colores`; un color sin número recibe el siguiente libre (`asignar`) o, en una
 *   vista previa, uno PROVISIONAL en memoria (distinto para cada color nuevo de la misma sesión, sin escribir nada).
 * - Solo calcula. Quien escribe es RenombradorSkuService.
 * - Una instancia es una «sesión»: los códigos que ya repartió quedan reservados en ella (dos productos del mismo color, público
 *   y línea no reciben el mismo código: el segundo lleva un número tras la línea, 115D-DZ2).
 */
class CodigoSkuService
{
    public const TABLA_COLORES = 'bylopez_va_sku_colores';

    /** @var array<int,int>|null opción de color => número */
    protected ?array $codigos = null;

    /** @var array<int,int> opción de color => número provisional (vista previa) */
    protected array $provisionales = [];

    /** @var array<string,int> sku (mayúsculas) => product_id ya reservado en esta sesión */
    protected array $reservados = [];

    protected array $familias = [];

    protected array $atributos = [];

    protected array $textos = [];

    public static function clave(?string $texto): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::ascii(mb_strtolower(trim((string) $texto)))));
    }

    /** Códigos de línea configurados (DZ, TP…), los más largos primero. @return list<string> */
    public static function lineas(): array
    {
        $cfg = (array) config('ventasasesor.sku');
        $l = array_values((array) ($cfg['telas'] ?? []));

        foreach ((array) ($cfg['familias'] ?? []) as $f) {
            $l[] = $f['linea'] ?? null;
            $l[] = $f['linea_defecto'] ?? null;
        }

        $l = array_values(array_unique(array_filter(array_map('strtoupper', array_filter($l)))));
        usort($l, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $l;
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Piezas del código
    // -----------------------------------------------------------------------------------------------------------------

    /** Configuración de la familia (genero, linea, linea_defecto, nombre) o null si no sigue el patrón. */
    public function familia(?int $familiaId): ?array
    {
        if (! $familiaId) {
            return null;
        }

        if (! array_key_exists($familiaId, $this->familias)) {
            $codigo = DB::table('attribute_families')->where('id', $familiaId)->value('code');
            $cfg = $codigo === null ? null : (config('ventasasesor.sku.familias')[$codigo] ?? null);
            $this->familias[$familiaId] = is_array($cfg) && ! empty($cfg['genero']) ? $cfg + ['codigo' => $codigo] : null;
        }

        return $this->familias[$familiaId];
    }

    /** @return array<int,int> */
    public function codigos(): array
    {
        return $this->codigos ??= DB::table(self::TABLA_COLORES)->pluck('codigo', 'attribute_option_id')
            ->mapWithKeys(fn ($c, $o) => [(int) $o => (int) $c])->all();
    }

    /** Usa estos números (vista previa de la normalización) sin escribirlos. @param array<int,int> $codigos */
    public function usarCodigos(array $codigos): void
    {
        $this->codigos = $codigos + $this->codigos();
    }

    public function siguienteCodigo(): int
    {
        $usados = [...array_values($this->codigos()), ...array_values($this->provisionales)];
        $max = (int) DB::table(self::TABLA_COLORES)->max('codigo');

        return max([$max, ...$usados, (int) config('ventasasesor.sku.primer_codigo_color', 101) - 1]) + 1;
    }

    /** Número del color. Sin número: con `asignar` lo crea (siguiente libre); sin `asignar` devuelve uno provisional. */
    public function codigoColor(int $opcionId, bool $asignar = true): int
    {
        if (isset($this->codigos()[$opcionId])) {
            return $this->codigos()[$opcionId];
        }

        if (! $asignar) {
            return $this->provisionales[$opcionId] ??= $this->siguienteCodigo();
        }

        for ($intento = 1; ; $intento++) {
            try {
                $codigo = DB::transaction(function () use ($opcionId) {
                    DB::table(self::TABLA_COLORES)->lockForUpdate()->get(['id']);     // serializa la asignación
                    $ya = DB::table(self::TABLA_COLORES)->where('attribute_option_id', $opcionId)->value('codigo');

                    if ($ya !== null) {
                        return (int) $ya;
                    }

                    $this->codigos = null;
                    unset($this->provisionales[$opcionId]);
                    $n = $this->siguienteCodigo();
                    DB::table(self::TABLA_COLORES)->insert([
                        'attribute_option_id' => $opcionId, 'codigo' => $n, 'color_snapshot' => $this->texto($opcionId),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);

                    return $n;
                });

                $this->codigos = null;

                return $codigo;
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062 || $intento >= 3) {
                    throw $e;
                }

                $this->codigos = null;
            }
        }
    }

    /** Línea (DZ, TP…): la fija de la familia, la de la tela, o la por defecto de la familia. */
    public function linea(array $familia, ?int $telaId): ?string
    {
        if (! empty($familia['linea'])) {
            return strtoupper((string) $familia['linea']);
        }

        if ($telaId) {
            $tela = self::clave($this->texto($telaId));

            foreach ((array) config('ventasasesor.sku.telas', []) as $nombre => $codigo) {
                if (self::clave((string) $nombre) === $tela) {
                    return strtoupper((string) $codigo);
                }
            }
        }

        return empty($familia['linea_defecto']) ? null : strtoupper((string) $familia['linea_defecto']);
    }

    /** Código de la talla en el SKU (S, M, XL, U, 2, 6…). */
    public function talla(int $opcionId): ?string
    {
        $texto = $this->texto($opcionId);

        if ($texto === null) {
            return null;
        }

        foreach ((array) config('ventasasesor.sku.tallas', []) as $nombre => $codigo) {
            if (self::clave((string) $nombre) === self::clave($texto)) {
                return (string) $codigo;
            }
        }

        $c = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($texto)));

        return $c === '' ? null : $c;
    }

    /** Texto de la talla para el NOMBRE de la variante. */
    public function tallaNombre(int $opcionId): ?string
    {
        $texto = $this->texto($opcionId);

        foreach ((array) config('ventasasesor.sku.tallas_nombre', []) as $nombre => $etiqueta) {
            if (self::clave((string) $nombre) === self::clave($texto)) {
                return (string) $etiqueta;
            }
        }

        return $texto === null ? null : trim($texto);
    }

    /** «115D-DZ» (sin sufijo de repetición) o null si la familia no sigue el patrón o falta la línea. */
    public function base(?int $familiaId, int $colorId, ?int $telaId, bool $asignar = true): ?string
    {
        $familia = $this->familia($familiaId);
        $linea = $familia === null ? null : $this->linea($familia, $telaId);

        return $linea === null ? null : $this->codigoColor($colorId, $asignar).strtoupper((string) $familia['genero']).'-'.$linea;
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Plan de un producto configurable (padre + variantes)
    // -----------------------------------------------------------------------------------------------------------------

    /**
     * SKU que le corresponde a cada producto del configurable. Solo incluye los que se pueden calcular; un producto que no se
     * puede calcular (sin color, sin talla, familia fuera del patrón, código ocupado por otro producto) conserva su SKU.
     *
     * @return array{raiz:?object, padre:?array, variantes:list<array>, familia:?array}
     *               padre/variantes: ['id', 'actual', 'nuevo', 'color_id', 'talla_id']
     */
    public function plan(int $raizId, bool $asignar = true): array
    {
        $vacio = ['raiz' => null, 'padre' => null, 'variantes' => [], 'familia' => null];
        $raiz = DB::table('products')->where('id', $raizId)->first(['id', 'sku', 'type', 'parent_id', 'attribute_family_id']);

        if (! $raiz || $raiz->type !== 'configurable' || $raiz->parent_id !== null || ($familia = $this->familia((int) $raiz->attribute_family_id)) === null) {
            return $vacio;
        }

        $hijos = DB::table('products')->where('parent_id', $raizId)->orderBy('id')->get(['id', 'sku']);
        $ids = [$raizId, ...$hijos->pluck('id')->map(fn ($i) => (int) $i)->all()];
        $color = $this->atributo('color');
        $tallas = array_values(array_filter(array_map(fn ($c) => $this->atributo((string) $c), (array) config('ventasasesor.sku.atributos_talla', ['size']))));
        $telaAttr = $this->atributo('tipo_tela');

        $valores = DB::table('product_attribute_values')->whereIn('product_id', $ids)
            ->whereIn('attribute_id', array_filter([$color, $telaAttr, ...$tallas]))->get(['product_id', 'attribute_id', 'integer_value'])
            ->groupBy('product_id');
        $valor = fn (int $pid, ?int $aid) => $aid === null ? null
            : (($v = ($valores->get($pid) ?? collect())->firstWhere('attribute_id', $aid)) && $v->integer_value !== null ? (int) $v->integer_value : null);

        $tela = $valor($raizId, $telaAttr);
        $colores = [];
        $variantes = [];

        foreach ($hijos as $h) {
            $cid = $valor((int) $h->id, $color);
            $tid = null;

            foreach ($tallas as $aid) {
                $tid ??= $valor((int) $h->id, $aid);
            }

            $tela ??= $valor((int) $h->id, $telaAttr);
            $cid !== null && $colores[$cid] = true;
            $variantes[] = ['id' => (int) $h->id, 'actual' => (string) $h->sku, 'nuevo' => null, 'color_id' => $cid, 'talla_id' => $tid];
        }

        // Padre: solo si TODAS sus variantes son de un mismo color (configurable = línea × color).
        $padre = ['id' => $raizId, 'actual' => (string) $raiz->sku, 'nuevo' => null, 'color_id' => null, 'talla_id' => null];
        $bases = [];                                                     // color => base resuelta para este configurable

        if (count($colores) === 1) {
            $padre['color_id'] = (int) array_key_first($colores);
            $base = $this->base((int) $raiz->attribute_family_id, $padre['color_id'], $tela, $asignar);

            if ($base !== null) {
                $bases[$padre['color_id']] = $this->resolverBase($base, (string) $raiz->sku, $ids);
                $padre['nuevo'] = $bases[$padre['color_id']];
            }
        }

        foreach ($variantes as &$v) {
            if ($v['color_id'] === null || $v['talla_id'] === null || ($talla = $this->talla($v['talla_id'])) === null) {
                continue;
            }

            if (! isset($bases[$v['color_id']])) {
                $b = $this->base((int) $raiz->attribute_family_id, $v['color_id'], $tela, $asignar);

                if ($b === null) {
                    continue;
                }

                $bases[$v['color_id']] = $b;
            }

            $sku = $bases[$v['color_id']].'-'.$talla;

            if (! $this->ocupado($sku, $ids) && ! isset($this->reservados[strtoupper($sku)])) {
                $v['nuevo'] = $sku;
                $this->reservados[strtoupper($sku)] = $v['id'];
            }
        }
        unset($v);

        if ($padre['nuevo'] !== null) {
            $this->reservados[strtoupper($padre['nuevo'])] = $raizId;
        }

        return ['raiz' => $raiz, 'padre' => $padre, 'variantes' => $variantes, 'familia' => $familia];
    }

    /**
     * La base libre para este configurable: si su SKU actual ya es la base (o la base con número, 115D-DZ2) se conserva; si no,
     * la base si está libre; si no, 115D-DZ2, 115D-DZ3…
     */
    protected function resolverBase(string $base, string $actual, array $ids): string
    {
        $propio = strcasecmp($actual, $base) === 0 || preg_match('/^'.preg_quote($base, '/').'\d+$/i', $actual);

        if ($propio && ! $this->baseOcupada(strtoupper($actual), $ids)) {
            return strtoupper($actual);
        }

        for ($n = 1; ; $n++) {
            $candidata = $n === 1 ? $base : $base.$n;

            if (! $this->baseOcupada($candidata, $ids)) {
                return $candidata;
            }
        }
    }

    /** Otra base de producto (o sus variantes) ya usa este código, en la base de datos o en esta sesión. */
    protected function baseOcupada(string $base, array $ids): bool
    {
        $b = strtoupper($base);

        foreach ($this->reservados as $sku => $pid) {
            if (! in_array($pid, $ids, true) && ($sku === $b || str_starts_with($sku, $b.'-'))) {
                return true;
            }
        }

        return DB::table('products')->whereNotIn('id', $ids)
            ->where(fn ($q) => $q->where('sku', $b)->orWhere('sku', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $b).'-%'))
            ->exists();
    }

    protected function ocupado(string $sku, array $ids): bool
    {
        return DB::table('products')->where('sku', $sku)->whereNotIn('id', $ids)->exists();
    }

    /**
     * Nombres por la plantilla de la familia: «Camiseta Piel Durazno Verde Cali Mujer» y la variante + talla («… Mujer M»).
     *
     * @return array<int,string> product_id => nombre (solo los que tienen color)
     */
    public function nombres(array $plan): array
    {
        $plantilla = $plan['familia']['nombre'] ?? null;

        if (! $plantilla || ! $plan['padre']) {
            return [];
        }

        $nombre = fn (int $color) => trim((string) preg_replace('/\s+/u', ' ', str_replace('{color}', (string) $this->texto($color), $plantilla)));
        $salida = [];

        if ($plan['padre']['color_id']) {
            $salida[$plan['padre']['id']] = $nombre($plan['padre']['color_id']);
        }

        foreach ($plan['variantes'] as $v) {
            if ($v['color_id']) {
                $salida[$v['id']] = trim($nombre($v['color_id']).($v['talla_id'] ? ' '.$this->tallaNombre($v['talla_id']) : ''));
            }
        }

        return $salida;
    }

    // -----------------------------------------------------------------------------------------------------------------

    public function atributo(string $codigo): ?int
    {
        if (! array_key_exists($codigo, $this->atributos)) {
            $id = DB::table('attributes')->where('code', $codigo)->value('id');
            $this->atributos[$codigo] = $id === null ? null : (int) $id;
        }

        return $this->atributos[$codigo];
    }

    /** Texto de una opción (etiqueta del idioma por defecto o admin_name). */
    public function texto(?int $opcionId): ?string
    {
        if (! $opcionId) {
            return null;
        }

        if (! array_key_exists($opcionId, $this->textos)) {
            $locale = core()->getDefaultLocaleCodeFromDefaultChannel();
            $etiqueta = DB::table('attribute_option_translations')->where('attribute_option_id', $opcionId)->where('locale', $locale)->value('label');
            $this->textos[$opcionId] = trim((string) ($etiqueta ?: DB::table('attribute_options')->where('id', $opcionId)->value('admin_name'))) ?: null;
        }

        return $this->textos[$opcionId];
    }

    public function olvidarTextos(): void
    {
        $this->textos = [];
    }
}
