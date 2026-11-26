<?php

/*
| Proceso hijo de las pruebas de concurrencia del cupo de cartera (Doc. 35). Un proceso PHP real, con SU conexion, que cambia el cupo
| de un cliente por el servicio real (GestionCupoCarteraService) y escribe el resultado como JSON en la ultima linea. Lo lanza
| CupoConcurrenteTest. Solo lpz_saas_testing o la base exclusiva de la rama: aplica la misma guarda que Tests\TestCase.
*/

use ByLopez\VentasAsesor\Exceptions\OperacionCupoInvalidaException;
use ByLopez\VentasAsesor\Services\Cupo\GestionCupoCarteraService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
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

try {
    $r = app(GestionCupoCarteraService::class)->establecer(
        Admin::query()->findOrFail($p['admin_id']), (int) $p['customer_id'], (string) $p['cupo'], (string) $p['estado'], (string) $p['motivo'],
    );
    $salida = ['ok' => true, 'accion' => $r['accion']];
} catch (Throwable $e) {
    // Solo clase, codigo estable y SQLSTATE: nunca el mensaje (arrastra SQL con datos).
    $salida = [
        'ok' => false,
        'clase' => $e::class,
        'codigo' => $e instanceof OperacionCupoInvalidaException ? $e->codigo : null,
        'sql_error' => $e instanceof QueryException ? ($e->errorInfo[1] ?? null) : null,
    ];
}

echo PHP_EOL.json_encode($salida).PHP_EOL;
