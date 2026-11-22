<?php

namespace ByLopez\VentasAsesor\Providers;

use Bagisto\Pickup\Listeners\Shipment as PickupShipmentListener;
use ByLopez\Logistica\Contracts\AccesoGuiaPedido;
use ByLopez\Logistica\Contracts\DomiciliarioElegidoEnCarrito;
use ByLopez\Logistica\Contracts\PagosPendientesDeConfirmar;
use ByLopez\Logistica\Contracts\ResuelveMunicipio;
use ByLopez\Logistica\Contracts\SugiereRecaudo;
use ByLopez\VentasAsesor\Console\Commands\DiagnosticoCostosVentaCommand;
use ByLopez\VentasAsesor\Console\Commands\EvaluarComisionesCommand;
use ByLopez\VentasAsesor\Console\Commands\GeoImportarDivipolaCommand;
use ByLopez\VentasAsesor\Console\Commands\IndexarTelefonosClientesCommand;
use ByLopez\VentasAsesor\Console\Commands\NormalizarSkuCommand;
use ByLopez\VentasAsesor\Console\Commands\ProgramarReglaComisionCommand;
use ByLopez\VentasAsesor\Console\Commands\ReconciliarAtribucionesCommand;
use ByLopez\VentasAsesor\Contracts\CalendarioLaboral;
use ByLopez\VentasAsesor\Contracts\NormalizadorTelefono;
use ByLopez\VentasAsesor\Listeners\Correo\CustomerConDestinatarioVerificado;
use ByLopez\VentasAsesor\Listeners\Correo\InvoiceConDestinatarioVerificado;
use ByLopez\VentasAsesor\Listeners\Correo\OrderConDestinatarioVerificado;
use ByLopez\VentasAsesor\Listeners\Correo\PickupShipmentConDestinatarioVerificado;
use ByLopez\VentasAsesor\Listeners\Correo\RefundConDestinatarioVerificado;
use ByLopez\VentasAsesor\Listeners\Correo\ShipmentConDestinatarioVerificado;
use ByLopez\VentasAsesor\Services\Comisiones\ReevaluacionComisionesService;
use ByLopez\VentasAsesor\Support\Calendario\CalendarioLaboralConfigurable;
use ByLopez\VentasAsesor\Support\ClasificadorOrigenPedido;
use ByLopez\VentasAsesor\Support\ContextoComercialDePeticion;
use ByLopez\VentasAsesor\Support\ContextoPrecioComercial;
use ByLopez\VentasAsesor\Support\CustomerRepositoryConContextoComercial;
use ByLopez\VentasAsesor\Support\Logistica\AccesoGuiaDelAsesor;
use ByLopez\VentasAsesor\Support\Logistica\DomiciliarioDesdeBorrador;
use ByLopez\VentasAsesor\Support\Logistica\MunicipioDesdeCatalogo;
use ByLopez\VentasAsesor\Support\Logistica\PagosPendientesDesdeCartera;
use ByLopez\VentasAsesor\Support\Logistica\SugiereRecaudoDesdeCartera;
use ByLopez\VentasAsesor\Support\Telefono\NormalizadorTelefonoBasico;
use ByLopez\VentasAsesor\Support\Venta\ConfirmacionEnCurso;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Core\Http\Middleware\PreventRequestsDuringMaintenance;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Shop\Listeners\Customer as ShopCustomerListener;
use Webkul\Shop\Listeners\Invoice as ShopInvoiceListener;
use Webkul\Shop\Listeners\Order as ShopOrderListener;
use Webkul\Shop\Listeners\Refund as ShopRefundListener;
use Webkul\Shop\Listeners\Shipment as ShopShipmentListener;

class VentasAsesorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/menu.php', 'menu.admin');
        $this->mergeConfigFrom(__DIR__.'/../Config/acl.php', 'acl');
        // Configuracion > Ventas Asesor > Cotizaciones (datos de pago, condiciones y vigencia editables por el administrador).
        $this->mergeConfigFrom(__DIR__.'/../Config/system.php', 'core');

        $this->app->register(EventServiceProvider::class);

        /*
         * `scoped`, nunca `singleton`. El contenedor descarta estas instancias al
         * terminar cada peticion, tambien bajo Octane, donde un singleton seguiria
         * vivo entre peticiones y filtraria el contexto comercial de un cliente al
         * pedido del siguiente.
         */
        $this->app->scoped(ContextoComercialDePeticion::class);

        $this->app->scoped(ClasificadorOrigenPedido::class);

        // 03F. Intento de confirmacion en curso (cart_id → borrador + token), mismo criterio.
        $this->app->scoped(ConfirmacionEnCurso::class);

        /*
         * RT-03B. Contexto de precio comercial por peticion (scoped, nunca singleton) y
         * CustomerRepository que lo respeta. Sin contexto explicito, el repositorio
         * delega en el core: la tienda y cualquier flujo sin contexto no cambian.
         */
        $this->app->scoped(ContextoPrecioComercial::class);

        // Doc. 23 §10.2: bandeja de reevaluacion de comisiones. Guarda los pedidos marcados en la peticion (o job) para
        // despacharlos tras el commit: scoped, por el mismo motivo.
        $this->app->scoped(ReevaluacionComisionesService::class);

        $this->app->bind(CustomerRepository::class, CustomerRepositoryConContextoComercial::class);

        /*
         * RT-04B1a. Normalizacion de telefonos detras de un contrato: una futura
         * implementacion con libphonenumber se enlaza aqui sin tocar consumidores.
         */
        $this->mergeConfigFrom(__DIR__.'/../Config/ventasasesor.php', 'ventasasesor');

        $this->app->bind(NormalizadorTelefono::class, NormalizadorTelefonoBasico::class);

        // Fase 03B. Calendario laboral por configuracion (sin festivos en el codigo).
        $this->app->bind(CalendarioLaboral::class, CalendarioLaboralConfigurable::class);

        // Despacho V1. Logistica define los contratos con una implementacion por defecto; aqui se reemplazan por las que
        // conocen Cartera (recaudo sugerido) y la propiedad comercial (el asesor ve la guia de SUS pedidos).
        $this->app->bind(SugiereRecaudo::class, SugiereRecaudoDesdeCartera::class);
        $this->app->bind(AccesoGuiaPedido::class, AccesoGuiaDelAsesor::class);
        // Mensajes al cliente (WhatsApp): el servicio real reemplaza al por defecto de Logistica (que no tiene mensajes).
        $this->app->bind(\ByLopez\Logistica\Contracts\MensajesPedidoCliente::class, \ByLopez\VentasAsesor\Services\Mensajes\MensajePedidoService::class);

        // Domicilios locales F1: municipio del pedido con el catalogo DIVIPOLA (coincidencia exacta) y pagos sin confirmar
        // desde Cartera (bloquean el despacho «pagado» hasta que Cartera los confirme o rechace). Solo lectura.
        $this->app->bind(ResuelveMunicipio::class, MunicipioDesdeCatalogo::class);
        $this->app->bind(\ByLopez\Logistica\Contracts\BuscaMunicipios::class, \ByLopez\VentasAsesor\Support\Logistica\MunicipiosDesdeCatalogo::class);
        $this->app->bind(PagosPendientesDeConfirmar::class, PagosPendientesDesdeCartera::class);
        // D10: anular una recepcion C5 no puede dejar credito consumido sin respaldo.
        $this->app->bind(\ByLopez\Logistica\Contracts\GuardaAnulacionRecepcion::class, \ByLopez\VentasAsesor\Support\Logistica\GuardaAnulacionRecepcionConCredito::class);

        // Cierre F1: el carrier de domicilio local lee el domiciliario elegido en la venta (el borrador dueno del carrito).
        $this->app->bind(DomiciliarioElegidoEnCarrito::class, DomiciliarioDesdeBorrador::class);

        $this->registrarGuardDeCorreosAlCliente();
    }

    /**
     * RT-04B1a (D-RT04-06). Los EventServiceProvider del core registran sus listeners
     * como [Clase, 'metodo'] y Laravel los instancia con container->make() en cada
     * disparo (Illuminate\Events\Dispatcher::createClassCallable). Enlazar la clase
     * del core a una subclase la sustituye sin añadir un segundo listener: con email
     * valido se ejecuta el cuerpo del core (parent::); sin destinatario se omite el
     * correo y se registra. Los listeners de Admin no se tocan: escriben al
     * administrador, no al cliente.
     */
    private function registrarGuardDeCorreosAlCliente(): void
    {
        $this->app->bind(ShopOrderListener::class, OrderConDestinatarioVerificado::class);
        $this->app->bind(ShopInvoiceListener::class, InvoiceConDestinatarioVerificado::class);
        $this->app->bind(ShopShipmentListener::class, ShipmentConDestinatarioVerificado::class);
        $this->app->bind(PickupShipmentListener::class, PickupShipmentConDestinatarioVerificado::class);
        $this->app->bind(ShopRefundListener::class, RefundConDestinatarioVerificado::class);
        $this->app->bind(ShopCustomerListener::class, CustomerConDestinatarioVerificado::class);
    }

    private function comandos(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ReconciliarAtribucionesCommand::class,
                DiagnosticoCostosVentaCommand::class,
                ProgramarReglaComisionCommand::class,
                IndexarTelefonosClientesCommand::class,
                NormalizarSkuCommand::class,
                GeoImportarDivipolaCommand::class,
                EvaluarComisionesCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        Route::middleware(['web', PreventRequestsDuringMaintenance::class])->group(__DIR__.'/../Http/routes.php');

        // Tablas propias con prefijo bylopez_va_. Ninguna migracion toca el core.
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'ventasasesor');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'ventasasesor');

        $this->comandos();

        // Doc. 23 §10.2: reintentos de la bandeja de comisiones. Solo corre si el servidor ejecuta `schedule:run` (cron).
        if ((bool) config('ventasasesor.comisiones.reevaluacion.programar', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command('ventas-asesor:comisiones:evaluar --bandeja')->everyFiveMinutes()->withoutOverlapping(30);
            });
        }
    }
}
