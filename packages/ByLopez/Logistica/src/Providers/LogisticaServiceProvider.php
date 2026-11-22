<?php

namespace ByLopez\Logistica\Providers;

use ByLopez\Logistica\Console\SincronizarCuentasTransportadora;
use ByLopez\Logistica\Contracts\AccesoGuiaPedido;
use ByLopez\Logistica\Contracts\AjustaInventario;
use ByLopez\Logistica\Contracts\DomiciliarioDeVenta;
use ByLopez\Logistica\Contracts\DomiciliarioElegidoEnCarrito;
use ByLopez\Logistica\Contracts\GestionaCobroPedido;
use ByLopez\Logistica\Contracts\PagosPendientesDeConfirmar;
use ByLopez\Logistica\Contracts\ReingresaInventario;
use ByLopez\Logistica\Contracts\ResuelveMunicipio;
use ByLopez\Logistica\Contracts\SugiereRecaudo;
use ByLopez\Logistica\Listeners\AsignarCodigoTransportadoraAlDespacho;
use ByLopez\Logistica\Listeners\BloquearCancelacionNativaConDespachos;
use ByLopez\Logistica\Listeners\MostrarEntregasEnPedido;
use ByLopez\Logistica\Services\Despachos\DomiciliarioDeVentaService;
use ByLopez\Logistica\Support\AccesoGuiaPorPermiso;
use ByLopez\Logistica\Support\Cobro\CobroViaCartera;
use ByLopez\Logistica\Support\Inventario\AjusteViaCorteConfeccion;
use ByLopez\Logistica\Support\Inventario\ReingresoViaCorteConfeccion;
use ByLopez\Logistica\Support\RecaudoSugeridoBagisto;
use ByLopez\Logistica\Support\SinDomiciliarioElegido;
use ByLopez\Logistica\Support\SinPagosPendientes;
use ByLopez\Logistica\Support\SinResolucionMunicipio;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Core\Http\Middleware\PreventRequestsDuringMaintenance;

/**
 * Extensiones logisticas de ByLopez sobre Bagisto (D-45).
 *
 * - 03D: carriers de METODOS DE ENTREGA con los puntos de extension nativos `carriers` y `core` (nacen inactivos).
 * - LOG-01 (2026-10-08): ENTREGA CANONICA y RETORNO DE TRANSPORTADORA por envio y unidad, catalogo de
 *   transportadoras, ACL `logistica.*` y menu «Logistica». El despacho sigue siendo el Shipment de Bagisto; este
 *   paquete es el propietario de la entrega y VentasAsesor solo la lee (LOG-D-01). Sin cambios en Webkul.
 */
class LogisticaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/carriers.php', 'carriers');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/system.php', 'core');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/acl.php', 'acl');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/menu.php', 'menu.admin');

        // Implementaciones por defecto (solo Bagisto + permisos de Logistica). VentasAsesor las reemplaza en su register():
        // el recaudo sugerido desde Cartera y el acceso del asesor a las guias de SUS pedidos. Logistica no depende de el.
        $this->app->bind(SugiereRecaudo::class, RecaudoSugeridoBagisto::class);
        $this->app->bind(AccesoGuiaPedido::class, AccesoGuiaPorPermiso::class);
        // Mensajes al cliente (WhatsApp): sin VentasAsesor no hay mensajes; VentasAsesor enlaza el servicio real.
        $this->app->bind(\ByLopez\Logistica\Contracts\MensajesPedidoCliente::class, \ByLopez\Logistica\Support\SinMensajesPedido::class);

        // Domicilios locales F1: condicion de envio por codigo de metodo; municipio del pedido y pagos sin confirmar por
        // contrato (sin VentasAsesor: no se resuelve municipio y no hay pagos sin revisar).
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/condiciones_envio.php', 'logistica.condiciones_envio');
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/cuentas.php', 'logistica.cuentas');
        $this->app->bind(ResuelveMunicipio::class, SinResolucionMunicipio::class);
        // Zonas locales y tarifas: catalogo de municipios para habilitar destinos (VentasAsesor, dueno del catalogo, lo reemplaza).
        $this->app->bind(\ByLopez\Logistica\Contracts\BuscaMunicipios::class, \ByLopez\Logistica\Support\SinCatalogoMunicipios::class);
        $this->app->bind(PagosPendientesDeConfirmar::class, SinPagosPendientes::class);
        // D10: anular una recepcion C5 no puede dejar credito consumido sin respaldo (VentasAsesor la reemplaza).
        $this->app->bind(\ByLopez\Logistica\Contracts\GuardaAnulacionRecepcion::class, \ByLopez\Logistica\Support\SinGuardaAnulacionRecepcion::class);

        // Cierre F1 (D1): Logistica OFRECE a la venta el domiciliario elegible y guarda la eleccion con el pedido.
        $this->app->bind(DomiciliarioDeVenta::class, DomiciliarioDeVentaService::class);
        $this->app->bind(DomiciliarioElegidoEnCarrito::class, SinDomiciliarioElegido::class);

        // Regularizacion de stock para despachar: unica puerta al inventario (delega en el ajuste manual de CorteConfeccion).
        $this->app->bind(AjustaInventario::class, AjusteViaCorteConfeccion::class);

        // Reingreso de las unidades APTAS de una devolucion recibida: misma frontera, entrada auditada e idempotente.
        $this->app->bind(ReingresaInventario::class, ReingresoViaCorteConfeccion::class);

        // Cobro contra entrega: unica puerta a Cartera (registrar + confirmar por la via oficial; jamas forzar una factura).
        $this->app->bind(GestionaCobroPedido::class, CobroViaCartera::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).'/Resources/lang', 'logistica');

        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');

        $this->loadViewsFrom(dirname(__DIR__).'/Resources/views', 'logistica');

        Route::middleware(['web', PreventRequestsDuringMaintenance::class])->group(dirname(__DIR__).'/Http/routes.php');

        // Bloque 11: codigo estable de transportadora en despachos nuevos (sin tocar el formulario de Bagisto).
        Event::listen(AsignarCodigoTransportadoraAlDespacho::EVENTO, AsignarCodigoTransportadoraAlDespacho::class.'@handle');

        // La cancelacion nativa no puede reintegrar al stock fisico lo ya despachado: se corta antes de cambiar nada.
        Event::listen(BloquearCancelacionNativaConDespachos::EVENTO, BloquearCancelacionNativaConDespachos::class.'@handle');

        // Bloque 17: boton «Entregas» en la vista nativa del pedido.
        Event::listen(MostrarEntregasEnPedido::EVENTO, MostrarEntregasEnPedido::class.'@handle');

        // Cuentas corrientes de transportadoras: conciliacion explicita con los envios existentes (simula por defecto).
        if ($this->app->runningInConsole()) {
            $this->commands([SincronizarCuentasTransportadora::class]);
        }

        // Scroll bloqueado en iPhone tras cerrar drawers/modales: libera el body cuando no hay ninguna capa abierta.
        Event::listen('bagisto.admin.layout.head.after', fn ($manager) => $manager->addTemplate('logistica::admin.guardia-scroll'));
    }
}
