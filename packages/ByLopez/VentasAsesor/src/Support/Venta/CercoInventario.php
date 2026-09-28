<?php

namespace ByLopez\VentasAsesor\Support\Venta;

use ByLopez\VentasAsesor\Exceptions\PedidoRechazadoPorCercoException as Rechazo;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\ProductProxy;

/**
 * D-54: proteccion concurrente de inventario para la creacion de pedidos que pasan por
 * OrderRepository (tienda, panel y VentasAsesor). Corre dentro de la transaccion de
 * OrderRepository::createOrderIfNotThenRetry(), en `checkout.order.save.before`, antes de
 * insertar el pedido.
 *
 * 1. Bloquea con FOR UPDATE las filas `products` de todos los productos del pedido, en una
 *    sola consulta y por id ASCENDENTE (dos pedidos con [v1, v2] y [v2, v1] no se cruzan).
 *    La fila `products` existe siempre; `product_ordered_inventories` no.
 * 2. Revalida el stock vendible con la formula de Bagisto (Indexers\Inventory::getQuantity):
 *    Σ product_inventories.qty de las fuentes ACTIVAS del canal − product_ordered_inventories
 *    .qty del canal, solo para productos con manage_stock, y back_orders donde Bagisto lo
 *    admite (tipo simple). Si no alcanza, rechaza: nunca reduce cantidades.
 *
 * NO reserva ni descuenta: la reserva la sigue haciendo manageInventory() de Bagisto, ahora
 * serializada por el bloqueo. Por que ve los datos correctos: en REPEATABLE READ la vista
 * consistente de la transaccion nace con su PRIMERA lectura no bloqueante. Este cerco hace
 * solo lecturas bloqueantes antes del FOR UPDATE de `products`, asi que esa vista (la que
 * usan estas lecturas y la de manageInventory) nace DESPUES de obtener el bloqueo y ve el
 * commit del pedido anterior. Por eso el cerco debe ser el PRIMER listener de
 * `checkout.order.save.before` (lo afirma una prueba).
 *
 * Fuera de alcance (riesgo residual conocido, D-54): Shipment concurrente, ajustes directos
 * de inventario y operaciones que no pasan por OrderRepository no toman este bloqueo.
 *
 * LIMITACION CONOCIDA (Doc. 05 §39.4 y §40; decidida por Camilo Gaitan: no se corrige): la
 * proteccion es efectiva donde Bagisto RESERVA. Para las variantes de un configurable, Bagisto
 * 2.4.9 reserva 0 (hijo con cantidad 0), asi que un pedido anterior no descuenta nada del
 * vendible y el cerco no puede verlo. Este cerco no promete "sin sobreventa" en esas
 * variantes ni crea reservas propias. Bagisto sigue siendo la autoridad de inventario.
 */
