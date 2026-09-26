<?php

namespace ByLopez\VentasAsesor\Providers;

use ByLopez\VentasAsesor\Console\Commands\ReconciliarAtribucionesCommand;
use ByLopez\VentasAsesor\Support\ClasificadorOrigenPedido;
use ByLopez\VentasAsesor\Support\ContextoComercialDePeticion;
use ByLopez\VentasAsesor\Support\ContextoPrecioComercial;
use ByLopez\VentasAsesor\Support\CustomerRepositoryConContextoComercial;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Core\Http\Middleware\PreventRequestsDuringMaintenance;
use Webkul\Customer\Repositories\CustomerRepository;

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

        /*
         * RT-03B. Contexto de precio comercial por peticion (scoped, nunca singleton) y
         * CustomerRepository que lo respeta. Sin contexto explicito, el repositorio
         * delega en el core: la tienda y cualquier flujo sin contexto no cambian.
         */
        $this->app->scoped(ContextoPrecioComercial::class);

        $this->app->bind(CustomerRepository::class, CustomerRepositoryConContextoComercial::class);
    }

    private function comandos(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ReconciliarAtribucionesCommand::class]);
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
