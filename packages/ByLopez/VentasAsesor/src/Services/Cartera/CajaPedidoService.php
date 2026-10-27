<?php

namespace ByLopez\VentasAsesor\Services\Cartera;

use Illuminate\Support\Facades\DB;

/**
 * C6 — «¿DONDE ESTA EL DINERO?» de un pedido. Es la lectura complementaria a Cartera: Cartera responde ¿el cliente debe?; esto
 * responde ¿el dinero que el cliente ya pago al transportador esta en custodia o ya se concilio con ByLopez? Solo lectura; no escribe
 * ni altera el saldo del cliente.
 *
 *   cobrado                 = Σ recaudo (movimiento de origen `cobro`) = lo que el cliente pago al transportador
 *   conciliado              = efectivo + compensado atribuido por las liquidaciones
 *   efectivo / compensado   = por separado: la compensacion NUNCA se suma a caja
 *   en_custodia             = cobrado − conciliado (todavia en poder / responsabilidad del transportador)
 *   pendiente_transportador = la parte de en_custodia que ya paso por una liquidacion cerrada y quedo sin conciliar
 *                             («Brian queda debiendo»). NUNCA es deuda del cliente.
 *
 * Por LOTE: 3 consultas agrupadas sin importar cuantos pedidos se pidan (sin N+1). Los pedidos sin recaudo C6 (legado, pagos directos)
 * devuelven `aplica = false`; sus pagos de custodia sin guia se senalan como `historico_sin_vinculo` y nunca se reinterpretan.
 */
class CajaPedidoService
{
    /**
     * @param  list<int>  $orderIds
     * @return array<int, array<string, mixed>>
     */
    public function dePedidos(array $orderIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $orderIds)));

        if ($ids === []) {
            return [];
        }

        $salida = array_fill_keys($ids, $this->vacio());

        $movimientos = DB::table('bylopez_lg_cuenta_movimientos as m')
            ->leftJoin('bylopez_lg_guias as g', 'g.id', '=', 'm.guia_id')
            ->leftJoin('bylopez_lg_transportadoras as t', 't.id', '=', 'm.transportadora_id')
            ->whereIn('m.order_id', $ids)->where('m.concepto', 'recaudo')->where('m.origen_tipo', 'cobro')
            ->get(['m.id', 'm.order_id', 'm.guia_id', 'm.importe', 'm.estado', 'm.liquidacion_id', 'g.numero_guia', 't.nombre as transportadora']);

        if ($movimientos->isNotEmpty()) {
            $conciliado = DB::table('bylopez_lg_conciliaciones_movimiento as c')
                ->join('bylopez_lg_liquidaciones_transportadora as l', 'l.id', '=', 'c.liquidacion_id')
                ->whereIn('c.movimiento_id', $movimientos->pluck('id')->all())
                ->groupBy('c.movimiento_id')
                ->selectRaw('c.movimiento_id, SUM(c.efectivo) AS efectivo, SUM(c.compensado) AS compensado, MAX(l.consecutivo) AS consecutivo')
                ->get()->keyBy('movimiento_id');

            foreach ($movimientos as $m) {
                $c = $conciliado->get($m->id);
                $cobrado = round((float) $m->importe, 2);
                $efectivo = round((float) ($c->efectivo ?? 0), 2);
                $compensado = round((float) ($c->compensado ?? 0), 2);
                $falta = round($cobrado - $efectivo - $compensado, 2);
                $fila = &$salida[(int) $m->order_id];

                $fila['aplica'] = true;
                $fila['cobrado'] = round($fila['cobrado'] + $cobrado, 2);
                $fila['efectivo'] = round($fila['efectivo'] + $efectivo, 2);
                $fila['compensado'] = round($fila['compensado'] + $compensado, 2);
                $fila['conciliado'] = round($fila['efectivo'] + $fila['compensado'], 2);
                $fila['en_custodia'] = round($fila['cobrado'] - $fila['conciliado'], 2);
                $fila['pendiente_transportador'] = round($fila['pendiente_transportador'] + ($m->estado === 'liquidado' ? max(0.0, $falta) : 0.0), 2);
                $fila['guias'][] = [
                    'guia_id' => (int) $m->guia_id, 'guia' => $m->numero_guia, 'transportadora' => $m->transportadora,
                    'cobrado' => $cobrado, 'efectivo' => $efectivo, 'compensado' => $compensado, 'conciliado' => round($efectivo + $compensado, 2),
                    'en_custodia' => $falta, 'liquidacion' => $c?->consecutivo === null ? null : 'LIQ-'.str_pad((string) $c->consecutivo, 6, '0', STR_PAD_LEFT),
                ];

                if ($c?->consecutivo !== null) {
                    $fila['liquidaciones'][(int) $c->consecutivo] = 'LIQ-'.str_pad((string) $c->consecutivo, 6, '0', STR_PAD_LEFT);
                }

                unset($fila);
            }
        }

        // Pagos de custodia confirmados SIN guia (anteriores a C6): historico pendiente de vinculo. No se vinculan ni se reconstruyen.
        $historicos = DB::table('bylopez_va_aplicaciones_pago as a')->join('bylopez_va_pagos as p', 'p.id', '=', 'a.pago_id')
            ->whereIn('a.order_id', $ids)->whereNull('a.anulada_en')->whereNull('p.guia_id')->where('p.estado', 'confirmado')
            ->where(fn ($q) => $q->whereIn('p.medio_cobro', (array) config('ventasasesor.comisiones.medios_custodia_tercero', []))
                ->orWhere('p.observacion', (string) config('ventasasesor.comisiones.observacion_cobro_en_entrega')))
            ->groupBy('a.order_id')->selectRaw('a.order_id, SUM(a.monto) AS total')->pluck('total', 'order_id');

        foreach ($historicos as $orderId => $total) {
            $salida[(int) $orderId]['historico_sin_vinculo'] = round((float) $total, 2);
        }

        foreach ($salida as &$fila) {
            $fila['liquidaciones'] = array_values($fila['liquidaciones']);
        }

        return $salida;
    }

    /** @return array<string, mixed> */
    private function vacio(): array
    {
        return [
            'aplica' => false, 'cobrado' => 0.0, 'en_custodia' => 0.0, 'conciliado' => 0.0, 'efectivo' => 0.0, 'compensado' => 0.0,
            'pendiente_transportador' => 0.0, 'guias' => [], 'liquidaciones' => [], 'historico_sin_vinculo' => 0.0,
        ];
    }
}
