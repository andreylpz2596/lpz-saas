<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use Brick\Math\BigDecimal;
use ByLopez\VentasAsesor\Models\ComisionLinea;
use ByLopez\VentasAsesor\Models\ComisionLiquidacion;
use ByLopez\VentasAsesor\Support\Comisiones\Dinero;
use Illuminate\Support\Facades\DB;

/**
 * «Tu avance del mes»: avance de UNA asesora frente al umbral de su acuerdo. Lee lo que ya decidio UmbralMensualService (unidades
 * COMPUTABLES = vendidas − canceladas − no entregadas; el umbral sale del acuerdo, no del codigo) y no calcula reglas propias.
 *
 * Tres cantidades que NO se mezclan:
 *  - computables: las que cuentan para el umbral (libro de unidades);
 *  - despachadas: unidades en envios del mes (un envio parcial se cuenta una vez, nunca otra al completarse);
 *  - entregadas: unidades con validacion de entrega confirmada.
 * Superar el umbral NO es «comision disponible para pagar»: lo segundo depende de entrega validada, pago confirmado y dinero
 * recibido (PanelComisionesService / cuenta). Este servicio nunca promete un pago.
 *
 * La estimacion es del servidor: null cuando algun importe es desconocido (sin costo con autoridad o sin porcentaje), nunca $0.
 */
class AvanceAsesorService
{
    public function __construct(
        protected UmbralMensualService $umbral,
        protected LiquidacionesComisionService $liquidaciones,
    ) {}

    /**
     * @return array{asesor_id: int, mes: string, estado_umbral: string, umbral: ?int, computables: int, faltante: ?int, comisionables: int, avance: ?int, situacion: string, estimada: ?string, estimada_parcial: ?string, sin_calcular: int, despachadas: int, entregadas: int}
     */
    public function deAsesor(int $asesorId, string $mes): array
    {
        $d = $this->umbral->distribucion($asesorId, $mes);
        $lineas = ComisionLinea::query()->where('asesor_admin_id', $asesorId)->where('mes_comercial', $mes)
            ->where('estado', '!=', ComisionLinea::ANULADA)->get(['id', 'order_id', 'order_item_id']);

        $estimada = BigDecimal::zero();
        $sinCalcular = 0;

        foreach ($d['lineas'] as $dl) {
            if (($dl['comisionables'] ?? 0) > 0) {
                $dl['monto_estimado'] === null ? $sinCalcular++ : $estimada = $estimada->plus(Dinero::de($dl['monto_estimado']));
            }
        }

        [$despachadas, $entregadas] = $this->despachosYEntregas($lineas->pluck('order_id')->unique()->all(), $lineas->pluck('order_item_id')->all());

        return [
            'asesor_id' => $asesorId,
            'mes' => $mes,
            'estado_umbral' => $d['estado_umbral'],
            'umbral' => $d['umbral'],
            'computables' => $d['acumulado'],
            'faltante' => $d['faltante'],
            'comisionables' => $d['comisionables'],
            'avance' => $d['umbral'] ? min(100, (int) floor($d['acumulado'] * 100 / max(1, $d['umbral']))) : null,
            'situacion' => $this->situacion($d['umbral'], $d['acumulado']),
            'estimada' => $sinCalcular > 0 ? null : Dinero::texto($estimada),
            'estimada_parcial' => $sinCalcular > 0 ? Dinero::texto($estimada) : null,
            'sin_calcular' => $sinCalcular,
            'despachadas' => $despachadas,
            'entregadas' => $entregadas,
        ];
    }

    /**
     * Resumen comparable de varias asesoras (Asesores, administracion): MISMA fuente y MISMAS definiciones que la tarjeta de cada una.
     *
     * @param  list<int>  $adminIds
     * @return list<array<string, mixed>>
     */
    public function comparativo(array $adminIds, string $mes): array
    {
        $nombres = DB::table('bylopez_va_asesores')->whereIn('admin_id', $adminIds ?: [0])->pluck('nombre', 'admin_id');
        $filas = [];

        foreach ($adminIds as $id) {
            $filas[] = $this->deAsesor($id, $mes) + ['asesora' => (string) ($nombres[$id] ?? ('#'.$id)), 'saldo_aprobado' => $this->saldoAprobado($id)];
        }

        return $filas;
    }

    /** Saldo de liquidaciones APROBADAS (lo que ByLopez aun debe pagar a la asesora), de todos los meses. */
    public function saldoAprobado(int $asesorId): string
    {
        $saldo = BigDecimal::zero();

        foreach (ComisionLiquidacion::query()->with('abonos')->where('asesor_admin_id', $asesorId)->where('estado', ComisionLiquidacion::APROBADA)->get() as $liq) {
            $saldo = $saldo->plus($this->liquidaciones->saldo($liq));
        }

        return Dinero::texto($saldo);
    }

    /** antes | exacto | despues | sin_umbral. */
    public function situacion(?int $umbral, int $computables): string
    {
        return match (true) {
            $umbral === null => 'sin_umbral',
            $computables < $umbral => 'antes',
            $computables === $umbral => 'exacto',
            default => 'despues',
        };
    }

    /**
     * Unidades despachadas y entregadas de los pedidos del mes. Bagisto guarda el envio en el item PADRE, y cada envio se suma una
     * sola vez: completar un despacho parcial no vuelve a contar lo ya enviado.
     *
     * @param  list<int>  $orderIds
     * @param  list<int>  $itemIds
     * @return array{0: int, 1: int}
     */
    protected function despachosYEntregas(array $orderIds, array $itemIds): array
    {
        if ($orderIds === []) {
            return [0, 0];
        }

        $despachadas = (int) DB::table('shipment_items as si')->join('shipments as s', 's.id', '=', 'si.shipment_id')
            ->whereIn('s.order_id', $orderIds)->whereIn('si.order_item_id', $itemIds)->sum('si.qty');
        $entregadas = (int) DB::table('bylopez_lg_validaciones_entrega')->whereIn('order_id', $orderIds)->where('estado', 'confirmado')->sum('entregadas');

        return [$despachadas, $entregadas];
    }
}
