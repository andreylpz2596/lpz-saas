<?php

namespace ByLopez\VentasAsesor\Listeners;

use ByLopez\VentasAsesor\Support\ContextoPrecioComercial;
use ByLopez\VentasAsesor\Support\RutasDePrecioDelPanel;
use Illuminate\Routing\Events\RouteMatched;
use Webkul\Checkout\Models\CartItemProxy;
use Webkul\Checkout\Models\CartProxy;
use Webkul\Customer\Models\CustomerProxy;
use Webkul\Sales\Models\OrderProxy;

/**
 * ADAPTADOR TEMPORAL (RT-03B) para el panel actual de Bagisto.
 *
 * Los controladores del core no pueden envolverse en ContextoPrecioComercial::durante()
 * sin modificar Webkul, y no existe ningun evento antes de que collectTotals()
 * reprecie los items. RouteMatched ocurre antes del controlador: aqui se declara el
 * contexto de la PETICION con el carrito que la propia URL identifica.
 *
 * No es la arquitectura de ventas: el futuro CrearPedidoComercialService (y API,
 * POS, n8n, WhatsApp) usara durante() directamente, sin rutas. Cuando el panel de
 * Bagisto deje de usarse para ventas comerciales, este adaptador se retira.
 *
 * Ciclo de vida: cada peticion enrutada empieza limpia (olvidarPeticion) y el
 * contexto se retira tambien en RequestHandled. Asi no sobrevive a su peticion ni
 * cuando la aplicacion se reutiliza (pruebas, Octane).
 */
class FijarContextoPrecioEnRutasDelPanel
{
    public function handle(RouteMatched $evento): void
    {
        $contexto = app(ContextoPrecioComercial::class);

        $contexto->olvidarPeticion();

        $ruta = $evento->route->getName();

        if (! RutasDePrecioDelPanel::incluye($ruta)) {
            return;
        }

        if (in_array($ruta, RutasDePrecioDelPanel::CREACION_DESDE_PEDIDO, true)) {
            $this->fijarCreacionDesdePedido($contexto, (int) $evento->route->parameter('id'));

            return;
        }

        [$cartId, $customerId] = $this->identificar($ruta, $evento);

        if ($cartId === null) {
            /*
             * No hay carrito existente que preciar (id inexistente o item invalido):
             * el controlador respondera 404/422 sin tocar precios. Nada que proteger.
             */
            return;
        }

        // Fail closed: carrito sin cliente o de otro cliente lanza 409 antes del controlador.
        $contexto->fijarParaPeticion($cartId, $customerId ?? 0);
    }

    /**
     * Volver a pedir: el cliente sale del PEDIDO (nunca de la sesion). Si el pedido no
     * existe o no tiene un cliente registrado vigente, Bagisto no crea carrito (404 o
     * aviso "cliente no encontrado"), asi que no hay precio que proteger.
     */
    protected function fijarCreacionDesdePedido(ContextoPrecioComercial $contexto, int $orderId): void
    {
        $pedido = OrderProxy::modelClass()::query()->select(['id', 'customer_id', 'customer_type'])->find($orderId);

        if (! $pedido || ! $pedido->customer_id) {
            return;
        }

        $modeloCliente = CustomerProxy::modelClass();

        if (! is_a((string) $pedido->customer_type, $modeloCliente, true)
            || ! $modeloCliente::query()->whereKey($pedido->customer_id)->exists()
        ) {
            return;
        }

        $contexto->fijarCreacionParaPeticion((int) $pedido->customer_id);
    }

    /** Carrito recien creado: si hay un contexto de creacion vigente, pasa a normal. */
    public function carritoCreado(object $carrito): void
    {
        app(ContextoPrecioComercial::class)->carritoCreado(
            (int) $carrito->id,
            $carrito->customer_id === null ? null : (int) $carrito->customer_id,
        );
    }

    public function limpiar(): void
    {
        app(ContextoPrecioComercial::class)->olvidarPeticion();
    }

    /** @return array{0: ?int, 1: ?int} cart_id y customer_id declarados por la peticion */
    protected function identificar(string $ruta, RouteMatched $evento): array
    {
        if (in_array($ruta, RutasDePrecioDelPanel::CARRITO_DE_CLIENTE_POR_ITEM, true)) {
            $item = CartItemProxy::modelClass()::query()->select(['id', 'cart_id'])
                ->find((int) $evento->request->input('item_id'));

            // El cliente lo declara la URL; si el carrito es de otro, validar() lo rechaza.
            return [$item?->cart_id ? (int) $item->cart_id : null, (int) $evento->route->parameter('id')];
        }

        $parametro = in_array($ruta, RutasDePrecioDelPanel::CARRITO_POR_PARAMETRO_CART_ID, true) ? 'cartId' : 'id';

        $carrito = CartProxy::modelClass()::query()->select(['id', 'customer_id'])
            ->find((int) $evento->route->parameter($parametro));

        if (! $carrito) {
            return [null, null];
        }

        return [(int) $carrito->id, $carrito->customer_id === null ? null : (int) $carrito->customer_id];
    }
}
