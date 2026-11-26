<?php

/*
| Proceso hijo de DictadoConcurrenteTest (solo lpz_saas_testing). Un proceso PHP real, con SU conexion:
|  - modo 'retener': bloquea la fila del borrador (FOR UPDATE) en una transaccion, crea el archivo `senal` y espera mientras
|    exista `retener` (asi las dos peticiones del dictado quedan esperando A LA VEZ, de forma determinista);
|  - modo 'agregar': ejecuta NuevaVentaService::agregarDictado y escribe el resultado como JSON en la ultima linea.
*/

use ByLopez\VentasAsesor\Services\Venta\NuevaVentaService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
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

$esperarMientrasExista = function (string $archivo): void {
    $limite = microtime(true) + 170;
    while (file_exists($archivo)) {
        if (microtime(true) > $limite) {
            throw new RuntimeException('El padre no libero '.basename($archivo));
        }
        usleep(20_000);
    }
};

if ($p['modo'] === 'retener') {
    DB::transaction(function () use ($p, $esperarMientrasExista) {
        DB::table('bylopez_va_borradores_venta')->where('id', $p['borrador_id'])->lockForUpdate()->first();
        file_put_contents($p['senal'], 'bloqueado');
        $esperarMientrasExista($p['retener']);
    });
    echo json_encode(['ok' => true, 'modo' => 'retener']).PHP_EOL;

    exit(0);
}

// modo agregar: arranca con la barrera para salir a la vez que el otro proceso.
$esperarMientrasExista($p['barrera']);
$actor = Admin::query()->findOrFail($p['admin_id']);
auth()->guard('admin')->setUser($actor);

$inicio = microtime(true);

try {
    $r = app(NuevaVentaService::class)->agregarDictado($p['borrador_id'], 0, '', '', $p['operacion'], $p['lineas'], $actor);
    echo json_encode(['ok' => true, 'repetida' => $r['repetida'], 'resumen' => $r['resumen'], 'inicio' => $inicio, 'fin' => microtime(true)]).PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e).': '.$e->getMessage(), 'inicio' => $inicio, 'fin' => microtime(true)]).PHP_EOL;
}
