<?php

namespace ByLopez\Cargues\Providers;

use ByLopez\Cargues\Console\DiagnosticoColaCommand;
use ByLopez\Cargues\Console\ProteccionValidarCommand;
use ByLopez\Cargues\Console\RecuperarCargaCommand;
use ByLopez\Cargues\Http\Middleware\SoloAdministrador;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Webkul\Core\Http\Middleware\PreventRequestsDuringMaintenance;

/**
 * Motor de cargues masivos parametrizable (E1). Solo administrador: sin ACL (una clave ACL se podria asignar a roles
 * personalizados) y sin la entrada de menu estatica (los roles `all` la verian). Sin cambios en Webkul.
 */
class CarguesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/cargues.php', 'cargues');

        $this->registrarConexionDePruebas();

        // Cola DEDICADA: solo agrega la conexion `cargues`. No toca `queue.default` ni las demas conexiones.
        config()->set('queue.connections.cargues', array_merge([
            'driver' => 'database',
            'connection' => env('CARGUES_QUEUE_DB_CONNECTION'),
            'table' => 'jobs',
            'queue' => config('cargues.cola.nombre', 'cargues'),
            'retry_after' => config('cargues.cola.retry_after', 1500),
            'after_commit' => false,
        ], config()->get('queue.connections.cargues', [])));
    }

    /**
     * SOLO para las pruebas automatizadas: en `testing`, con CARGUES_PRUEBAS_AISLADAS=1 y una base indicada en
     * CARGUES_TEST_TIPO_DB, define la conexion `cargues_tipo_prueba` (misma instancia aislada, otra base) y la autoriza.
     * Hace falta aqui porque un worker de cola es otro proceso y no hereda la configuracion que fija una prueba.
     * Nunca se activa fuera de `testing`.
     */
    protected function registrarConexionDePruebas(): void
    {
        if (! $this->app->environment('testing') || env('CARGUES_PRUEBAS_AISLADAS') !== '1' || ! env('CARGUES_TEST_TIPO_DB')) {
            return;
        }

        $base = (array) config()->get('database.connections.'.config()->get('database.default'));
        config()->set('database.connections.cargues_tipo_prueba', array_merge($base, ['database' => (string) env('CARGUES_TEST_TIPO_DB')]));
        config()->set('cargues.conexiones_autorizadas', ['cargues_tipo_prueba']);
        // Las pruebas anteriores a la proteccion usan la conexion del tipo (root del aislado). ProteccionValidarTest la exige.
        config()->set('cargues.validar.exigir_cuenta_restringida', filter_var(env('CARGUES_VALIDAR_EXIGIR_CUENTA', false), FILTER_VALIDATE_BOOLEAN));
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).'/Resources/lang', 'cargues');

        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');

        $this->loadViewsFrom(dirname(__DIR__).'/Resources/views', 'cargues');

        Route::middleware(['web', PreventRequestsDuringMaintenance::class])->group(dirname(__DIR__).'/Http/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([DiagnosticoColaCommand::class, ProteccionValidarCommand::class, RecuperarCargaCommand::class]);
        }

        // Menu solo para el rol administrador. Se decide por request y ANTES de que el layout construya el menu: la
        // clase Menu arma sus items una sola vez, asi que el compositor va en el layout (el primero en renderizarse).
        View::composer('admin::components.layouts.index', function () {
            $existentes = collect((array) config()->get('menu.admin', []))->pluck('key')->all();

            if (in_array('cargues', $existentes, true) || ! SoloAdministrador::esAdministrador(auth()->guard('admin')->user())) {
                return;
            }

            config()->set('menu.admin', array_merge(
                (array) config()->get('menu.admin', []),
                require dirname(__DIR__).'/Config/menu.php',
            ));
        });
    }
}
