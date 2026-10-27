<?php

namespace ByLopez\VentasAsesor\Providers;

use ByLopez\VentasAsesor\Listeners\AtribuirPedidoCreado;
use ByLopez\VentasAsesor\Listeners\CapturarCostoAlCrearPedido;
use ByLopez\VentasAsesor\Listeners\CercoCreacionPedido;
use ByLopez\VentasAsesor\Listeners\ClasificarOperacionPedido;
use ByLopez\VentasAsesor\Listeners\ComunicarDomiciliarioALogistica;
use ByLopez\VentasAsesor\Listeners\EnlazarPedidoConBorrador;
use ByLopez\VentasAsesor\Listeners\FijarContextoPrecioEnRutasDelPanel;
use ByLopez\VentasAsesor\Listeners\MostrarAntecedentesEnviosEnFichaCliente;
use ByLopez\VentasAsesor\Listeners\MostrarCarteraEnFichaCliente;
use ByLopez\VentasAsesor\Listeners\MostrarTipoOperacionEnPedido;
use ByLopez\VentasAsesor\Listeners\RegistrarRentabilidadYComision;
use ByLopez\VentasAsesor\Listeners\RegistrarSnapshotGrupoPedido;
use ByLopez\VentasAsesor\Listeners\SolicitarReevaluacionComision;
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
        /*
         * 03F. Laravel no tiene prioridades: el orden es el de registro, y este proveedor se
         * registra desde register() del modulo, antes que los del core. Una prueba afirma que:
         * - el cerco es el PRIMER listener de save.before (token D-55 + inventario D-54; debe
         *   ir antes de cualquier lectura no bloqueante de la transaccion del pedido);
         * - el enlace borrador → pedido es el PRIMERO de save.after, antes de la atribucion,
         *   los correos, la factura y el reindexado.
         */
        Event::listen(CercoCreacionPedido::EVENTO, CercoCreacionPedido::class.'@handle');
        Event::listen(EnlazarPedidoConBorrador::EVENTO, EnlazarPedidoConBorrador::class.'@handle');

        // G-11 (COM-D-15): clasificacion comercial del pedido de Nueva venta, en la misma transaccion y tras el enlace.
        Event::listen(ClasificarOperacionPedido::EVENTO, ClasificarOperacionPedido::class.'@handle');

        // GRP-01: snapshot durable del grupo (original / efectivo / override) del pedido y cierre del historial de grupo de la venta, en la misma transaccion.
        Event::listen(RegistrarSnapshotGrupoPedido::EVENTO, RegistrarSnapshotGrupoPedido::class.'@handle');

        Event::listen(AtribuirPedidoCreado::EVENTO, AtribuirPedidoCreado::class.'@handle');

        // Cierre F1 (D1): domiciliario elegido en la venta → Logistica, en la misma transaccion del pedido. Va DESPUES de enlace, clasificacion y atribucion (03F-45 fija ese orden); solo necesita el pedido y sus direcciones.
        Event::listen(ComunicarDomiciliarioALogistica::EVENTO, ComunicarDomiciliarioALogistica::class.'@handle');

        // COM-01: snapshot del costo de cada linea, con el pedido y sus items ya creados (nunca bloquea la venta).
        Event::listen(CapturarCostoAlCrearPedido::EVENTO, CapturarCostoAlCrearPedido::class.'@handle');

        // R2/R3 (Doc. 23): snapshot de rentabilidad y lineas de comision estimadas. DESPUES del costo y de la atribucion (los lee).
        Event::listen(RegistrarRentabilidadYComision::EVENTO, RegistrarRentabilidadYComision::class.'@handle');

        // Doc. 23 §10.2: hechos reales (entrega, cobro, dinero recibido, cancelacion, reembolso, atribucion) → bandeja de
        // reevaluacion de comisiones. Solo marca dentro de la transaccion del hecho; el calculo corre tras el commit.
        SolicitarReevaluacionComision::registrar();

        // PAG-D: tarjeta «Pago / Cartera» en la ficha nativa del cliente (hook propio de Bagisto, sin tocar Webkul).
        Event::listen(MostrarCarteraEnFichaCliente::EVENTO, MostrarCarteraEnFichaCliente::class.'@handle');

        // Clientes historicos: «Historial reportado por 99 Envios» en la misma ficha (solo lectura, informativo).
        Event::listen(MostrarAntecedentesEnviosEnFichaCliente::EVENTO, MostrarAntecedentesEnviosEnFichaCliente::class.'@handle');

        // G-11: tipo de operacion (y advertencia de reposicion/garantia con total > 0) en la vista nativa del pedido.
        Event::listen(MostrarTipoOperacionEnPedido::EVENTO, MostrarTipoOperacionEnPedido::class.'@handle');

        // RT-03B: adaptador TEMPORAL del panel actual de Bagisto. Declara el contexto de
        // precio al resolver la ruta y lo retira al terminar la peticion.
        Event::listen(RouteMatched::class, FijarContextoPrecioEnRutasDelPanel::class.'@handle');
        Event::listen(RequestHandled::class, FijarContextoPrecioEnRutasDelPanel::class.'@limpiar');

        // RT-03B: al nacer un carrito, un contexto de CREACION (reorder) pasa a normal.
        Event::listen('eloquent.created: '.CartProxy::modelClass(), FijarContextoPrecioEnRutasDelPanel::class.'@carritoCreado');
    }
}
