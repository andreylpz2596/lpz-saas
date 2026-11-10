<?php

namespace ByLopez\VentasAsesor\Services\Credito;

use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Models\CreditoClienteMovimiento as Mov;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D10 V1 — FUENTE UNICA del saldo a favor (credito) de un pedido y de un cliente. SOLO LECTURA.
 *
 * El credito NO se guarda al generarse: se DERIVA de las fuentes V1 autorizadas (devoluciones fisicas C5 recibidas y ajustes de
 * obligacion autorizados). La cancelacion del pendiente NO es fuente (V2), aunque siga reduciendo el total operativo de Cartera.
 * Lo unico persistido es lo CONSUMIDO (ledger de movimientos).
 *
 *   economico   = min(devuelto + ajustado,  max(0, verificado − totalOperativo))        «lo pagado de mas por esas causas»
 *   recibido    = dinero REAL ya en poder de ByLopez: pagos confirmados que no son credito, ni estan en custodia sin conciliar,
 *                 + Transactions ajenas + la parte de custodia ya CONCILIADA (efectivo + compensado)
 *   generado    = min(economico, recibido)       ← solo dinero verificado y recibido genera credito UTILIZABLE
 *   usado       = Σ(aplicacion + reembolso) sin reverso
 *   disponible  = max(0, generado − usado)       ← nunca negativo
 *
 * `verificado` parte de pagos CONFIRMADOS (nunca sin_revisar). La compensacion con credito (`credito_cliente`) satisface la
 * obligacion pero no cuenta como dinero recibido. Una consulta con `$lecturaActual = true` es BLOQUEANTE (LOCK IN SHARE MODE):
 * obligatoria para quien valida dinero dentro de una transaccion (ver AplicarCreditoService).
 */
class CreditoClienteService
{
    public const MEDIO = 'credito_cliente';

    public function __construct(protected CarteraConsultaService $cartera) {}

    /**
     * @param  list<int|string>  $orderIds
     * @return array<int, array{generado: float, economico: float, recibido: float, en_custodia: float, aplicado: float, reembolsado: float, usado: float, disponible: float, sin_recibir: float, aplicado_a_este_pedido: float}>
     */
    public function dePedidos(array $orderIds, bool $lecturaActual = false): array
    {
        $ids = array_values(array_unique(array_map('intval', $orderIds)));

        if ($ids === []) {
            return [];
        }

        $saldos = $this->cartera->saldosDePedidos($ids, $lecturaActual);
        $usado = $this->usadoPorPedido($ids, $lecturaActual);
        $recibido = $this->recibidoPorPedido($ids, $saldos, $lecturaActual);
        $salida = [];

        foreach ($ids as $id) {
            $s = $saldos[$id] ?? null;
            $economico = 0.0;

            if ($s !== null && $s->enCartera) {
                // Fuentes V1: solo devolucion fisica C5 + ajuste autorizado. `cancelado` (cancelacion del pendiente) sigue reduciendo
                // `totalOperativo` en Cartera, pero NO origina credito: cancelar un pedido pagado como fuente general es V2.
                $reducido = round($s->devuelto + $s->ajustado, 2);
                $economico = $reducido > 0
                    ? round(min($reducido, max(0.0, (float) $s->verificado - (float) $s->totalOperativo)), 2)
                    : 0.0;
            }

            $real = $recibido[$id]['recibido'] ?? 0.0;
            $generado = round(min($economico, $real), 2);
            $aplicado = $usado[$id]['aplicacion'] ?? 0.0;
            $reembolsado = $usado[$id]['reembolso'] ?? 0.0;
            $total = round($aplicado + $reembolsado, 2);

            $salida[$id] = [
                'generado' => $generado,
                'economico' => $economico,
                'recibido' => $real,
                'en_custodia' => $recibido[$id]['en_custodia'] ?? 0.0,
                'aplicado' => $aplicado,
                'reembolsado' => $reembolsado,
                'usado' => $total,
                'disponible' => round(max(0.0, $generado - $total), 2),
                'sin_recibir' => round(max(0.0, $economico - $generado), 2),
                'aplicado_a_este_pedido' => $s?->creditoAplicado ?? 0.0,
            ];
        }

        return $salida;
    }

