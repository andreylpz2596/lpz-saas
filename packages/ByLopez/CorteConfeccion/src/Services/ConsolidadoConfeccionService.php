<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccionRetorno;
use ByLopez\CorteConfeccion\Enums\PrioridadOrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenConfeccionDetalle;
use ByLopez\CorteConfeccion\Models\PrioridadCambio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Consolidado general de confeccion (solo lectura): lo que cada taller aun debe por variante real, con sus ordenes de
 * origen. Reutiliza las reglas del modulo, expresadas en SQL una sola vez por detalle:
 *   - Responsabilidad vigente = entregado - reasignado a otro taller (OrdenConfeccionDetalle::SQL_VIGENTE).
 *   - Por confeccionar       = vigente - en transito - reportado (pendientePorReportar): aun NO reportado como terminado.
 *   - Pendiente de recibir    = lo reportado en entregas que bodega todavia no recibe. Nunca «asignado - buenas
 *     recibidas»: eso haria reaparecer defectuosas o faltantes ya resueltos.
 *   - En transito             = llego por reasignacion y aun no se confirma; se muestra aparte y no es «por confeccionar».
 * Los reportes se suman en una subconsulta por detalle ANTES de unir, para no multiplicar cantidades.
 * Agrupa por taller + product_id (variante real) + tela del corte: nunca solo por nombres.
 */
class ConsolidadoConfeccionService
{
    public const POR_PAGINA = 20;

    public function __construct(
        protected VarianteConsultaService $varianteConsultaService,
        protected SugerenciaStockService $sugerenciaStockService,
    ) {}

    /**
     * @param  array<int, int>|null  $alcance  talleres visibles (null = todos)
     * @param  array{q?: ?string, taller?: ?int, referencia?: ?int, color?: ?string, tallas?: array<int, string>, prioridad?: ?string, sugerencia?: ?string, producto?: ?int}  $filtros
     * @return array{filas: Collection, grupos: Collection, totales: array<string, int>, opciones: array<string, mixed>, calculada_en: ?Carbon}
     */
    public function consultar(?array $alcance, array $filtros): array
    {
        $filas = $this->filas($alcance);

        // Sugerencia por stock: una sola consulta por lote de variantes (global por variante; el pendiente de recibir
        // se limita al alcance del usuario).
        $sugerencias = $this->sugerenciaStockService->paraProductos($filas->pluck('product_id'), $alcance);
        $filas = $filas->map(fn (array $fila) => $fila + ['sugerencia' => $sugerencias->get($fila['product_id'])]);

        $opciones = [
            'talleres' => $filas->pluck('taller', 'taller_id')->sort()->all(),
            'referencias' => $filas->filter(fn ($f) => $f['referencia_id'])->pluck('referencia', 'referencia_id')->sort()->all(),
            'colores' => $filas->pluck('color')->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'tallas' => $filas->pluck('talla')->filter(fn ($t) => $t !== '-')->unique()->sort(SORT_NATURAL)->values()->all(),
        ];

        $filtradas = $filas->filter(fn (array $fila) => $this->cumple($fila, $filtros))->values();

        $grupos = $filtradas
            ->groupBy(fn (array $fila) => $fila['taller_id'].'|'.$fila['product_id'].'|'.($fila['tela_id'] ?? 0))
            ->map(fn (Collection $ordenes, string $clave) => $this->grupo($clave, $ordenes))
            // La prioridad asignada manda; entre iguales, la sugerencia por stock, luego la mas antigua y el nombre.
            // Una sola clave por grupo (calculada una vez) en vez de comparar cuatro campos en cada comparacion.
            ->sortBy(fn (array $g) => sprintf('%d-%d-%06d-%s', $g['peso'], $g['peso_sugerencia'], 999999 - min($g['antiguedad'], 999999), mb_strtolower((string) $g['nombre'])))
            ->values();

        return [
            'filas' => $filtradas,
            'grupos' => $grupos,
            'totales' => $this->totales($filtradas, $grupos),
            'opciones' => $opciones,
            'calculada_en' => $sugerencias->first()['calculada_en'] ?? null,
        ];
    }

