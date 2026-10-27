<?php

namespace ByLopez\VentasAsesor\Listeners;

use Illuminate\Support\Facades\DB;

/**
 * F5, transicion sin backfill. Un pedido confirmado ANTES de la correccion (variante de configurable con `qty_ordered` = 0)
 * nunca reservo nada. Al despacharlo, ShipmentRepository igual resta lo despachado de `product_ordered_inventories` de la
 * variante (Bagisto lo hace con cualquier hijo y lo recorta en 0), y esa fila ya contiene las reservas de los pedidos NUEVOS:
 * el despacho del pedido viejo «gastaria» unidades comprometidas por otros y abriria la puerta a la sobreventa.
 *
 * Por eso, solo para esos hijos sin cantidad: antes del Shipment (`sales.shipment.save.before`, dentro de su transaccion) se
 * bloquea y anota la reserva de cada variante; despues (`sales.shipment.save.after`, misma transaccion) se deja tal cual
 * estaba. El fisico SI baja (las prendas salieron). Los pedidos nuevos no pasan por aqui: su reserva se consume normalmente.
 */
class ProtegerReservasEnDespachoLegado
{
    public const ANTES = 'sales.shipment.save.before';

    public const DESPUES = 'sales.shipment.save.after';

    /** @var array<int, list<array{id: int, qty: int}>> order_id => reservas a conservar */
    protected static array $anotadas = [];

    /**
     * Laravel entrega el arreglo del evento como argumentos sueltos (`array_values`), y el orden de las claves depende de quien
     * arma los datos (panel nativo o DespachoService). El pedido se deriva de las lineas del envio (`shipment.items`), que
     * siempre existen, o de un `order_id` explicito.
     */
    public function antes(mixed ...$argumentos): void
    {
        $orderId = $this->pedidoDe($argumentos);
        unset(self::$anotadas[$orderId]);

        if ($orderId === 0) {
            return;
        }

        $variantes = DB::table('order_items as h')
            ->join('order_items as l', 'l.id', '=', 'h.parent_id')
            ->where('l.order_id', $orderId)->where('l.type', 'configurable')->where('h.qty_ordered', 0)
            ->distinct()->pluck('h.product_id')->all();

        if ($variantes === []) {
            return;
        }

        $canalId = (int) DB::table('orders')->where('id', $orderId)->value('channel_id');

        self::$anotadas[$orderId] = DB::table('product_ordered_inventories')
            ->whereIn('product_id', $variantes)->where('channel_id', $canalId)
            ->orderBy('id')->lockForUpdate()->get(['id', 'qty'])
            ->map(fn ($f) => ['id' => (int) $f->id, 'qty' => (int) $f->qty])->all();
    }

    /** @param  list<mixed>  $argumentos */
    protected function pedidoDe(array $argumentos): int
    {
        foreach ($argumentos as $a) {
            if (is_array($a) && isset($a['order_id'])) {
                return (int) $a['order_id'];
            }

            if (is_array($a) && isset($a['items']) && is_array($a['items']) && $a['items'] !== []) {
                return (int) (DB::table('order_items')->where('id', (int) array_key_first($a['items']))->value('order_id') ?? 0);
            }
        }

        return 0;
    }

    public function despues($envio): void
    {
        $orderId = (int) ($envio->order_id ?? 0);

        foreach (self::$anotadas[$orderId] ?? [] as $fila) {
            DB::table('product_ordered_inventories')->where('id', $fila['id'])->update(['qty' => $fila['qty']]);
        }

        unset(self::$anotadas[$orderId]);
    }
}
