<?php

namespace ByLopez\VentasAsesor\Providers;

use ByLopez\VentasAsesor\Listeners\AtribuirPedidoCreado;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

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
    }
}