    /** Historial de cambios de prioridad de los detalles indicados (mas reciente primero). */
    public function historial(iterable $detalleIds): Collection
    {
        $ids = collect($detalleIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return PrioridadCambio::query()
            ->with(['operacion.usuario:id,name'])
            ->whereIn('orden_confeccion_detalle_id', $ids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('orden_confeccion_detalle_id');
    }

    /**
     * Estado ACTUAL de un detalle, para explicar una alerta historica sin presentar pendientes antiguos como actuales.
     *
     * @return array<string, mixed>
     */
    public function estadoActual(OrdenConfeccionDetalle $detalle): array
    {
        $detalle->loadMissing(['ordenConfeccion', 'retornosDetalles.retorno']);

        $pendienteRecibir = (int) $detalle->retornosDetalles
            ->filter(fn ($rd) => $rd->retorno?->estado === EstadoOrdenConfeccionRetorno::PendienteBodega->value)
            ->sum('cantidad_reportada');

        return [
            'orden_estado' => $detalle->ordenConfeccion?->estado,
            'por_confeccionar' => $detalle->pendientePorReportar(),
            'prioridades' => $detalle->prioridadesPorConfeccionar(),
            'reportada' => $detalle->cantidadReportada(),
            'pendiente_recibir' => $pendienteRecibir,
            'cedida' => (int) $detalle->cantidad_reasignada_salida,
            'en_transito' => (int) $detalle->cantidad_en_transito,
        ];
    }

    /** Una fila por detalle con saldo (por confeccionar, en transito o pendiente de recibir), de ordenes no anuladas. */
    protected function filas(?array $alcance): Collection
    {
        if ($alcance === []) {
            return collect();
        }

        $pendiente = EstadoOrdenConfeccionRetorno::PendienteBodega->value;

        $reportes = DB::table('bylopez_cc_ordenes_confeccion_retorno_detalles as rd')
            ->join('bylopez_cc_ordenes_confeccion_retornos as r', 'r.id', '=', 'rd.retorno_id')
            ->groupBy('rd.orden_confeccion_detalle_id')
            ->selectRaw('rd.orden_confeccion_detalle_id, SUM(rd.cantidad_reportada) as reportada')
            ->selectRaw('SUM(CASE WHEN r.estado = ? THEN rd.cantidad_reportada ELSE 0 END) as pendiente_recibir', [$pendiente])
            ->selectRaw('MIN(CASE WHEN r.estado = ? THEN r.fecha_reporte_taller END) as reporte_pendiente_desde', [$pendiente]);

        $vigente = '(d.cantidad_entregada - d.cantidad_reasignada_salida)';
        $porConfeccionar = "GREATEST($vigente - d.cantidad_en_transito - COALESCE(rep.reportada, 0), 0)";

        $consulta = DB::table('bylopez_cc_ordenes_confeccion_detalles as d')
            ->join('bylopez_cc_ordenes_confeccion as o', 'o.id', '=', 'd.orden_confeccion_id')
            ->join('bylopez_cc_talleres as t', 't.id', '=', 'o.taller_id')
            ->join('bylopez_cc_ordenes_corte as oc', 'oc.id', '=', 'o.orden_corte_id')
            ->leftJoin('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoinSub($reportes, 'rep', 'rep.orden_confeccion_detalle_id', '=', 'd.id')
            ->where('o.estado', '!=', EstadoOrdenConfeccion::Anulada->value)
            ->whereRaw("($porConfeccionar > 0 OR COALESCE(rep.pendiente_recibir, 0) > 0 OR LEAST(d.cantidad_en_transito, $vigente) > 0)")
            ->select([
                'd.id as detalle_id', 'd.product_id', 'd.producto_sku', 'd.producto_nombre', 'd.talla',
                'd.cantidad_urgente', 'd.cantidad_alta',
                'o.id as orden_id', 'o.codigo as orden_codigo', 'o.estado as orden_estado', 'o.fecha_entrega', 'o.created_at as orden_creada',
                'o.taller_id', 't.nombre as taller', 'oc.id as corte_id', 'oc.codigo as corte_codigo',
            ])
            ->selectRaw('COALESCE(d.attribute_family_id, p.attribute_family_id) as referencia_id')
            ->selectRaw("$porConfeccionar as por_confeccionar")
            ->selectRaw("LEAST(d.cantidad_en_transito, $vigente) as en_transito")
            ->selectRaw('COALESCE(rep.pendiente_recibir, 0) as pendiente_recibir')
            ->selectRaw('rep.reporte_pendiente_desde')
            ->orderBy('o.id')
            ->orderBy('d.id');

        if ($alcance !== null) {
            $consulta->whereIn('o.taller_id', $alcance);
        }

        $crudas = $consulta->get();

        if ($crudas->isEmpty()) {
            return collect();
        }

        $variantes = $this->varianteConsultaService->porProductIds($crudas->pluck('product_id'));
        $hex = $this->varianteConsultaService->hexPorColor();
        $referencias = DB::table('attribute_families')->whereIn('id', $crudas->pluck('referencia_id')->filter()->unique())->pluck('name', 'id');
        $telas = $this->telasPorCorte($crudas->pluck('corte_id')->unique());
        $hoy = Carbon::today();

        // Fechas memorizadas por valor: con miles de detalles, interpretar la misma fecha una y otra vez dominaba el tiempo.
        $fechas = [];
        $fecha = function (?string $valor) use (&$fechas, $hoy) {
            return $fechas[(string) $valor] ??= (function () use ($valor, $hoy) {
                $dia = Carbon::parse($valor)->startOfDay();

                return [$dia, (int) $dia->diffInDays($hoy)];
            })();
        };

        return $crudas->map(function ($c) use ($variantes, $hex, $referencias, $telas, $fecha) {
            $variante = $variantes->get((int) $c->product_id, []);
            $color = $variante['color'] ?? null;
            $porConfeccionar = (int) $c->por_confeccionar;
            [$desde, $antiguedad] = $fecha($c->fecha_entrega ?? $c->orden_creada);
            $tela = $telas->get((int) $c->corte_id);

            return [
                'detalle_id' => (int) $c->detalle_id,
                'product_id' => (int) $c->product_id,
                'sku' => $variante['sku'] ?? $c->producto_sku,
                'nombre' => $variante['nombre'] ?? $c->producto_nombre ?? $c->producto_sku,
                'activo' => $variante['activo'] ?? null,
                'color' => $color,
                'color_hex' => $color ? ($hex[mb_strtolower(trim($color))] ?? null) : null,
                'talla' => $c->talla ?: ($variante['talla'] ?? null) ?: '-',
                'referencia_id' => $c->referencia_id ? (int) $c->referencia_id : null,
                'referencia' => $c->referencia_id ? $referencias->get($c->referencia_id) : null,
                'tela_id' => $tela['id'] ?? null,
                'tela' => $tela['nombre'] ?? null,
                'taller_id' => (int) $c->taller_id,
                'taller' => $c->taller,
                'orden_id' => (int) $c->orden_id,
                'orden_codigo' => $c->orden_codigo,
                'orden_estado' => $c->orden_estado,
                'corte_id' => (int) $c->corte_id,
                'corte_codigo' => $c->corte_codigo,
                'por_confeccionar' => $porConfeccionar,
                'prioridades' => PrioridadOrdenConfeccion::repartir($porConfeccionar, (int) $c->cantidad_urgente, (int) $c->cantidad_alta),
                'en_transito' => (int) $c->en_transito,
                'pendiente_recibir' => (int) $c->pendiente_recibir,
                'desde' => $desde,
                'antiguedad' => $antiguedad,
                'reporte_pendiente_desde' => $c->reporte_pendiente_desde ? $fecha($c->reporte_pendiente_desde)[0] : null,
            ];
        });
    }

    /** Tela de cada corte (solo si sus rollos tienen un unico tipo de tela, igual que OrdenTarifaService). */
    protected function telasPorCorte(Collection $corteIds): Collection
    {
        $tipos = DB::table('bylopez_cc_orden_corte_rollos as ocr')
            ->join('bylopez_cc_rollos as r', 'r.id', '=', 'ocr.rollo_id')
            ->leftJoin('attribute_options as ao', 'ao.id', '=', 'r.tipo_tela_id')
            ->whereIn('ocr.orden_corte_id', $corteIds)
            ->whereNotNull('r.tipo_tela_id')
            ->distinct()
            ->get(['ocr.orden_corte_id', 'r.tipo_tela_id', 'ao.admin_name'])
            ->groupBy('orden_corte_id');

        return $tipos
            ->filter(fn (Collection $t) => $t->count() === 1)
            ->map(fn (Collection $t) => ['id' => (int) $t->first()->tipo_tela_id, 'nombre' => $t->first()->admin_name]);
    }

    protected function cumple(array $fila, array $filtros): bool
    {
        if (! empty($filtros['taller']) && $fila['taller_id'] !== (int) $filtros['taller']) {
            return false;
        }

        if (! empty($filtros['producto']) && $fila['product_id'] !== (int) $filtros['producto']) {
            return false;
        }

        if (! empty($filtros['referencia']) && $fila['referencia_id'] !== (int) $filtros['referencia']) {
            return false;
        }

        if (! empty($filtros['color']) && mb_strtolower((string) $fila['color']) !== mb_strtolower($filtros['color'])) {
            return false;
        }

        $tallas = array_values(array_filter((array) ($filtros['tallas'] ?? []), fn ($t) => $t !== '' && $t !== null));

        if ($tallas !== [] && ! in_array(mb_strtolower((string) $fila['talla']), array_map('mb_strtolower', $tallas), true)) {
            return false;
        }

        $p = $fila['prioridades'];
        $okPrioridad = match ($filtros['prioridad'] ?? null) {
            'urgente' => $p['urgente'] > 0,
            'alta' => $p['alta'] > 0,
            'prioritaria' => $p['urgente'] + $p['alta'] > 0,
            'normal' => $p['normal'] > 0,
            default => true,
        };

        if (! $okPrioridad) {
            return false;
        }

        if (! empty($filtros['sugerencia']) && ($fila['sugerencia']['nivel'] ?? SugerenciaStockService::SIN_INFORMACION) !== $filtros['sugerencia']) {
            return false;
        }

        $q = trim((string) ($filtros['q'] ?? ''));

        if ($q === '') {
            return true;
        }

        $texto = $this->plano(implode(' ', [
            $fila['nombre'], $fila['sku'], $fila['color'], 'talla '.$fila['talla'], $fila['referencia'], $fila['tela'],
            $fila['taller'], $fila['orden_codigo'], $fila['corte_codigo'],
        ]));

        // Todas las palabras deben aparecer («camiseta negra 14»), en cualquier orden.
        foreach (preg_split('/\s+/', $this->plano($q)) as $palabra) {
            if ($palabra !== '' && ! str_contains($texto, $palabra)) {
                return false;
            }
        }

        return true;
    }

    protected function plano(string $texto): string
    {
        return mb_strtolower(Str::ascii($texto));
    }

    protected function grupo(string $clave, Collection $ordenes): array
    {
        $primera = $ordenes->first();
        $prioridades = ['urgente' => 0, 'alta' => 0, 'normal' => 0];
        $sumas = ['por_confeccionar' => 0, 'pendiente_recibir' => 0, 'en_transito' => 0];
        $antiguedad = 0;

        // Un solo recorrido (antes ~10 operaciones de coleccion por grupo; con miles de variantes pesaba).
        foreach ($ordenes as $o) {
            foreach ($prioridades as $nivel => $_) {
                $prioridades[$nivel] += $o['prioridades'][$nivel];
            }

            foreach ($sumas as $campo => $_) {
                $sumas[$campo] += $o[$campo];
            }

            $antiguedad = max($antiguedad, $o['antiguedad']);
        }

        return [
            'clave' => $clave,
            'taller_id' => $primera['taller_id'],
            'taller' => $primera['taller'],
            'product_id' => $primera['product_id'],
            'nombre' => $primera['nombre'],
            'sku' => $primera['sku'],
            'activo' => $primera['activo'],
            'color' => $primera['color'],
            'color_hex' => $primera['color_hex'],
            'talla' => $primera['talla'],
            'referencia' => $primera['referencia'],
            'tela' => $primera['tela'],
            'por_confeccionar' => $sumas['por_confeccionar'],
            'pendiente_recibir' => $sumas['pendiente_recibir'],
            'en_transito' => $sumas['en_transito'],
            'prioridades' => $prioridades,
            'sugerencia' => $primera['sugerencia'],
            'peso' => $prioridades['urgente'] > 0 ? 0 : ($prioridades['alta'] > 0 ? 1 : 2),
            'peso_sugerencia' => SugerenciaStockService::PESO[$primera['sugerencia']['nivel'] ?? SugerenciaStockService::SIN_INFORMACION],
            'antiguedad' => $antiguedad,
            'ordenes' => $ordenes->count() > 1 ? $ordenes->sortByDesc('antiguedad')->values() : $ordenes->values(),
        ];
    }

    /** Indicadores sobre TODOS los resultados filtrados (no solo la pagina visible). */
    protected function totales(Collection $filas, Collection $grupos): array
    {
        return [
            'por_confeccionar' => (int) $filas->sum('por_confeccionar'),
            'pendiente_recibir' => (int) $filas->sum('pendiente_recibir'),
            'urgente' => (int) $filas->sum(fn ($f) => $f['prioridades']['urgente']),
            'alta' => (int) $filas->sum(fn ($f) => $f['prioridades']['alta']),
            'en_transito' => (int) $filas->sum('en_transito'),
            'variantes' => $grupos->count(),
            'ordenes' => $filas->pluck('orden_id')->unique()->count(),
        ];
    }
}
