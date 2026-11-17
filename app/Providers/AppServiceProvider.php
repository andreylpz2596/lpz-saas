<?php

namespace App\Providers;

use Barryvdh\Debugbar\Facades\Debugbar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // bagisto-eu/pickup declara la clase `Instore` (Payment/Instore.php) pero su configuración la nombra `InStore`.
        // Windows no distingue mayúsculas y funciona; en Linux el autoload no encuentra el ARCHIVO de `InStore` y Nueva
        // venta da 500. PHP no distingue mayúsculas en nombres de clase: basta con cargar `Instore` por adelantado.
        class_exists('Bagisto\Pickup\Payment\Instore');

        $allowedIPs = array_map('trim', explode(',', config('app.debug_allowed_ips', '')));

        $allowedIPs = array_filter($allowedIPs);

        if (empty($allowedIPs)) {
            return;
        }

        if (in_array(Request::ip(), $allowedIPs)) {
            Debugbar::enable();
        } else {
            Debugbar::disable();
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ParallelTesting::setUpTestDatabase(function (string $database, int $token) {
            Artisan::call('db:seed');
        });
    }
}
