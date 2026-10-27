<?php

namespace ByLopez\Logistica\Services\Despachos;

use ByLopez\Logistica\Contracts\SugiereRecaudo;
use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Support\ValorDevolucionesEnvio;
use Illuminate\Support\Facades\DB;
use Webkul\Sales\Models\Order;

/**
 * C1 — RECAUDO que una guia debe cobrar, con UNA sola regla para el drawer (contexto) y para el servidor (despachar):
 *
 *   exigible    = saldo que el cliente aun debe y que las transportadoras pueden cobrar (SugiereRecaudo::saldoExigible).
 *   comprometido = Σ recaudo_esperado de las guias que el pedido YA tiene, menos lo que Bodega ya RECIBIO devuelto de cada una
 *                  (C5: el exigible baja por esa devolucion; descontarla tambien aqui la contaria dos veces).
 *   disponible  = exigible − comprometido            ← techo de ESTA guia (la suma de las guias nunca supera el exigible).
 *   mercancia   = valor HISTORICO de las unidades de ESTA guia (linea del pedido: precio efectivo − descuento + impuesto).
 *   flete       = lo que el cliente pago de domicilio EN EL PEDIDO (grand_total − Σ lineas): se cobra UNA sola vez, en la
 *                 PRIMERA guia del pedido; las siguientes no lo repiten.
 *   sugerido    = min(disponible, mercancia + flete), y 0 si el pedido no es contra entrega.
 *
 * Despacho unico (una sola guia con TODO lo pendiente, el caso normal): sugerido = exigible completo, igual que antes de C1.
 * El costo real del servicio de la transportadora (`valor_flete` de la guia) es OTRA cosa: se cobra por viaje y no entra aqui.
 * El cobro en Cartera (C6) NO se resuelve aqui. Lo devuelto y recibido en bodega (C5) se descuenta del recaudo de la guia
 * que lo llevaba; lo devuelto pero aun NO recibido sigue comprometido.
 */
class RecaudoPorGuia
{
    public function __construct(
        protected SugiereRecaudo $recaudo,
        protected ValorDevolucionesEnvio $valores,
        protected UnidadesDespachables $unidades,
    ) {}

    /**
     * @param  array<int, int>  $unidades  order_item_id (linea padre) => cantidad de ESTA guia
     * @return array{contra_entrega: bool, exigible: float, comprometido: float, disponible: float, hay_previas: bool, completa: bool,
     *               mercancia: float, flete: float, sugerido: float, lineas: list<array<string, mixed>>}
     */
    public function calcular(Order $pedido, array $unidades, bool $cobrarAlEntregar = false): array
    {
        $contexto = $this->contextoPedido($pedido);
        // F6: pedido que no es contra entrega con «cobrar al entregar» vigente: misma regla de recaudo por guia.
        $contexto['contra_entrega'] = $contexto['contra_entrega'] || $cobrarAlEntregar;
        $completa = $this->esDespachoCompleto($pedido, $unidades);
        $mercancia = $this->valores->deUnidades($unidades);
        $flete = $contexto['hay_previas'] ? 0.0 : $contexto['flete_pedido'];

        $bruto = (! $contexto['hay_previas'] && $completa) ? $contexto['exigible'] : round($mercancia['valor'] + $flete, 2);

        return $contexto + [
            'completa' => $completa,
            'mercancia' => $mercancia['valor'],
            'flete' => $flete,
            'lineas' => $mercancia['lineas'],
            'sugerido' => $contexto['contra_entrega'] ? round(max(0.0, min($contexto['disponible'], $bruto)), 2) : 0.0,
        ];
    }

    /**
     * Lo del pedido que no depende de las unidades elegidas (el drawer lo recibe una vez y recalcula al cambiar cantidades).
     *
     * @return array{contra_entrega: bool, exigible: float, comprometido: float, disponible: float, hay_previas: bool, flete_pedido: float}
     */
    public function contextoPedido(Order $pedido): array
    {
        $id = (int) $pedido->id;
        $exigible = $this->recaudo->saldoExigible($id);
        $guias = Guia::query()->where('order_id', $id);
        $devueltoPorEnvio = $this->devueltoRecibidoPorEnvio($id);
        $comprometido = round((float) (clone $guias)->get(['shipment_id', 'recaudo_esperado'])
            ->sum(fn ($g) => max(0.0, (float) $g->recaudo_esperado - ($devueltoPorEnvio[(int) $g->shipment_id] ?? 0.0))), 2);

        return [
            'contra_entrega' => $this->recaudo->recaudoSugerido($id) > 0,
            'exigible' => $exigible,
            'comprometido' => $comprometido,
            'disponible' => round(max(0.0, $exigible - $comprometido), 2),
            'hay_previas' => (clone $guias)->exists(),
            'flete_pedido' => $this->fleteCobradoAlCliente($id),
        ];
    }

    /**
     * Lo que el drawer necesita para PROPONER el recaudo al cambiar las cantidades (la misma regla de calcular()): valor de una
     * unidad por linea pendiente. El servidor siempre recalcula al despachar; esto solo evita pedirlo en cada clic.
     *
     * @param  list<int>  $lineaIds
     * @return array<int, float>
     */
    public function valoresUnitarios(array $lineaIds): array
    {
        return collect($this->valores->deUnidades(array_fill_keys($lineaIds, 1))['lineas'])
            ->mapWithKeys(fn (array $l) => [$l['order_item_id'] => $l['valor_unitario']])->all();
    }

    /**
     * C5: valor historico de lo RECIBIDO por devolucion en bodega, por envio (recepciones confirmadas).
     *
     * @return array<int, float>
     */
    private function devueltoRecibidoPorEnvio(int $orderId): array
    {
        return DB::table('bylopez_lg_recepcion_devolucion_items as i')
            ->join('bylopez_lg_recepciones_devolucion as r', 'r.id', '=', 'i.recepcion_id')
            ->where('r.order_id', $orderId)->where('r.estado', 'confirmado')->whereNotNull('i.valor_devuelto')
            ->groupBy('r.shipment_id')->selectRaw('r.shipment_id, SUM(i.valor_devuelto) AS valor')
            ->pluck('valor', 'shipment_id')->map(fn ($v) => round((float) $v, 2))->all();
    }

    /**
     * Domicilio cobrado AL CLIENTE en el pedido = lo que no es mercancia: grand_total − Σ (total − descuento + impuesto) de las
     * lineas. Se deduce asi (y no de un campo de envio) para que mercancia + flete cuadre SIEMPRE con el total del pedido.
     */
    private function fleteCobradoAlCliente(int $orderId): float
    {
        $total = (float) DB::table('orders')->where('id', $orderId)->value('base_grand_total');
        $lineas = (float) DB::table('order_items')->where('order_id', $orderId)->whereNull('parent_id')
            ->sum(DB::raw('base_total - base_discount_amount + base_tax_amount'));

        return round(max(0.0, $total - $lineas), 2);
    }

    /** Esta guia lleva TODO lo que el pedido tiene pendiente de enviar (nada queda para otra guia). */
    private function esDespachoCompleto(Order $pedido, array $unidades): bool
    {
        $pendientes = $this->unidades->pendientes($pedido);

        return $pendientes->isNotEmpty()
            && $pendientes->every(fn ($linea) => (int) ($unidades[(int) $linea->id] ?? 0) === (int) $linea->qty_to_ship);
    }
}
