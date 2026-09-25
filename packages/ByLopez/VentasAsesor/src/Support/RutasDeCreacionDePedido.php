<?php

namespace ByLopez\VentasAsesor\Support;

/**
 * Fuente UNICA de la lista de rutas que crean pedidos, verificada en la auditoria
 * de la Fase 02B0 [D-§16.2]. Codigo y pruebas leen de aqui: si alguien agrega un
 * flujo nuevo, tiene que tocar este archivo a proposito.
 *
 * La comparacion es siempre por nombre EXACTO. Nunca por prefijo: `admin.*`
 * abarcaria pantallas futuras del panel y `shop.*` rutas que no crean pedidos.
 * El nombre ademas sobrevive a cambios de APP_ADMIN_URL.
 */
final class RutasDeCreacionDePedido
{
    /** Unica ruta que crea pedidos desde el panel administrativo. */
    public const ADMINISTRATIVAS = [
        'admin.sales.orders.store',
    ];

    /** Checkout de la tienda y retornos de pasarela que crean el pedido. */
    public const TIENDA = [
        'shop.checkout.onepage.orders.store',
        'stripe.payment.success',
        'payu.success',
        'razorpay.payment.success',
        'paypal.standard.success',
        'paypal.smart-button.capture-order',
        'phonepe.callback',
        'phonepe.webhook',
    ];

    public static function esAdministrativa(?string $ruta): bool
    {
        return $ruta !== null && in_array($ruta, self::ADMINISTRATIVAS, true);
    }

    public static function esDeTienda(?string $ruta): bool
    {
        return $ruta !== null && in_array($ruta, self::TIENDA, true);
    }

    public static function esConocida(?string $ruta): bool
    {
        return self::esAdministrativa($ruta) || self::esDeTienda($ruta);
    }

    /** @return list<string> */
    public static function todas(): array
    {
        return array_merge(self::ADMINISTRATIVAS, self::TIENDA);
    }
}
