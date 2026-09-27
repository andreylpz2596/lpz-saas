<?php

namespace ByLopez\Logistica\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Extensiones logisticas de ByLopez sobre Bagisto (D-45).
 *
 * En 03D solo registra los carriers de METODOS DE ENTREGA con los puntos de extension
 * nativos: `carriers` (Shipping::collectRates los recorre) y `core` (campos del panel en
 * sales.carriers.<code>.*). Sin tablas, sin Shipment propio, sin transportadoras ni guias
 * (fase de logistica). Todos los carriers nacen INACTIVOS: desplegar el codigo no cambia
 * lo que ve la tienda hasta que se activen en la configuracion.
 */
class LogisticaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/carriers.php', 'carriers');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/system.php', 'core');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).'/Resources/lang', 'logistica');
    }
}