class CercoInventario
{
    /**
     * Bloquea las filas `products` del pedido (id ascendente). Debe ser la primera lectura
     * no bloqueante del cerco la que venga DESPUES de esto.
     *
     * @param  array<string, mixed>  $pedido  Datos de OrderResource (los de OrderRepository::create).
     * @return list<int> Ids bloqueados.
     */
    public function bloquear(array $pedido): array
    {
        $ids = array_keys($this->productosDelPedido($pedido, false));

        if ($ids === []) {
            return [];
        }

        sort($ids);

        return DB::table('products')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Revalida el stock bajo el bloqueo de bloquear(). Rechaza con las variantes afectadas.
     *
     * @param  array<string, mixed>  $pedido
     */
    public function validar(array $pedido): void
    {
        $cantidades = $this->productosDelPedido($pedido, true);

        if ($cantidades === []) {
            return;
        }

        $productos = ProductProxy::modelClass()::query()->whereKey(array_keys($cantidades))->get()->keyBy('id');
        $gestionados = $productos->filter(fn ($p) => $this->bagistoExigeStock($p));

        if ($gestionados->isEmpty()) {
            return;
        }

        $canalId = (int) ($pedido['channel_id'] ?? 0);
        $vendible = $this->vendible($gestionados->keys()->map(fn ($id) => (int) $id)->all(), $canalId);
        $backOrders = (bool) core()->getConfigData('catalog.inventory.stock_options.back_orders');
        $afectadas = [];

        foreach ($gestionados as $id => $producto) {
            $cantidad = $cantidades[(int) $id];
            $disponible = $vendible[(int) $id] ?? 0;

            // Misma regla que Simple::haveSufficientQuantity (qty <= total ?: back_orders);
            // Virtual::haveSufficientQuantity no admite back_orders.
            if ($cantidad <= $disponible || ($backOrders && $producto->type === 'simple')) {
                continue;
            }

            $afectadas[] = ['variante_id' => (int) $id, 'cantidad' => $cantidad, 'stock_vendible' => $disponible];
        }

        if ($afectadas !== []) {
            throw new Rechazo(Rechazo::STOCK_INSUFICIENTE, ['variantes' => $afectadas]);
        }
    }

    /**
     * Solo donde Bagisto 2.4.9 exige stock: Simple::haveSufficientQuantity y
     * Virtual::haveSufficientQuantity comprueban manage_stock contra el vendible. Los demas
     * tipos no lo exigen (Downloadable y Booking heredan o devuelven true; el configurable se
     * valida por su hijo, que es simple). El cerco no endurece lo que Bagisto no exige.
     */
    protected function bagistoExigeStock($producto): bool
    {
        return in_array($producto->type, ['simple', 'virtual'], true) && (bool) $producto->manage_stock;
    }

    /**
     * Productos del pedido con la misma seleccion que OrderItemRepository::manageInventory:
     * los hijos de un item compuesto (configurable, bundle) o el propio item. Con
     * $soloCantidades = false devuelve TODOS los ids (padres incluidos) para bloquear.
     *
     * Cantidad de la variante de un CONFIGURABLE = cantidad del padre, la misma que valida
     * Bagisto (Configurable::isItemHaveQuantity usa $cartItem->quantity). NO se usa el
     * qty_ordered del hijo: Configurable::prepareForCart no le da cantidad, la columna
     * cart_items.quantity vale 0 por defecto y OrderItemResource calcula 0 × padre = 0
     * (verificado en lpz_saas: hijos con cantidad 0 en cart_items y order_items). Ver el
     * riesgo residual en Doc. 05 §39: con ese 0, manageInventory tampoco reserva la variante.
     *
     * @return array<int, int> product_id => cantidad pedida (sumada si se repite).
     */
    protected function productosDelPedido(array $pedido, bool $soloCantidades): array
    {
        $productos = [];

        foreach ((array) ($pedido['items'] ?? []) as $item) {
            $hijos = (array) ($item['children'] ?? []);
            $esConfigurable = ($item['type'] ?? null) === 'configurable';

            if (! $soloCantidades && isset($item['product_id'])) {
                $productos[(int) $item['product_id']] ??= 0;
            }

            foreach ($hijos === [] ? [$item] : $hijos as $linea) {
                if (! isset($linea['product_id'])) {
                    continue;
                }

                $cantidad = $esConfigurable
                    ? (int) ($item['qty_ordered'] ?? 1)
                    : (int) ($linea['qty_ordered'] ?? $item['qty_ordered'] ?? 1);

                $id = (int) $linea['product_id'];
                $productos[$id] = ($productos[$id] ?? 0) + $cantidad;
            }
        }

        return $productos;
    }

    /**
     * Stock vendible por producto en el canal (Indexers\Inventory::getQuantity), leido
     * despues del bloqueo.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    protected function vendible(array $ids, int $canalId): array
    {
        $fuentes = DB::table('channel_inventory_sources')
            ->join('inventory_sources', 'inventory_sources.id', '=', 'channel_inventory_sources.inventory_source_id')
            ->where('channel_inventory_sources.channel_id', $canalId)
            ->where('inventory_sources.status', 1)
            ->pluck('inventory_sources.id');

        $fisico = DB::table('product_inventories')
            ->whereIn('product_id', $ids)
            ->whereIn('inventory_source_id', $fuentes)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty) AS qty')
            ->pluck('qty', 'product_id');

        $reservado = DB::table('product_ordered_inventories')
            ->whereIn('product_id', $ids)
            ->where('channel_id', $canalId)
            ->pluck('qty', 'product_id');

        $vendible = [];

        foreach ($ids as $id) {
            $vendible[$id] = (int) ($fisico[$id] ?? 0) - (int) ($reservado[$id] ?? 0);
        }

        return $vendible;
    }
}