    /** @return array{generado: float, economico: float, recibido: float, en_custodia: float, aplicado: float, reembolsado: float, usado: float, disponible: float, sin_recibir: float, aplicado_a_este_pedido: float} */
    public function dePedido(int $orderId, bool $lecturaActual = false): array
    {
        return $this->dePedidos([$orderId], $lecturaActual)[$orderId];
    }

    /**
     * Pedidos de un cliente con credito (generado o consumido), del mas antiguo al mas nuevo, y el total disponible.
     *
     * @return array{total_disponible: float, pedidos: list<array<string, mixed>>}
     */
    public function delCliente(int $customerId): array
    {
        $ids = DB::table('orders')->where('customer_id', $customerId)->orderBy('created_at')->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
        $filas = [];
        $total = 0.0;

        foreach ($this->dePedidos($ids) as $id => $c) {
            if ($c['economico'] <= 0 && $c['usado'] <= 0) {
                continue;
            }

            $total += $c['disponible'];
            $filas[] = ['order_id' => $id, 'increment_id' => (string) DB::table('orders')->where('id', $id)->value('increment_id')] + $c;
        }

        return ['total_disponible' => round($total, 2), 'pedidos' => $filas];
    }

    /** Credito aplicado ACTIVO como compensacion en un pedido destino (bloquea su cancelacion). */
    public function aplicadoActivoEnDestino(int $orderId, bool $lecturaActual = false): float
    {
        $consulta = DB::table('bylopez_va_aplicaciones_pago as a')->join('bylopez_va_pagos as p', 'p.id', '=', 'a.pago_id')
            ->where('a.order_id', $orderId)->whereNull('a.anulada_en')
            ->where('p.estado', EstadoPago::CONFIRMADO->value)->where('p.medio_cobro', self::MEDIO);

        return round((float) ($lecturaActual ? $consulta->sharedLock() : $consulta)->sum('a.monto'), 2);
    }

    /**
     * Historial de consumos de un pedido (como origen o como destino), del mas reciente al mas antiguo, con su estado de reverso.
     *
     * @return list<object>
     */
    public function movimientosDe(int $orderId): array
    {
        return DB::table('bylopez_va_creditos_cliente_movimientos as m')
            ->leftJoin('bylopez_va_creditos_cliente_movimientos as r', 'r.revierte_id', '=', 'm.id')
            ->leftJoin('orders as o', 'o.id', '=', 'm.order_origen_id')->leftJoin('orders as d', 'd.id', '=', 'm.order_destino_id')
            ->where(fn ($q) => $q->where('m.order_origen_id', $orderId)->orWhere('m.order_destino_id', $orderId))
            ->orderByDesc('m.id')
            ->get(['m.id', 'm.tipo', 'm.monto', 'm.order_origen_id', 'm.order_destino_id', 'm.motivo', 'm.medio_devolucion', 'm.referencia', 'm.actor_nombre',
                'm.created_at', 'm.revierte_id', DB::raw('(m.comprobante_ruta IS NOT NULL) AS tiene_comprobante'), 'o.increment_id as origen', 'd.increment_id as destino', 'r.id as reverso_id', 'r.motivo as reverso_motivo'])
            ->all();
    }

    /**
     * Ajustes de obligacion (condonaciones) de un pedido, del mas reciente al mas antiguo.
     *
     * @return list<object>
     */
    public function ajustesDe(int $orderId): array
    {
        if (! Schema::hasTable('bylopez_va_ajustes_obligacion')) {
            return [];
        }

        return DB::table('bylopez_va_ajustes_obligacion')->where('order_id', $orderId)->orderByDesc('id')
            ->get(['id', 'concepto', 'causa', 'monto', 'motivo', 'estado', 'actor_nombre', 'created_at'])->all();
    }

