<?php

/*
| Proceso hijo de PreciosGrupoConcurrenteTest (solo lpz_saas_testing). Un proceso PHP real, con SU conexion: previsualiza una
| edicion de «Precios por grupo» con el servicio REAL, avisa al padre y confirma (el padre tiene bloqueada la variante, asi que
| la confirmacion espera). Escribe el resultado como JSON en la ultima linea. `cid` deja el CONNECTION_ID para el padre.
*/

use ByLopez\VentasAsesor\Exceptions\PrecioGrupoInvalidoException;
use ByLopez\VentasAsesor\Services\Precios\EdicionPreciosGrupoService;
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

file_put_contents($p['cid'], (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);

$actor = Admin::query()->findOrFail($p['admin_id']);
$servicio = app(EdicionPreciosGrupoService::class);

try {
    $solicitud = $servicio->solicitud(['grupo' => $p['grupo'], 'operacion' => 'fijo', 'qty' => '1', 'valor' => $p['valor'], 'ids' => [$p['variante']]]);
    $vista = $servicio->previsualizar($actor, $solicitud);
    file_put_contents($p['listo'], '1');

    $r = $servicio->confirmar($actor, $solicitud, $vista->token);
    $salida = ['ok' => true, 'ya_aplicado' => $r['ya_aplicado'], 'lote' => $r['lote']->id, 'valor' => $p['valor']];
} catch (PrecioGrupoInvalidoException $e) {
    $salida = ['ok' => false, 'codigo' => $e->codigo, 'valor' => $p['valor']];
} catch (Throwable $e) {
    $salida = ['ok' => false, 'clase' => get_class($e), 'mensaje' => substr($e->getMessage(), 0, 300), 'valor' => $p['valor']];
}

echo json_encode($salida), "\n";
