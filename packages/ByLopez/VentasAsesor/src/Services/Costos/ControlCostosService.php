<?php

namespace ByLopez\VentasAsesor\Services\Costos;

use ByLopez\VentasAsesor\DataObjects\FilaControlCosto;
use ByLopez\VentasAsesor\Enums\EstadoControlCosto;
use ByLopez\VentasAsesor\Support\Costos\CostoComisionableCatalogo;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «Control de costos» (D-COST-04/07): compara, por variante vendible (producto `simple`), el COSTO COMISIONABLE de
 * Bagisto con el COSTO PRODUCTIVO DE REFERENCIA de CorteConfeccion y alerta sin cambiar nada. SOLO LECTURA.
 *
 * La familia (linea) es la `attribute_family` del configurable padre (o la propia si el simple no tiene padre): se usa
 * el dato estructurado de Bagisto, sin listas fijas de Hombre/Dama/Nino. Lecturas por lote, sin N+1.
 */
class ControlCostosService
{
    public const POR_PAGINA = 50;

    public const DIAS_RECIENTES = 30;

    public function __construct(
        protected CostoComisionableCatalogo $catalogo,
        protected CostoProductivoReferenciaService $productivo,
    ) {}

    /**
     * @param  array{q?: ?string, familia?: ?int, estado?: ?string}  $filtros
     * @return Collection<int, FilaControlCosto>
     */
    public function filas(array $filtros = [], ?array $soloIds = null): Collection
    {
        $base = $this->variantes($filtros, $soloIds);

        if ($base->isEmpty()) {
            return collect();
        }

        $ids = $base->pluck('id')->map(fn ($id) => (int) $id)->all();
        $costos = $this->catalogo->costosDe($ids);
        $productivos = $this->productivo->porVariante($ids);
        $cambios = DB::table('bylopez_va_costo_comisionable_cambios')
            ->whereIn('product_id', $ids)
            ->selectRaw('product_id, MAX(created_at) as ultimo')
            ->groupBy('product_id')
            ->pluck('ultimo', 'product_id');

        $filas = $base->map(function (object $v) use ($costos, $productivos, $cambios) {
            $id = (int) $v->id;
            $comisionable = $costos[$id] ?? null;
            $p = $productivos[$id] ?? null;
            $productivo = $p['costo'] ?? null;
            $diferencia = $comisionable !== null && $productivo !== null ? round($productivo - $comisionable, 2) : null;

            return new FilaControlCosto(
                productId: $id,
                sku: (string) $v->sku,
                nombre: (string) ($v->nombre ?? $v->sku),
                producto: $v->producto,
                familiaId: $v->familia_id === null ? null : (int) $v->familia_id,
                familia: $v->familia,
                costoComisionable: $comisionable,
                costoProductivo: $productivo,
                productivoCompleto: (bool) ($p['completo'] ?? false),
                componentesFaltantes: $p['faltantes'] ?? [],
                piezasProducidas: (int) ($p['piezas'] ?? 0),
                diferencia: $diferencia,
                diferenciaPorcentaje: $diferencia !== null ? round($diferencia / $comisionable * 100, 1) : null,
                estado: EstadoControlCosto::evaluar($comisionable, $productivo, (bool) ($p['completo'] ?? false)),
                ultimoCambio: $cambios[$id] ?? null,
            );
        });

        $estado = EstadoControlCosto::tryFrom((string) ($filtros['estado'] ?? ''));

        return ($estado === null ? $filas : $filas->filter(fn (FilaControlCosto $f) => $f->estado === $estado))->values();
    }

    /** @param  array{q?: ?string, familia?: ?int, estado?: ?string}  $filtros */
    public function listar(array $filtros, int $pagina): LengthAwarePaginator
    {
        $filas = $this->filas($filtros);
        $pagina = max(1, $pagina);

        return new LengthAwarePaginator(
            $filas->forPage($pagina, self::POR_PAGINA)->values(),
            $filas->count(),
            self::POR_PAGINA,
            $pagina,
            ['path' => route('admin.ventas_asesor.costos.index')],
        );
    }

    /** @return array{variantes: int, con_costo: int, sin_costo: int, revisar: int, con_produccion: int, cambios_recientes: int, lotes_recientes: int, produccion_disponible: bool} */
    public function indicadores(): array
    {
        $filas = $this->filas();
        $desde = now()->subDays(self::DIAS_RECIENTES);
        $recientes = DB::table('bylopez_va_costo_comisionable_cambios')->where('created_at', '>=', $desde);

        return [
            'variantes' => $filas->count(),
            'con_costo' => $filas->filter(fn (FilaControlCosto $f) => $f->costoComisionable !== null)->count(),
            'sin_costo' => $filas->filter(fn (FilaControlCosto $f) => $f->costoComisionable === null)->count(),
            'revisar' => $filas->filter(fn (FilaControlCosto $f) => $f->estado === EstadoControlCosto::REVISAR)->count(),
            'con_produccion' => $filas->filter(fn (FilaControlCosto $f) => $f->costoProductivo !== null)->count(),
            'cambios_recientes' => (clone $recientes)->count(),
            'lotes_recientes' => (clone $recientes)->distinct()->count('lote'),
            'produccion_disponible' => $this->productivo->disponible(),
        ];
    }

    /** @return array<int, string> id → nombre de las familias de atributos de Bagisto (lineas del catalogo) */
    public function familias(): array
    {
        return DB::table('attribute_families')->orderBy('name')->pluck('name', 'id')->map(fn ($n) => trim((string) $n))->all();
    }

    /**
     * Variantes vendibles (`simple`) con su producto padre y familia. Nombre por canal/idioma por defecto.
     *
     * @param  array{q?: ?string, familia?: ?int}  $filtros
     * @return Collection<int, object>
     */
    protected function variantes(array $filtros, ?array $soloIds): Collection
    {
        $canal = core()->getDefaultChannelCode();
        $idioma = core()->getDefaultLocaleCodeFromDefaultChannel();

        return DB::table('products as p')
            ->leftJoin('products as padre', 'padre.id', '=', 'p.parent_id')
            ->leftJoin('product_flat as pf', fn ($j) => $j->on('pf.product_id', '=', 'p.id')->where('pf.channel', $canal)->where('pf.locale', $idioma))
            ->leftJoin('product_flat as ppf', fn ($j) => $j->on('ppf.product_id', '=', 'padre.id')->where('ppf.channel', $canal)->where('ppf.locale', $idioma))
            ->leftJoin('attribute_families as af', 'af.id', '=', DB::raw('COALESCE(padre.attribute_family_id, p.attribute_family_id)'))
            ->where('p.type', 'simple')
            ->when($soloIds !== null, fn ($q) => $q->whereIn('p.id', $soloIds))
            ->when(! empty($filtros['familia']), fn ($q) => $q->whereRaw('COALESCE(padre.attribute_family_id, p.attribute_family_id) = ?', [(int) $filtros['familia']]))
            ->when(! empty($filtros['q']), function ($q) use ($filtros) {
                $texto = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $filtros['q']).'%';
                $q->where(fn ($w) => $w->where('p.sku', 'like', $texto)->orWhere('pf.name', 'like', $texto)->orWhere('ppf.name', 'like', $texto));
            })
            ->orderByRaw('COALESCE(ppf.name, pf.name, p.sku)')
            ->orderBy('p.sku')
            ->get([
                'p.id', 'p.sku', 'pf.name as nombre', 'ppf.name as producto',
                DB::raw('COALESCE(padre.attribute_family_id, p.attribute_family_id) as familia_id'), 'af.name as familia',
            ]);
    }
}