    /** @return array<int, array{aplicacion: float, reembolso: float}> */
    protected function usadoPorPedido(array $ids, bool $lecturaActual): array
    {
        if (! Schema::hasTable('bylopez_va_creditos_cliente_movimientos')) {
            return [];
        }

        $consulta = DB::table('bylopez_va_creditos_cliente_movimientos as m')
            ->leftJoin('bylopez_va_creditos_cliente_movimientos as r', 'r.revierte_id', '=', 'm.id')
            ->whereIn('m.order_origen_id', $ids)->whereIn('m.tipo', [Mov::APLICACION, Mov::REEMBOLSO])->whereNull('r.id')
            ->groupBy('m.order_origen_id', 'm.tipo')->selectRaw('m.order_origen_id AS id, m.tipo, SUM(m.monto) AS total');

        $salida = [];

        foreach (($lecturaActual ? $consulta->sharedLock() : $consulta)->get() as $f) {
            $salida[(int) $f->id][(string) $f->tipo] = round((float) $f->total, 2);
        }

        return $salida;
    }

    /**
     * Dinero REAL en poder de ByLopez por pedido (ver doc de la clase) y lo que sigue en custodia sin conciliar.
     *
     * @param  array<int, \ByLopez\VentasAsesor\DataObjects\SaldoPedido>  $saldos
     * @return array<int, array{recibido: float, en_custodia: float}>
     */
    protected function recibidoPorPedido(array $ids, array $saldos, bool $lecturaActual): array
    {
        $custodia = (array) config('ventasasesor.comisiones.medios_custodia_tercero', []);
        $obs = (string) config('ventasasesor.comisiones.observacion_cobro_en_entrega');

        $consulta = DB::table('bylopez_va_aplicaciones_pago as a')->join('bylopez_va_pagos as p', 'p.id', '=', 'a.pago_id')
            ->whereIn('a.order_id', $ids)->whereNull('a.anulada_en')
            ->where('p.estado', EstadoPago::CONFIRMADO->value)->where('p.medio_cobro', '!=', self::MEDIO);

        $porPedido = [];

        foreach (($lecturaActual ? $consulta->sharedLock() : $consulta)->get(['a.order_id', 'a.monto', 'p.medio_cobro', 'p.guia_id', 'p.observacion']) as $f) {
            $enCustodia = $f->guia_id !== null || in_array($f->medio_cobro, $custodia, true) || ($obs !== '' && $f->observacion === $obs);
            $porPedido[(int) $f->order_id][$enCustodia ? 'custodia' : 'libre'] = ($porPedido[(int) $f->order_id][$enCustodia ? 'custodia' : 'libre'] ?? 0.0) + (float) $f->monto;
        }

        $conciliado = $this->conciliadoPorPedido($ids, $lecturaActual);
        $salida = [];

        foreach ($ids as $id) {
            $enCustodia = round($porPedido[$id]['custodia'] ?? 0.0, 2);
            $liberado = round(min($enCustodia, $conciliado[$id] ?? 0.0), 2);

            $salida[$id] = [
                'recibido' => round(($porPedido[$id]['libre'] ?? 0.0) + (float) ($saldos[$id]->transaccionesAjenas ?? 0.0) + $liberado, 2),
                'en_custodia' => round($enCustodia - $liberado, 2),
            ];
        }

        return $salida;
    }

    /** @return array<int, float> efectivo + compensado de los recaudos COBRADOS por transportador, ya conciliados con ByLopez */
    protected function conciliadoPorPedido(array $ids, bool $lecturaActual): array
    {
        if (! Schema::hasTable('bylopez_lg_cuenta_movimientos') || ! Schema::hasTable('bylopez_lg_conciliaciones_movimiento')) {
            return [];
        }

        $consulta = DB::table('bylopez_lg_conciliaciones_movimiento as c')->join('bylopez_lg_cuenta_movimientos as m', 'm.id', '=', 'c.movimiento_id')
            ->whereIn('m.order_id', $ids)->where('m.concepto', 'recaudo')->where('m.origen_tipo', 'cobro')
            ->groupBy('m.order_id')->selectRaw('m.order_id AS id, SUM(c.efectivo + c.compensado) AS total');

        $salida = [];

        foreach (($lecturaActual ? $consulta->sharedLock() : $consulta)->get() as $f) {
            $salida[(int) $f->id] = round((float) $f->total, 2);
        }

        return $salida;
    }
}
