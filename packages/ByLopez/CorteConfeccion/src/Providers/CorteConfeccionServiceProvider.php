<?php

namespace ByLopez\CorteConfeccion\Providers;

use ByLopez\CorteConfeccion\Comprobantes\ComprobanteLector;
use ByLopez\CorteConfeccion\Comprobantes\FuenteOperacionesExistentes;
use ByLopez\CorteConfeccion\Comprobantes\LectorClaudeVision;
use ByLopez\CorteConfeccion\Comprobantes\LectorNoDisponible;
use ByLopez\CorteConfeccion\Comprobantes\LectorTesseract;
use ByLopez\CorteConfeccion\Console\Commands\DiagnosticarCatalogoCorte;
use ByLopez\CorteConfeccion\Services\CarteraFuenteOperaciones;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Core\Http\Middleware\PreventRequestsDuringMaintenance;

class CorteConfeccionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/menu.php', 'menu.admin');
        $this->mergeConfigFrom(__DIR__.'/../Config/acl.php', 'acl');
        $this->mergeConfigFrom(__DIR__.'/../Config/comprobantes.php', 'corteconfeccion.comprobantes');

        // Lectura asistida de comprobantes: sin credenciales o sin activar, el lector responde «no disponible».
        $this->app->bind(ComprobanteLector::class, function () {
            $config = (array) config('corteconfeccion.comprobantes');
            $lector = match ($config['lector'] ?? 'tesseract') {
                'anthropic' => new LectorClaudeVision($config),
                'tesseract' => new LectorTesseract($config),
                default => new LectorNoDisponible,
            };

            return $lector->disponible() ? $lector : new LectorNoDisponible;
        });
        $this->app->bind(FuenteOperacionesExistentes::class, CarteraFuenteOperaciones::class);
    }

    public function boot(): void
    {
        Route::middleware(['web', PreventRequestsDuringMaintenance::class])->group(__DIR__.'/../Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'corteconfeccion');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'corteconfeccion');
        Blade::anonymousComponentPath(__DIR__.'/../Resources/views/components', 'corteconfeccion');

        // Avisos del modulo en la campana y el listado de notificaciones NATIVOS de Bagisto (puntos de extension oficiales).
        Event::listen('bagisto.admin.layout.vue-app-mount.before', fn ($manager) => $manager->addTemplate('corteconfeccion::admin.notificaciones.campana'));
        Event::listen('bagisto.admin.marketing.notifications.create.before', fn ($manager) => $manager->addTemplate('corteconfeccion::admin.notificaciones.listado'));

        if ($this->app->runningInConsole()) {
            $this->commands([DiagnosticarCatalogoCorte::class]);
        }
    }
}
