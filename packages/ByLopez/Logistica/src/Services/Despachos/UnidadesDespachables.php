<?php

namespace ByLopez\Logistica\Services\Despachos;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Inventory\Models\InventorySourceProxy;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderItem;

/**
 * Que se puede despachar de un pedido y desde que bodega. Reproduce las reglas del despacho NATIVO de Bagisto 2.4.9
 * (Admin\...\ShipmentController::isInventoryValidate y la vista de creacion), porque ShipmentRepository::create no las
 * valida (solo recorta a `qty_to_ship`): son del controlador y aqui no hay controlador de Bagisto.
 *
 *  - PENDIENTE de una linea = `qty_to_ship` de Bagisto = pedido − enviado − reembolsado − cancelado.
 *  - MAXIMO de una linea en una bodega = min(pendiente, stock permitido):
 *      · producto simple:    stock de la bodega;
 *      · producto compuesto: por cada hijo, lo que el Shipment le descuenta (`(qty_hijo / qty_linea) * cantidad`; razon 1
 *        si el hijo no tiene cantidad, el caso de TODOS los configurables reales) no puede superar su stock en la bodega,
 *        ni (si tiene cantidad propia) su pendiente. El despacho nativo no valida el stock de hijos sin cantidad, pero el
 *        Shipment SI lo descuenta: aqui se mide lo que realmente se va a descontar.
 *    Es la UNICA regla de cantidades: la interfaz precarga este maximo y la validacion del servidor lo exige (no hay
 *    una segunda regla en JavaScript).
 *  - Un Shipment sale de UNA bodega: se elige la que permita despachar mas unidades (empate: la de mayor prioridad).
 *
 * Con Bagisto 2.4.9 una linea configurable puede estar reservada con 0; aqui el stock se mide en
 * `product_inventories`, que es lo que descuenta el Shipment.
 */
class UnidadesDespachables
{
    /** @return Collection<int, OrderItem> lineas con unidades pendientes de enviar, por id de linea */
    public function pendientes(Order $pedido): Collection
    {
        return $pedido->items()->get()->filter(fn (OrderItem $linea) => $linea->canShip())->keyBy('id');
    }

    public function totalPendiente(Order $pedido): int
    {
        return (int) $this->pendientes($pedido)->sum(fn (OrderItem $linea) => $linea->qty_to_ship);
    }

