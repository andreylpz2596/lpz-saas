<?php

/*
| Proceso hijo de la prueba de concurrencia de D10 (solo lpz_saas_testing). Un proceso PHP real, con SU conexion, que consume el
| MISMO credito (aplicar o reembolsar) con los servicios REALES y escribe el resultado como JSON en la ultima linea.
| Lo lanza D10CreditoConcurrenteTest. `cid` deja el CONNECTION_ID para que el padre vea cuando este proceso espera un bloqueo.
*/

use ByLopez\VentasAsesor\Exceptions\OperacionCreditoInvalidaException;
use ByLopez\VentasAsesor\Services\Credito\AplicarCreditoService;
use ByLopez\VentasAsesor\Services\Credito\ReembolsarCreditoService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\User\Models\Admin;

$raiz = dirname(__DIR__, 5);

require $raiz.'/vendor/autoload.php';

$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$conexion = config('database.connections.'.config('database.default'));
TestCase::verificarConexionDePruebas((string) DB::selectOne('SELECT DATABASE() AS base')->base, (string) ($conexion['host'] ?? ''));

$p = json_decode(base64_decode($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);

config(['ventasasesor.cartera.fecha_corte' => '2000-01-01']);

if (! empty($p['cid'])) {
    file_put_contents($p['cid'], (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
}

$actor = Admin::query()->findOrFail($p['admin_id']);
config()->set('ventasasesor.credito.rol_administrador_id', (int) $actor->role_id);

try {
    $r = $p['modo'] === 'reembolsar'
        ? app(ReembolsarCreditoService::class)->reembolsar($actor, (int) $p['origen'], (string) $p['monto'], 'efectivo', null, 'Devolver al cliente', $p['clave'])
        : app(AplicarCreditoService::class)->aplicar($actor, (int) $p['origen'], (int) $p['destino'], (string) $p['monto'], $p['clave']);
    $salida = ['ok' => true] + $r;
} catch (OperacionCreditoInvalidaException $e) {
    $salida = ['ok' => false, 'codigo' => $e->codigo];
} catch (Throwable $e) {
    $salida = ['ok' => false, 'clase' => get_class($e), 'mensaje' => substr($e->getMessage(), 0, 200)];
}

echo json_encode($salida), "\n";
