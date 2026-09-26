<?php

namespace ByLopez\VentasAsesor\Support;

/**
 * Lista EXACTA de rutas del panel actual de Bagisto que reprecian un carrito
 * existente (auditada en RT-03B contra el router y los controladores). La usa solo
 * el adaptador temporal FijarContextoPrecioEnRutasDelPanel; el dominio no conoce
 * rutas.
 *
 * Nunca prefijos: una ruta nueva del core queda FUERA por defecto.
 *
 * Fuera a proposito:
 *  - admin.sales.cart.index / admin.sales.orders.create (GET): no reprecian.
 *  - admin.sales.cart.store / admin.customers.customers.cart.store: solo crean el
 *    carrito, sin precios.
 *  - admin.customers.customers.cart.items (GET): solo lee.
 */
final class RutasDePrecioDelPanel
{
    /** Rutas cuyo parametro de ruta `id` es el carrito. */
    public const CARRITO_POR_PARAMETRO_ID = [
        'admin.sales.cart.items.store',
        'admin.sales.cart.items.update',
        'admin.sales.cart.items.destroy',
        'admin.sales.cart.addresses.store',
        'admin.sales.cart.shipping_methods.store',
        'admin.sales.cart.payment_methods.store',
        'admin.sales.cart.store_coupon',
        'admin.sales.cart.remove_coupon',
    ];

    /** Confirmacion del pedido: parametro `cartId`; hace collectTotals antes de crear el pedido. */
    public const CARRITO_POR_PARAMETRO_CART_ID = [
        'admin.sales.orders.store',
    ];

    /** Carrito del cliente desde el admin: parametro `id` = cliente, carrito = el del item a borrar. */
    public const CARRITO_DE_CLIENTE_POR_ITEM = [
        'admin.customers.customers.cart.items.delete',
    ];

    /**
     * Volver a pedir: parametro `id` = pedido. El carrito aun no existe (lo crea el
     * controlador), asi que se declara un contexto de CREACION con el cliente del pedido.
     */
    public const CREACION_DESDE_PEDIDO = [
        'admin.sales.orders.reorder',
    ];

    public static function incluye(?string $ruta): bool
    {
        return $ruta !== null && in_array($ruta, self::todas(), true);
    }

    /** @return list<string> */
    public static function todas(): array
    {
        return array_merge(self::CARRITO_POR_PARAMETRO_ID, self::CARRITO_POR_PARAMETRO_CART_ID, self::CARRITO_DE_CLIENTE_POR_ITEM, self::CREACION_DESDE_PEDIDO);
    }
}
