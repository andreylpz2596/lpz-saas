<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion;
use ByLopez\CorteConfeccion\Models\StockMinimo;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Minimos de stock por variante (base de la sugerencia «Alta: bajo el mínimo»). Configuracion explicita y sencilla:
 * vacio = sin minimo (se borra la fila), 0 = minimo cero. Nunca se asignan minimos masivos ni por defecto.
 * Cada cambio queda en `bylopez_cc_stock_minimo_cambios`.
 */
class StockMinimoService
{
    public const POR_PAGINA = 25;

    public function __construct(
        protected VarianteConsultaService $varianteConsultaService,
        protected SugerenciaStockService $sugerenciaStockService,
    ) {}

    /**
     * Variantes (productos simples) para configurar. `ambito` = 'confeccion' (por defecto: variantes con ordenes de
     * confeccion abiertas) o 'todas'. `q`: todas las palabras en SKU o nombre.
     */
    public function buscar(string $q, string $ambito, int $pagina): LengthAwarePaginator
    {
        $locale = app()->getLocale();
        $consulta = DB::table('products as p')
            ->leftJoin('product_flat as pf', fn ($j) => $j->on('pf.product_id', '=', 'p.id')->where('pf.locale', $locale))
            ->leftJoin('product_flat as padre', fn ($j) => $j->on('padre.product_id', '=', 'p.parent_id')->where('padre.locale', $locale))
            ->where('p.type', 'simple')
            ->select('p.id', 'p.sku')
            ->orderByRaw('COALESCE(padre.name, pf.name)')
            ->orderBy('p.sku');

        if ($ambito !== 'todas') {
            $consulta->whereExists(fn ($s) => $s->from('bylopez_cc_ordenes_confeccion_detalles as d')
                ->join('bylopez_cc_ordenes_confeccion as o', 'o.id', '=', 'd.orden_confeccion_id')
                ->whereColumn('d.product_id', 'p.id')
                ->whereNotIn('o.estado', [EstadoOrdenConfeccion::Anulada->value, EstadoOrdenConfeccion::Cerrada->value]));
        }

        foreach (preg_split('/\s+/', trim($q)) as $palabra) {
            if ($palabra === '') {
                continue;
            }

            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $palabra).'%';
            $consulta->where(fn ($w) => $w->where('p.sku', 'like', $like)->orWhere('pf.name', 'like', $like)->orWhere('padre.name', 'like', $like));
        }

        // Uniones 1:1 (un product_flat por idioma) y whereExists: no hay filas repetidas por variante.
        $total = (clone $consulta)->reorder()->count('p.id');
        $ids = $consulta->forPage($pagina, self::POR_PAGINA)->pluck('p.id')->map(fn ($id) => (int) $id);

        $variantes = $this->varianteConsultaService->porProductIds($ids);
        $minimos = StockMinimo::query()->whereIn('product_id', $ids)->get()->keyBy('product_id');
        $sugerencias = $this->sugerenciaStockService->paraProductos($ids);

        $filas = $ids->map(fn (int $id) => [
            'product_id' => $id,
            'sku' => $variantes[$id]['sku'] ?? null,
            'nombre' => $variantes[$id]['nombre'] ?? null,
            'color' => $variantes[$id]['color'] ?? null,
            'talla' => $variantes[$id]['talla'] ?? null,
            'disponible' => $variantes[$id]['stock_actual'] ?? null,
            'minimo' => $minimos->get($id)?->minimo,
            'actualizado' => $minimos->get($id)?->updated_at,
            'sugerencia' => $sugerencias->get($id),
        ]);

        return new LengthAwarePaginator($filas, $total, self::POR_PAGINA, $pagina);
    }

    /**
     * Guarda solo los cambios. Cada entrada: product_id => ['nuevo' => ''|int, 'original' => ''|int]. `original` es lo
     * que mostraba la pantalla: si otra persona lo cambio entretanto, se rechaza todo para no pisar su cambio.
     *
     * @return int cambios aplicados
     */
    public function guardar(array $entradas, ?int $adminId): int
    {
        $cambios = [];

        foreach ($entradas as $productId => $entrada) {
            $productId = (int) $productId;
            $nuevo = $this->valor($entrada['nuevo'] ?? '');
            $original = $this->valor($entrada['original'] ?? '');

            if ($productId <= 0 || $nuevo === false || $original === false) {
                throw ValidationException::withMessages(["minimos.{$productId}" => trans('corteconfeccion::app.errors.stock-minimo-invalido')]);
            }

            if ($nuevo !== $original) {
                $cambios[$productId] = ['nuevo' => $nuevo, 'original' => $original];
            }
        }

        if ($cambios === []) {
            return 0;
        }

        $simples = DB::table('products')->whereIn('id', array_keys($cambios))->where('type', 'simple')->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach (array_keys($cambios) as $productId) {
            if (! in_array($productId, $simples, true)) {
                throw ValidationException::withMessages(["minimos.{$productId}" => trans('corteconfeccion::app.errors.stock-minimo-variante')]);
            }
        }

        return DB::transaction(function () use ($cambios, $adminId) {
            ksort($cambios);
            // Bloquea las variantes (filas `products`, siempre existen) para serializar ediciones simultaneas.
            DB::table('products')->whereIn('id', array_keys($cambios))->orderBy('id')->lockForUpdate()->pluck('id');

            $actuales = StockMinimo::query()->whereIn('product_id', array_keys($cambios))->pluck('minimo', 'product_id');

            foreach ($cambios as $productId => $cambio) {
                $actual = $actuales->has($productId) ? (int) $actuales->get($productId) : null;

                if ($actual !== $cambio['original']) {
                    throw ValidationException::withMessages(["minimos.{$productId}" => trans('corteconfeccion::app.errors.stock-minimo-cambio', [
                        'actual' => $actual ?? 'sin mínimo',
                    ])]);
                }

                if ($cambio['nuevo'] === null) {
                    StockMinimo::query()->where('product_id', $productId)->delete();
                } else {
                    StockMinimo::query()->updateOrCreate(['product_id' => $productId], ['minimo' => $cambio['nuevo'], 'actualizado_por' => $adminId]);
                }

                DB::table('bylopez_cc_stock_minimo_cambios')->insert([
                    'product_id' => $productId,
                    'anterior' => $actual,
                    'nuevo' => $cambio['nuevo'],
                    'admin_id' => $adminId,
                    'created_at' => now(),
                ]);
            }

            return count($cambios);
        });
    }

    /** '' = sin minimo (null); entero >= 0; cualquier otra cosa = false (invalido). */
    protected function valor(mixed $valor): int|null|false
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return null;
        }

        if (is_int($valor)) {
            return $valor >= 0 ? $valor : false;
        }

        return is_string($valor) && preg_match('/^\d{1,9}$/', trim($valor)) ? (int) trim($valor) : false;
    }
}
