<?php

namespace ByLopez\Logistica\Services\Despachos;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Cancelacion;
use ByLopez\Logistica\Models\CancelacionLinea;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use ByLopez\Logistica\Support\ValorDevolucionesEnvio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderItem;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\User\Contracts\Admin;

/**
 * CANCELACION DE LO PENDIENTE DE DESPACHO (jornada 2026-10-27/28). Reemplaza el camino nativo de Bagisto, que es peligroso para
 * ByLopez: `OrderRepository::cancel` reintegra al stock FISICO lo que ya se envio (OrderItemRepository::returnQtyToProductInventory)
 * y ademas no se puede ejecutar en pedidos autofacturados (`qty_to_cancel` descuenta lo facturado).
 *
 * Regla:
 *  - Cancela TODO lo que el pedido aun tiene pendiente de despachar (pedido − enviado − reembolsado − cancelado); no hay
 *    cancelacion parcial de un pendiente. Repetir la operacion no hace nada (CANCELACION_SIN_PENDIENTE): no se libera ni se
 *    ajusta dos veces.
 *  - Libera SOLO la reserva de esas unidades (`product_ordered_inventories`). El stock fisico (`product_inventories`) NO se
 *    toca: esas unidades seguian en bodega. Lo despachado conserva su trazabilidad (envios, guias, entregas) y solo vuelve a
 *    inventario por la recepcion fisica del retorno y su clasificacion (C5).
 *  - Pedidos anteriores a F5 (hija con `qty_ordered` 0, sin reserva): el compromiso legado baja solo (CercoInventario resta
 *    `qty_canceled`) y no se resta nada de las reservas de otros pedidos.
 *  - Obligacion del cliente: el total del pedido NO se modifica, no se crea Refund ni reembolso. El valor historico de lo cancelado
 *    (misma formula de C1/C5) se guarda aqui y Cartera lo descuenta del total exigible, identificado aparte de lo devuelto (C5), de
 *    modo que ninguna unidad se descuenta por las dos vias (una unidad cancelada no se despacha, por lo tanto no puede devolverse).
 *    El flete cobrado no se condona. Si el pedido quedara TOTALMENTE cancelado con dinero ya aplicado, se bloquea
 *    (CANCELACION_CON_PAGOS): devolver ese dinero es una decision humana (D10) que aun no esta implementada.
 *  - Permiso, motivo e historial (actor, fecha, lineas). Se valida aqui, bajo el bloqueo del pedido; ocultar un boton no autoriza
 *    nada. Las lecturas bajo el bloqueo son «lectura actual» (MariaDB REPEATABLE READ: la foto de la transaccion puede ser vieja).
 */
