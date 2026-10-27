<?php

namespace ByLopez\Logistica\Support;

use ByLopez\Logistica\Contracts\SugiereRecaudo;
use Illuminate\Support\Facades\DB;

/** Sugerencia solo con datos de Bagisto: contra entrega → total del pedido menos lo ya transaccionado; si no, 0. */
class RecaudoSugeridoBagisto implements SugiereRecaudo
{
    public const METODO_CONTRAENTREGA = 'cashondelivery';

    public function recaudoSugerido(int $orderId): float
    {
        $metodo = DB::table('order_payment')->where('order_id', $orderId)->value('method');

        return $metodo === self::METODO_CONTRAENTREGA ? $this->saldoExigible($orderId) : 0.0;
    }

    public function saldoExigible(int $orderId): float
    {
        $total = (float) DB::table('orders')->where('id', $orderId)->value('base_grand_total');
        $pagado = (float) DB::table('order_transactions')->where('order_id', $orderId)->sum('amount');

        return round(max(0.0, $total - $pagado), 2);
    }
}
