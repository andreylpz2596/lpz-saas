<?php

namespace ByLopez\VentasAsesor\Providers;

use ByLopez\VentasAsesor\Console\Commands\IndexarTelefonosClientesCommand;
use ByLopez\VentasAsesor\Console\Commands\ReconciliarAtribucionesCommand;
use ByLopez\VentasAsesor\Contracts\CalendarioLaboral;
use ByLopez\VentasAsesor\Contracts\NormalizadorTelefono;
use ByLopez\VentasAsesor\Listeners\Correo\CustomerConDestinatarioVerificado;
use ByLopez\VentasAsesor\Listeners\Correo\InvoiceConDestinatarioVerificado;
use ByLopez\VentasAsesor\Listeners\Correo\OrderConDestinatarioVerificado;
use ByLopez\VentasAsesor\Listeners\Correo\RefundConDestinatarioVerificado;
use ByLopez\VentasAsesor\Listeners\Correo\ShipmentConDestinatarioVerificado;
use ByLopez\VentasAsesor\Support\Calendario\CalendarioLaboralConfigurable;
use ByLopez\VentasAsesor\Support\ClasificadorOrigenPedido;
use ByLopez\VentasAsesor\Support\ContextoComercialDePeticion;
use ByLopez\VentasAsesor\Support\ContextoPrecioComercial;
use ByLopez\VentasAsesor\Support\CustomerRepositoryConContextoComercial;
use ByLopez\VentasAsesor\Support\Telefono\NormalizadorTelefonoBasico;
use ByLopez\VentasAsesor\Support\Venta\ConfirmacionEnCurso;
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

        $this->app->bind(CustomerRepository::class, CustomerRepositoryConContextoComercial::class);

        /*
         * RT-04B1a. Normalizacion de telefonos detras de un contrato: una futura
         * implementacion con libphonenumber se enlaza aqui sin tocar consumidores.
         */
        $this->mergeConfigFrom(__DIR__.'/../Config/ventasasesor.php', 'ventasasesor');

        $this->app->bind(NormalizadorTelefono::class, NormalizadorTelefonoBasico::class);

        // Fase 03B. Calendario laboral por configuracion (sin festivos en el codigo).
        $this->app->bind(CalendarioLaboral::class, CalendarioLaboralConfigurable::class);

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
        $this->app->bind(ShopRefundListener::class, RefundConDestinatarioVerificado::class);
        $this->app->bind(ShopCustomerListener::class, CustomerConDestinatarioVerificado::class);
    }

    private function comandos(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ReconciliarAtribucionesCommand::class,
                IndexarTelefonosClientesCommand::class,
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
    }
}
