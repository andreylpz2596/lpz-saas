<?php

namespace ByLopez\VentasAsesor\Listeners;

/**
 * F5 (auditoria 2026-10-21; regla aprobada por Camilo Gaitan el 2026-10-26): un pedido CONFIRMADO compromete sus unidades.
 *
 * Causa raiz (Doc. 05 §39.4): Bagisto 2.4.9 crea la linea hija de un configurable SIN `quantity`
 * (Configurable::prepareForCart) y la columna `cart_items.quantity` vale 0 por defecto. OrderItemResource calcula el
 * `qty_ordered` del hijo como `quantity × padre` = 0, asi que OrderItemRepository::manageInventory reserva 0 para la variante:
 * el pedido no descontaba nada del vendible hasta el despacho y otra venta podia prometer las mismas unidades.
 *
 * Correccion (sin tocar Webkul y sin una segunda reserva): la hija del configurable lleva cantidad 1, que es la RAZON
 * hijo/padre que el propio Bagisto espera (con `null` el resource ya usaria 1). Con eso el mecanismo NATIVO queda correcto
 * de punta a punta:
 *  - manageInventory reserva `qty_ordered` del hijo = cantidad pedida, en `product_ordered_inventories` (variante y canal);
 *  - el cerco D-54 (CercoCreacionPedido) ya serializa y revalida el vendible con esa reserva dentro de la transaccion;
 *  - ShipmentRepository descuenta `(hijo/padre) × despachado` = lo despachado de la reserva y del fisico (sin doble descuento,
 *    y en un despacho parcial la reserva del pendiente se conserva);
 *  - la cancelacion nativa libera `qty_ordered − qty_shipped` de la reserva (solo lo pendiente).
 *
 * Se normaliza en `checkout.cart.collect.totals.before`: Bagisto recalcula los totales despues de agregar cualquier producto y
 * justo antes de crear el pedido (tienda, panel y Nueva venta), asi que tambien corrige los carritos que ya existian. La
 * cantidad de la hija no interviene en precios ni totales (Cart solo suma las lineas padre).
 *
 * Pedidos ANTERIORES (hijo con qty_ordered = 0): no se reinterpretan ni se rellenan (sin backfill); ver CercoInventario y
 * ProtegerReservasEnDespachoLegado para la proteccion de la transicion.
 */
class CantidadVarianteConfigurable
{
    public const EVENTO = 'checkout.cart.collect.totals.before';

    public function handle($cart): void
    {
        if ($cart === null) {
            return;
        }

        foreach ($cart->items as $item) {
            if ($item->type !== 'configurable') {
                continue;
            }

            foreach ($item->children as $hija) {
                if (! (int) $hija->quantity) {
                    $hija->quantity = 1;
                    $hija->save();
                }
            }

            if ($item->relationLoaded('child') && $item->child && ! (int) $item->child->quantity) {
                $item->child->quantity = 1;
            }
        }
    }
}
