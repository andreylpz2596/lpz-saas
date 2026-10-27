<?php

namespace ByLopez\Logistica\Carriers;

use ByLopez\Logistica\Contracts\DomiciliarioElegidoEnCarrito;
use ByLopez\Logistica\Services\Tarifas\TarifaDomicilioLocal;
use Webkul\Checkout\Facades\Cart;

/**
 * Domicilio local (D-40). En una ZONA LOCAL configurada (Bogota, Soacha...) el valor es la tarifa F1 del domiciliario
 * elegido en la venta en esa zona (`bylopez_lg_tarifas`): sin domiciliario o sin tarifa el metodo NO se ofrece, nunca se
 * usa un valor generico. Si el destino no es local para el ORIGEN (zonas locales por origen) el metodo tampoco se ofrece.
 */
class DomicilioLocal extends EntregaADestino
{
    protected $code = 'domicilio_local';

    protected $method = 'domicilio_local';

    protected function valorTarifa(): mixed
    {
        $carrito = Cart::getCart();
        $zona = app(TarifaDomicilioLocal::class)->zonaDeDireccion($carrito?->shipping_address);

        // Destino NO local para el origen (ZonaLocalDeOrigen): no se ofrece. Nunca se cae al default_rate del panel.
        if ($zona === null) {
            return null;
        }

        $transportadoraId = $carrito->id ? app(DomiciliarioElegidoEnCarrito::class)->transportadoraId((int) $carrito->id) : null;

        return app(TarifaDomicilioLocal::class)->tarifa($transportadoraId, $zona);
    }
}
