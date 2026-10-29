<?php

/*
| Router SOLO de la demo para `php -S` (lo usa demo.sh en lugar del server.php de Laravel). Hace dos cosas:
|   1) GET /__demo/entorno (solo desde 127.0.0.1): responde, desde DENTRO del proceso del servidor, la configuracion EFECTIVA que este
|      proceso usa (base real consultada con SELECT DATABASE(), caché de configuración, cola, correo, fechas de activacion). Sin credenciales.
|      Es la prueba de QUE BASE atiende el servidor: autenticar a demo.admin solo lo demuestra de forma indirecta.
|   2) Todo lo demas se delega al server.php de Laravel (archivos estaticos o public/index.php).
| No forma parte de la aplicacion ni se registra en rutas: solo existe cuando se arranca con demo.sh.
*/

use Illuminate\Contracts\Console\Kernel;

$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($ruta === '/__demo/entorno' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    require_once __DIR__.'/router_demo_funciones.php';
    $raiz = dirname(__DIR__, 5);
    require $raiz.'/vendor/autoload.php';
    $app = require $raiz.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    header('Content-Type: application/json');
    echo json_encode(entorno_efectivo('servidor', $raiz), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return true;
}

return require dirname(__DIR__, 5).'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
