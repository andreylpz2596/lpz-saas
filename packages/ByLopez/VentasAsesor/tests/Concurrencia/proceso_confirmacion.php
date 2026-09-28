<?php

/*
| Proceso hijo de las pruebas de concurrencia 03F (solo lpz_saas_testing). Un proceso PHP
| real, con SU conexion, que ejecuta una confirmacion ByLopez o un pedido nativo y escribe el
| resultado como JSON en la ultima linea. Lo lanza ConfirmacionConcurrenteTest.
|
| Sincronizacion determinista con ARCHIVOS de senal del proceso padre (no con GET_LOCK: un
| hilo del servidor bloqueado en GET_LOCK retrasa, con el thread pool de MariaDB en Windows,
| la atencion de las conexiones nuevas y volvia intermitente la prueba):
| - barrera: el hijo espera mientras exista el archivo, para arrancar a la vez que el otro;
| - retener_en: gancho SOLO DE PRUEBA que, la primera vez que pasa por ese evento, crea
|   `senal` y espera mientras exista `retener`. Su conexion queda inactiva (sin consultas).
|     'save.before'    → dentro de la transaccion del pedido, DESPUES del cerco (con sus
|                        bloqueos tomados);
|     'collect.totals' → en la revalidacion (paso B), despues del paso A y antes del pedido.
*/

function f03fc_esperarMientrasExista(string $archivo): void
{
    $limite = microtime(true) + 170;

    while (file_exists($archivo)) {
        if (microtime(true) > $limite) {
            throw new RuntimeException('Prueba de concurrencia: el padre no libero '.basename($archivo));
        }

        usleep(20_000);
    }
}

use ByLopez\VentasAsesor\Services\Venta\ConfirmarVentaService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\Cart as CartModel;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Transformers\OrderResource;
use Webkul\User\Models\Admin;

$raiz = dirname(__DIR__, 5);

require $raiz.'/vendor/autoload.php';

$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$conexion = config('database.connections.'.config('database.default'));
TestCase::verificarConexionDePruebas((string) DB::selectOne('SELECT DATABASE() AS base')->base, (string) ($conexion['host'] ?? ''));

require_once __DIR__.'/../helpersFase03f.php';

$p = json_decode(base64_decode($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);

Mail::fake();
f03f_activarCarriers();

if (isset($p['lease'])) {
    config(['ventasasesor.confirmacion.lease_segundos' => (int) $p['lease']]);
}

if (isset($p['lock_wait'])) {
    DB::statement('SET SESSION innodb_lock_wait_timeout = '.(int) $p['lock_wait']);
}

if (! empty($p['retener_en'])) {
    $evento = $p['retener_en'] === 'save.before' ? 'checkout.order.save.before' : 'checkout.cart.collect.totals.before';

    Event::listen($evento, function () use ($p) {
        static $hecho = false;

        if ($hecho) {
            return;
        }

        $hecho = true;
        touch($p['senal']);
        f03fc_esperarMientrasExista($p['retener']);
    });
}

if (! empty($p['barrera'])) {
    f03fc_esperarMientrasExista($p['barrera']);
}

try {
    if ($p['modo'] === 'confirmar') {
        $r = app(ConfirmarVentaService::class)->confirmar((int) $p['borrador_id'], Admin::query()->findOrFail($p['admin_id']), $p['total']);
        $salida = ['ok' => true, 'order_id' => $r->orderId, 'ya_existia' => $r->yaExistia];
    } else {
        Cart::setCart(CartModel::query()->findOrFail($p['cart_id']));
        $pedido = app(OrderRepository::class)->create((new OrderResource(Cart::getCart()))->jsonSerialize());
        $salida = ['ok' => true, 'order_id' => (int) $pedido->id, 'ya_existia' => false];
    }
} catch (Throwable $e) {
    // Solo clase, codigo estable y SQLSTATE: nunca el mensaje (arrastra SQL con datos).
    $salida = [
        'ok' => false,
        'clase' => $e::class,
        'codigo' => property_exists($e, 'codigo') ? $e->codigo : null,
        'detalles' => property_exists($e, 'detalles') ? $e->detalles : null,
        'sql_error' => $e instanceof QueryException ? ($e->errorInfo[1] ?? null) : null,
    ];
}

echo PHP_EOL.json_encode($salida).PHP_EOL;
