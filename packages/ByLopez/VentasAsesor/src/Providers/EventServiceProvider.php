<?php

namespace ByLopez\VentasAsesor\Providers;

use ByLopez\VentasAsesor\Listeners\AtribuirPedidoCreado;
use ByLopez\VentasAsesor\Listeners\FijarContextoPrecioEnRutasDelPanel;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Webkul\Checkout\Models\CartProxy;

/**
 * Registro de eventos del modulo, con el mismo patron que usan los paquetes del
 * core (Webkul\Notification, Webkul\Payment): un proveedor propio de eventos,
 * registrado desde el proveedor principal.
 *
 * Queda registrado EXACTAMENTE una vez: Laravel descarta el registro repetido de
 * un mismo proveedor, asi que no hay riesgo de atribuir dos veces el mismo pedido
 * aunque el proveedor principal se cargue por varias vias.
 */
class EventServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(AtribuirPedidoCreado::EVENTO, AtribuirPedidoCreado::class.'@handle');

        // RT-03B: adaptador TEMPORAL del panel actual de Bagisto. Declara el contexto de
        // precio al resolver la ruta y lo retira al terminar la peticion.
        Event::listen(RouteMatched::class, FijarContextoPrecioEnRutasDelPanel::class.'@handle');
        Event::listen(RequestHandled::class, FijarContextoPrecioEnRutasDelPanel::class.'@limpiar');

        // RT-03B: al nacer un carrito, un contexto de CREACION (reorder) pasa a normal.
        Event::listen('eloquent.created: '.CartProxy::modelClass(), FijarContextoPrecioEnRutasDelPanel::class.'@carritoCreado');
    }
}
