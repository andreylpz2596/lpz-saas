<?php

namespace ByLopez\Logistica\Support;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\ProductProxy;

/**
 * SKU FISICO de una linea de pedido: el propio producto (simple) o la VARIANTE hija (configurable), que es lo que el
 * Shipment descuenta del inventario. Una linea compuesta con varios hijos no se soporta (no existe en el catalogo actual).
 */
class ProductoFisicoDeLinea
{
    /** @return array{id: int, sku: ?string, gestiona_stock: bool} */
    public function deLineaId(int $orderItemId): array
    {
        $linea = DB::table('order_items')->where('id', $orderItemId)->first(['id', 'product_id', 'sku', 'type'])
            ?? throw new Invalida(Invalida::LINEA_NO_SOPORTADA, ['sku' => '#'.$orderItemId]);

        $hijos = DB::table('order_items')->where('parent_id', $linea->id)->whereNotNull('product_id')->get(['product_id', 'sku']);

        if ($hijos->isEmpty() && $linea->type !== 'configurable') {
            return ['id' => (int) $linea->product_id, 'sku' => $linea->sku, 'gestiona_stock' => $this->gestionaStock((int) $linea->product_id)];
        }

        if ($hijos->count() !== 1) {
            throw new Invalida(Invalida::LINEA_NO_SOPORTADA, ['sku' => $linea->sku]);
        }

        $hijo = $hijos->first();

        return ['id' => (int) $hijo->product_id, 'sku' => $hijo->sku, 'gestiona_stock' => $this->gestionaStock((int) $hijo->product_id)];
    }

    /** `manage_stock` es un atributo (EAV) del producto en Bagisto: se lee como lo hace el Shipment al descontar. */
    private function gestionaStock(int $productId): bool
    {
        return (bool) ProductProxy::modelClass()::query()->find($productId)?->manage_stock;
    }
}
