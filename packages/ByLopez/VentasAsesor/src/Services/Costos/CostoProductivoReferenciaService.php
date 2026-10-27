<?php

namespace ByLopez\VentasAsesor\Services\Costos;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * COSTO PRODUCTIVO DE REFERENCIA por variante (D-COST-07), leido de CorteConfeccion en SOLO LECTURA. No escribe en
 * CorteConfeccion, no importa sus clases (lee sus tablas persistidas) y no es fuente de comision.
 *
 * Que se puede calcular HOY (auditoria 2026-10-08, Doc. 19 §3): SOLO EL COSTO DE TELA. CorteConfeccion no registra
 * pago al cortador, pago al satelite/taller, transporte productivo ni otros costos directos. Por eso el resultado es
 * siempre PARCIAL (`completo = false`) y es una COTA INFERIOR del costo real.
 *
 * Costo de tela por pieza de una orden de corte:
 *
 *   valor_kilo_orden = Σ(peso_inicial_usado × valor_kilo) / Σ peso_inicial_usado      (rollos asignados a la orden)
 *   kilos_usados     = Σ peso_inicial_usado − peso_sobrante_total                     (incluye la merma: tela pagada)
 *   tela_por_pieza   = kilos_usados × valor_kilo_orden / total_piezas_cortadas
 *
 * Solo ordenes `recibido_bodega` o `cerrado` (valores de EstadoOrdenCorte de CorteConfeccion), con piezas > 0 y con
 * TODOS sus rollos con `valor_kilo` > 0 (una orden con un rollo sin precio se excluye: no se inventa el valor).
 * Una orden con varias lineas reparte la tela por igual entre sus piezas (CorteConfeccion solo guarda el peso a
 * nivel de orden: `peso_promedio_pieza`); no distingue tallas.
 *
 * Por variante (`ordenes_corte_detalles.product_id` = simple de Bagisto): promedio ponderado por
 * `cantidad_real_cortada` de las ordenes validas.
 */
class CostoProductivoReferenciaService
{
    /** Valores de ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte con recepcion en bodega (pesos finales). */
    public const ESTADOS_ORDEN_CON_PESOS_FINALES = ['recibido_bodega', 'cerrado'];

    /** Componentes que CorteConfeccion todavia no registra (Doc. 19 §3). */
    public const COMPONENTES_FALTANTES = ['corte', 'confeccion', 'transporte_productivo', 'otros_directos'];

    /**
     * @param  list<int>|null  $productIds  null = todas las variantes con datos
     * @return array<int, array{costo: float, piezas: int, ordenes: int, componentes: list<string>, faltantes: list<string>, completo: bool}>
     */
    public function porVariante(?array $productIds = null): array
    {
        if (! $this->disponible()) {
            return [];
        }

        $telaPorPieza = $this->telaPorPiezaDeOrdenes();

        if ($telaPorPieza === []) {
            return [];
        }

        $detalles = DB::table('bylopez_cc_ordenes_corte_detalles')
            ->whereIn('orden_corte_id', array_keys($telaPorPieza))
            ->whereNotNull('product_id')
            ->where('cantidad_real_cortada', '>', 0)
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))
            ->get(['orden_corte_id', 'product_id', 'cantidad_real_cortada']);

        $acumulado = [];

        foreach ($detalles as $d) {
            $id = (int) $d->product_id;
            $piezas = (int) $d->cantidad_real_cortada;
            $acumulado[$id]['valor'] = ($acumulado[$id]['valor'] ?? 0.0) + $telaPorPieza[(int) $d->orden_corte_id] * $piezas;
            $acumulado[$id]['piezas'] = ($acumulado[$id]['piezas'] ?? 0) + $piezas;
            $acumulado[$id]['ordenes'][(int) $d->orden_corte_id] = true;
        }

        $resultado = [];

        foreach ($acumulado as $id => $a) {
            $resultado[$id] = [
                'costo' => round($a['valor'] / $a['piezas'], 2),
                'piezas' => $a['piezas'],
                'ordenes' => count($a['ordenes']),
                'componentes' => ['tela'],
                'faltantes' => self::COMPONENTES_FALTANTES,
                'completo' => false,
            ];
        }

        return $resultado;
    }

    public function disponible(): bool
    {
        return Schema::hasTable('bylopez_cc_ordenes_corte')
            && Schema::hasTable('bylopez_cc_ordenes_corte_detalles')
            && Schema::hasTable('bylopez_cc_orden_corte_rollos')
            && Schema::hasTable('bylopez_cc_rollos');
    }

    /** @return array<int, float> orden_corte_id → costo de tela por pieza */
    protected function telaPorPiezaDeOrdenes(): array
    {
        $ordenes = DB::table('bylopez_cc_ordenes_corte')
            ->whereIn('estado', self::ESTADOS_ORDEN_CON_PESOS_FINALES)
            ->where('total_piezas_cortadas', '>', 0)
            ->get(['id', 'peso_sobrante_total', 'total_piezas_cortadas'])
            ->keyBy('id');

        if ($ordenes->isEmpty()) {
            return [];
        }

        $rollos = DB::table('bylopez_cc_orden_corte_rollos as p')
            ->join('bylopez_cc_rollos as r', 'r.id', '=', 'p.rollo_id')
            ->whereIn('p.orden_corte_id', $ordenes->keys())
            ->selectRaw('p.orden_corte_id, SUM(p.peso_inicial_usado) as kilos, SUM(p.peso_inicial_usado * r.valor_kilo) as valor, SUM(CASE WHEN r.valor_kilo IS NULL OR r.valor_kilo <= 0 THEN 1 ELSE 0 END) as sin_precio')
            ->groupBy('p.orden_corte_id')
            ->get()
            ->keyBy('orden_corte_id');

        $resultado = [];

        foreach ($ordenes as $id => $orden) {
            $r = $rollos->get($id);

            if ($r === null || (int) $r->sin_precio > 0 || (float) $r->kilos <= 0) {
                continue;
            }

            $kilosUsados = (float) $r->kilos - (float) $orden->peso_sobrante_total;

            if ($kilosUsados <= 0) {
                continue;
            }

            $valorKilo = (float) $r->valor / (float) $r->kilos;
            $resultado[(int) $id] = $kilosUsados * $valorKilo / (int) $orden->total_piezas_cortadas;
        }

        return $resultado;
    }
}
