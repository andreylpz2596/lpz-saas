<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use Illuminate\Support\Facades\DB;

/**
 * COM-01 — diagnostico de SOLO LECTURA de los snapshots de costo. No escribe ni rellena nada (sin backfill,
 * COM-D-13).
 *
 * «Pedidos sin snapshot desde la activacion» = pedidos creados a partir del primer snapshot que no tienen
 * ninguno: senal de que el listener fallo (los anteriores son historicos y se esperan sin snapshot).
 */
class DiagnosticoCostosVentaService
{
    /**
     * @return array{
     *   pedidos_totales: int, pedidos_con_snapshot: int, lineas_con_snapshot: int, lineas_sin_costo: int,
     *   lineas_con_autoridad: int, por_fuente: array<string, int>, por_referencia_sin_costo: array<string, int>,
     *   activacion: ?string, pedidos_sin_snapshot_desde_activacion: list<int>
     * }
     */
    public function resumen(): array
    {
        $snapshots = DB::table('bylopez_va_costos_venta');
        $activacion = (clone $snapshots)->min('capturado_en');

        $sinSnapshot = $activacion === null ? [] : DB::table('orders as o')
            ->where('o.created_at', '>=', $activacion)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bylopez_va_costos_venta as c')->whereColumn('c.order_id', 'o.id'))
            ->orderBy('o.id')
            ->limit(50)
            ->pluck('o.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'pedidos_totales' => DB::table('orders')->count(),
            'pedidos_con_snapshot' => (clone $snapshots)->distinct()->count('order_id'),
            'lineas_con_snapshot' => (clone $snapshots)->count(),
            'lineas_sin_costo' => (clone $snapshots)->whereNull('costo_unitario')->count(),
            'lineas_con_autoridad' => (clone $snapshots)->where('autoridad_comision', true)->count(),
            'por_fuente' => (clone $snapshots)->select('fuente', DB::raw('COUNT(*) as total'))
                ->groupBy('fuente')->pluck('total', 'fuente')->map(fn ($n) => (int) $n)->all(),
            'por_referencia_sin_costo' => (clone $snapshots)->whereNull('costo_unitario')
                ->select('fuente_referencia', DB::raw('COUNT(*) as total'))
                ->groupBy('fuente_referencia')->pluck('total', 'fuente_referencia')->map(fn ($n) => (int) $n)->all(),
            'activacion' => $activacion === null ? null : (string) $activacion,
            'pedidos_sin_snapshot_desde_activacion' => $sinSnapshot,
        ];
    }
}