    /**
     * Unidades pedidas por el operador → mapa order_item_id => cantidad validada. Sin solicitud, TODAS las pendientes.
     * Aqui se rechaza todo lo que supere lo PENDIENTE (sobre-despacho); el stock lo valida elegirBodega().
     *
     * @param  array<int|string, mixed>  $solicitadas
     * @return array<int, int>
     */
    public function normalizar(Order $pedido, array $solicitadas): array
    {
        $pendientes = $this->pendientes($pedido);

        if ($pendientes->isEmpty()) {
            throw new Invalida(Invalida::PEDIDO_NO_DESPACHABLE, ['pedido' => $pedido->id]);
        }

        if ($solicitadas === []) {
            return $pendientes->map(fn (OrderItem $linea) => (int) $linea->qty_to_ship)->all();
        }

        $unidades = [];

        foreach ($solicitadas as $lineaId => $cantidad) {
            $id = filter_var($lineaId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $n = filter_var($cantidad, FILTER_VALIDATE_INT);

            if ($id === false || $n === false || $n < 0) {
                throw new Invalida(Invalida::CANTIDAD_INVALIDA, ['order_item_id' => $lineaId]);
            }

            if ($n === 0) {
                continue; // el panel envia todas las lineas; las que quedan en 0 no son parte del despacho.
            }

            $linea = $pendientes->get($id) ?? throw new Invalida(Invalida::LINEA_NO_DEL_PEDIDO, ['order_item_id' => $id]);

            if ($n > (int) $linea->qty_to_ship) {
                throw new Invalida(Invalida::CANTIDAD_EXCEDE_PENDIENTE, [
                    'order_item_id' => $id, 'pendientes' => (int) $linea->qty_to_ship, 'solicitadas' => $n,
                ]);
            }

            $unidades[$id] = $n;
        }

        if ($unidades === []) {
            throw new Invalida(Invalida::SIN_UNIDADES);
        }

        return $unidades;
    }

    /**
     * Bodega desde la que se despacha y, por linea, pendiente / disponible / maximo. Es lo que la interfaz precarga.
     *
     * @return array{bodega: ?object, lineas: array<int, array{pendientes: int, disponibles: int, maximo: int}>}
     */
    public function plan(Order $pedido): array
    {
        $lineas = $this->pendientes($pedido);
        $mejor = null;

        foreach ($this->bodegas($pedido) as $bodega) {
            $porLinea = [];

            foreach ($lineas as $id => $linea) {
                $porLinea[$id] = [
                    'pendientes' => (int) $linea->qty_to_ship,
                    'disponibles' => $this->disponiblesEnBodega($linea, (int) $bodega->id),
                    'maximo' => $this->maximoEnBodega($linea, (int) $bodega->id),
                ];
            }

            $total = array_sum(array_column($porLinea, 'maximo'));

            // Estricto: ante un empate gana la de mayor prioridad (la primera).
            if ($mejor === null || $total > $mejor['total']) {
                $mejor = ['bodega' => $bodega, 'lineas' => $porLinea, 'total' => $total];
            }
        }

        if ($mejor === null) {
            return ['bodega' => null, 'lineas' => $lineas->map(fn (OrderItem $l) => [
                'pendientes' => (int) $l->qty_to_ship, 'disponibles' => 0, 'maximo' => 0,
            ])->all()];
        }

        return ['bodega' => $mejor['total'] > 0 ? $mejor['bodega'] : null, 'lineas' => $mejor['lineas']];
    }

    /**
     * Primera bodega activa del canal que permite TODAS las unidades pedidas. Ninguna: STOCK_INSUFICIENTE con la
     * primera linea que falla en la mejor candidata. Se vuelve a calcular aqui, bajo el bloqueo del pedido y con las
     * filas de inventario bloqueadas, aunque la interfaz ya haya acotado la cantidad.
     *
     * @param  array<int, int>  $unidades  order_item_id => cantidad (ya normalizada)
     */
    public function elegirBodega(Order $pedido, array $unidades): object
    {
        $lineas = $this->pendientes($pedido);
        $bodegas = $this->bodegas($pedido);
        $faltante = null;

        foreach ($bodegas as $bodega) {
            $faltante = $this->primerFaltante($lineas, $unidades, (int) $bodega->id);

            if ($faltante === null) {
                return $bodega;
            }
        }

        throw new Invalida(Invalida::STOCK_INSUFICIENTE, $faltante ?? ['motivo' => 'sin_bodegas']);
    }

    /** Maximo despachable de una linea desde una bodega: min(pendiente, stock permitido). Misma regla que la validacion. */
    public function maximoEnBodega(OrderItem $linea, int $bodegaId): int
    {
        return max(0, min($this->limitePorPendiente($linea), $this->disponiblesEnBodega($linea, $bodegaId)));
    }

    /** Unidades de la linea que el STOCK de la bodega permite (sin considerar lo pendiente del pedido). */
    public function disponiblesEnBodega(OrderItem $linea, int $bodegaId): int
    {
        if (! $linea->getTypeInstance()->isComposite()) {
            return max(0, $this->stock($linea, $bodegaId));
        }

        // Lo que el Shipment DESCUENTA del hijo: (qty_hijo / qty_padre) × cantidad; con hijo de cantidad 0 (todos los configurables
        // reales de este proyecto) ShipmentRepository descuenta la cantidad completa (razon 1). Se mide el stock contra eso.
        $limites = [];

        foreach ($linea->children as $hijo) {
            $limites[] = $this->unidadesDePadre($this->stock($hijo, $bodegaId), $this->razon($hijo, $linea));
        }

        return $limites === [] ? 0 : max(0, min($limites));
    }

    /** @return list<object> bodegas activas del canal del pedido (o todas las activas), por prioridad */
    private function bodegas(Order $pedido): array
    {
        $bodegas = $pedido->channel?->inventory_sources()->where('status', 1)->orderBy('priority')->orderBy('id')->get() ?? collect();

        if ($bodegas->isEmpty()) {
            $bodegas = InventorySourceProxy::modelClass()::query()->where('status', 1)->orderBy('priority')->orderBy('id')->get();
        }

        return $bodegas->all();
    }

    /** Pendiente de la linea y, en compuestos, lo que dejan pendiente sus hijos (misma comparacion que el despacho nativo). */
    private function limitePorPendiente(OrderItem $linea): int
    {
        $limite = (int) $linea->qty_to_ship;

        if ($linea->getTypeInstance()->isComposite()) {
            // Pendiente del hijo: solo si tiene cantidad propia, igual que la validacion nativa.
            foreach ($linea->children as $hijo) {
                if ($hijo->qty_ordered) {
                    $limite = min($limite, $this->unidadesDePadre((int) $hijo->qty_to_ship, $this->razon($hijo, $linea)));
                }
            }
        }

        return $limite;
    }

    /** Unidades del hijo por cada unidad del padre, como las usa ShipmentRepository: qty_hijo / qty_padre, o 1 si el hijo no tiene cantidad. */
    private function razon(OrderItem $hijo, OrderItem $padre): float
    {
        return $hijo->qty_ordered && $padre->qty_ordered ? $hijo->qty_ordered / $padre->qty_ordered : 1.0;
    }

    /** Cuantas unidades de la linea PADRE alcanzan con `$cantidadHijo` unidades del hijo (hijo = razon × padre). */
    private function unidadesDePadre(float|int $cantidadHijo, float $razon): int
    {
        return (int) floor(($cantidadHijo / $razon) + 1e-9);
    }

    /**
     * @param  Collection<int, OrderItem>  $lineas
     * @param  array<int, int>  $unidades
     * @return array<string, mixed>|null detalle de la primera linea que supera lo permitido, o null si alcanza
     */
    private function primerFaltante(Collection $lineas, array $unidades, int $bodegaId): ?array
    {
        foreach ($unidades as $lineaId => $cantidad) {
            /** @var OrderItem $linea */
            $linea = $lineas->get($lineaId);
            $maximo = $this->maximoEnBodega($linea, $bodegaId);

            if ($cantidad > $maximo) {
                return ['sku' => $linea->sku, 'necesarias' => $cantidad, 'disponibles' => $maximo];
            }
        }

        return null;
    }

    /**
     * Stock de un producto en la bodega, como lo mide Bagisto (suma de `product_inventories`). Dentro de una transaccion
     * (el despacho) las filas se bloquean: dos despachos que comparten producto se serializan y el segundo ve el stock ya
     * descontado, en vez de ambos descontar del mismo saldo.
     */
    private function stock(OrderItem $linea, int $bodegaId): int
    {
        if (! $linea->product_id) {
            return 0;
        }

        $consulta = DB::table('product_inventories')->where('product_id', $linea->product_id)->where('inventory_source_id', $bodegaId);

        if (DB::transactionLevel() > 0) {
            $consulta->lockForUpdate();
        }

        return (int) $consulta->sum('qty');
    }
}