class CancelarPendienteService
{
    public const MOTIVO_MINIMO = 5;

    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected ValorDevolucionesEnvio $valores,
        protected CondicionDespacho $condicion,
        protected OrderRepository $pedidos,
    ) {}

    /** El historial existe (la migracion 2026_10_28_100021 esta aplicada). En cache por proceso. */
    public static function disponible(): bool
    {
        static $ok = null;

        return $ok ??= Schema::hasTable('bylopez_lg_cancelaciones') && Schema::hasTable('bylopez_lg_cancelacion_lineas');
    }

    /**
     * Lo que el drawer muestra: unidades pendientes, permiso y si hay algo que cancelar. Solo lectura.
     *
     * @return array{disponible: bool, permiso: bool, pendientes: int, valor_pendiente: float, bloqueo: ?string, historial: list<array<string, mixed>>}
     */
    public function situacion(int $orderId, ?Admin $actor): array
    {
        $lineas = $this->lineasPendientes($orderId, false);
        $valor = $this->valores->deUnidades(array_column($lineas, 'pendiente', 'id'))['valor'];

        $historial = ! self::disponible() ? [] : Cancelacion::query()->where('order_id', $orderId)->orderBy('id')->get()
            ->map(fn (Cancelacion $c) => [
                'id' => (int) $c->id, 'unidades' => (int) $c->unidades, 'valor' => round((float) $c->valor_cancelado, 2),
                'motivo' => $c->motivo, 'actor' => $c->actor_name_snapshot, 'fecha' => $c->created_at?->toDateTimeString(),
            ])->all();

        return [
            'disponible' => self::disponible(),
            'permiso' => $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_CANCELAR_PENDIENTE),
            'pendientes' => array_sum(array_column($lineas, 'pendiente')),
            'valor_pendiente' => round($valor, 2),
            'bloqueo' => $this->bloqueo($orderId, $lineas),
            'historial' => $historial,
        ];
    }

    /** @throws Invalida */
    public function cancelar(?Admin $actor, int $orderId, ?string $motivo): Cancelacion
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_CANCELAR_PENDIENTE)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }

        $motivo = trim((string) $motivo);

        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw new Invalida(Invalida::MOTIVO_CANCELACION_REQUERIDO);
        }

        if (! self::disponible()) {
            throw new Invalida(Invalida::CANCELACION_NO_DISPONIBLE);
        }

        return DB::transaction(function () use ($actor, $orderId, $motivo) {
            // El bloqueo del pedido va PRIMERO (igual que el despacho): despachar y cancelar se serializan y quien llega segundo
            // ve lo que dejo el otro. Las lineas se leen despues, con lectura actual.
            $pedido = Order::query()->lockForUpdate()->find($orderId) ?? throw new Invalida(Invalida::PEDIDO_INEXISTENTE);
            $lineas = $this->lineasPendientes($orderId, true);

            if ($bloqueo = $this->bloqueo($orderId, $lineas)) {
                throw new Invalida($bloqueo);
            }

            $valores = $this->valores->deUnidades(array_column($lineas, 'pendiente', 'id'));
            $valorPorLinea = array_column($valores['lineas'], 'valor', 'order_item_id');

            $cancelacion = Cancelacion::query()->create([
                'order_id' => $orderId,
                'motivo' => mb_substr($motivo, 0, 500),
                'unidades' => array_sum(array_column($lineas, 'pendiente')),
                'valor_cancelado' => $valores['valor'],
                'actor_admin_id' => (int) $actor->id,
                'actor_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
            ]);

            foreach ($lineas as $l) {
                $libero = $this->cancelarLinea($pedido, $l['id'], $l['pendiente']);

                CancelacionLinea::query()->create([
                    'cancelacion_id' => $cancelacion->id, 'order_item_id' => $l['id'], 'cantidad' => $l['pendiente'],
                    'valor' => $valorPorLinea[$l['id']] ?? 0, 'libero_reserva' => $libero,
                ]);
            }

            // Estado del pedido por el mecanismo nativo (cantidades), sin tocar facturas, pagos ni stock fisico.
            $this->pedidos->updateOrderStatus($pedido->fresh());

            return $cancelacion;
        });
    }

    /**
     * Lineas PADRE con unidades pendientes de despachar. `$actual` = lectura actual (FOR UPDATE), obligatoria dentro de la
     * transaccion de cancelar.
     *
     * @return list<array{id: int, pendiente: int}>
     */
    private function lineasPendientes(int $orderId, bool $actual): array
    {
        $consulta = DB::table('order_items')->where('order_id', $orderId)->whereNull('parent_id')->orderBy('id');

        if ($actual) {
            $consulta->lockForUpdate();
        }

        $salida = [];

        foreach ($consulta->get(['id', 'qty_ordered', 'qty_shipped', 'qty_refunded', 'qty_canceled']) as $i) {
            $pendiente = (int) $i->qty_ordered - (int) $i->qty_shipped - (int) $i->qty_refunded - (int) $i->qty_canceled;

            if ($pendiente > 0) {
                $salida[] = ['id' => (int) $i->id, 'pendiente' => $pendiente];
            }
        }

        return $salida;
    }

    /**
     * Por que NO se puede cancelar (null si se puede): nada pendiente, o el pedido quedaria totalmente cancelado con dinero aplicado.
     *
     * @param  list<array{id: int, pendiente: int}>  $lineas
     */
    private function bloqueo(int $orderId, array $lineas): ?string
    {
        if ($lineas === []) {
            return Invalida::CANCELACION_SIN_PENDIENTE;
        }

        $vigente = (int) DB::table('order_items')->where('order_id', $orderId)->whereNull('parent_id')
            ->sum(DB::raw('qty_ordered - qty_canceled'));

        if ($vigente - array_sum(array_column($lineas, 'pendiente')) <= 0) {
            $pago = $this->condicion->pagoPendiente($orderId, false);

            if (round((float) ($pago['verificado'] ?? 0) + (float) $pago['sin_revisar'], 2) > 0.001) {
                return Invalida::CANCELACION_CON_PAGOS;
            }
        }

        return null;
    }

    /**
     * Cancela las unidades en la linea (y en sus hijas) y devuelve la reserva SOLO de las que la tenian. true si libero alguna.
     * No toca `product_inventories`.
     */
    private function cancelarLinea(Order $pedido, int $lineaId, int $cantidad): bool
    {
        $linea = OrderItem::query()->lockForUpdate()->findOrFail($lineaId);
        $hijas = OrderItem::query()->where('parent_id', $lineaId)->lockForUpdate()->get();
        $libero = false;

        $linea->qty_canceled += $cantidad;
        $linea->save();

        // Simple: la reserva es de la propia linea. Compuesto: la de cada hija con cantidad (la legada, con 0, no reservo nada).
        $reservas = [];

        if ($hijas->isEmpty()) {
            $reservas[] = [$linea->product_id, $cantidad];
        }

        foreach ($hijas as $hija) {
            if ((int) $hija->qty_ordered > 0) {
                $unidades = (int) round($cantidad * $hija->qty_ordered / max(1, (int) $linea->qty_ordered));
                $hija->qty_canceled += $unidades;
                $hija->save();
                $reservas[] = [$hija->product_id, $unidades];
            }
        }

        foreach ($reservas as [$productoId, $unidades]) {
            if ($unidades <= 0 || ! $productoId) {
                continue;
            }

            $fila = DB::table('product_ordered_inventories')->where('product_id', $productoId)
                ->where('channel_id', $pedido->channel_id)->lockForUpdate()->first();

            if ($fila === null) {
                continue;
            }

            DB::table('product_ordered_inventories')->where('id', $fila->id)->update(['qty' => max(0, (int) $fila->qty - $unidades)]);
            $libero = true;
        }

        return $libero;
    }
}
