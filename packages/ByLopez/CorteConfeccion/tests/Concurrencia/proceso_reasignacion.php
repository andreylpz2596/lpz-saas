<?php

/*
| Proceso hijo de ReasignacionConcurrenteTest (solo lpz_saas_testing). Un proceso PHP real, con SU conexion, que reasigna,
| reporta o anula sobre el mismo corte y escribe el resultado como JSON en la ultima linea.
|
| Sincronizacion con archivos de senal del padre (mismo criterio que VentasAsesor/tests/Concurrencia):
| - barrera: espera mientras exista, para arrancar todos a la vez;
| - cid: archivo donde escribe su CONNECTION_ID.
*/

use ByLopez\CorteConfeccion\Exceptions\CarteraConflictoException;
use ByLopez\CorteConfeccion\Exceptions\ReasignacionConflictoException;
use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Services\CarteraMovimientoService;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use ByLopez\CorteConfeccion\Services\PrioridadConfeccionService;
use ByLopez\CorteConfeccion\Services\ReasignacionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Webkul\User\Models\Admin;

$raiz = dirname(__DIR__, 5);

require $raiz.'/vendor/autoload.php';

$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$conexion = config('database.connections.'.config('database.default'));
TestCase::verificarConexionDePruebas((string) DB::selectOne('SELECT DATABASE() AS base')->base, (string) ($conexion['host'] ?? ''));

$p = json_decode(base64_decode($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);

if (! empty($p['cid'])) {
    file_put_contents($p['cid'], (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
}

if (! empty($p['barrera'])) {
    $limite = microtime(true) + 170;

    while (file_exists($p['barrera'])) {
        if (microtime(true) > $limite) {
            throw new RuntimeException('El padre no libero la barrera');
        }

        usleep(10_000);
    }
}

Auth::guard('admin')->setUser(Admin::query()->findOrFail($p['admin_id']));

$salida = [];

try {
    if ($p['modo'] === 'reasignar') {
        $r = app(ReasignacionService::class)->reasignar($p['payload']);
        $salida = ['ok' => true, 'reasignacion_id' => $r->id, 'orden_destino_id' => $r->orden_confeccion_destino_id];
    } elseif ($p['modo'] === 'cartera') {
        $mov = app(CarteraMovimientoService::class)->registrar($p['payload']);
        $salida = ['ok' => true, 'movimiento_id' => $mov->id, 'codigo' => $mov->codigo];
    } elseif ($p['modo'] === 'http') {
        // Endpoint REAL: pasa por el kernel HTTP (middleware, FormRequest, controlador, servicio), no solo por el servicio.
        $peticion = Request::create($p['uri'], $p['metodo'], $p['datos'] ?? [], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $respuesta = $app->make(HttpKernel::class)->handle($peticion);
        $salida = ['ok' => $respuesta->getStatusCode() < 400, 'status' => $respuesta->getStatusCode(), 'cuerpo' => substr((string) $respuesta->getContent(), 0, 400)];
    } elseif ($p['modo'] === 'prioridad') {
        $r = app(PrioridadConfeccionService::class)->cambiar($p['payload']);
        $salida = ['ok' => true, 'operacion_id' => $r['operacion']->id, 'repetida' => $r['repetida']];
    } elseif ($p['modo'] === 'reportar') {
        $retorno = app(OrdenConfeccionService::class)->reportarTaller(OrdenConfeccion::findOrFail($p['orden_id']), $p['payload']);
        $salida = ['ok' => true, 'retorno_id' => $retorno->id];
    } else {
        app(OrdenConfeccionService::class)->cancel(OrdenConfeccion::findOrFail($p['orden_id']));
        $salida = ['ok' => true];
    }
} catch (CarteraConflictoException $e) {
    $salida = ['ok' => false, 'clase' => 'conflicto', 'mensaje' => $e->getMessage()];
} catch (ReasignacionConflictoException $e) {
    $salida = ['ok' => false, 'clase' => 'conflicto', 'mensaje' => $e->getMessage()];
} catch (ValidationException $e) {
    $salida = ['ok' => false, 'clase' => 'validacion', 'errores' => $e->errors()];
} catch (QueryException $e) {
    $salida = ['ok' => false, 'clase' => 'sql', 'sql_error' => (int) ($e->errorInfo[1] ?? 0), 'mensaje' => substr($e->getMessage(), 0, 300)];
} catch (Throwable $e) {
    $salida = ['ok' => false, 'clase' => get_class($e), 'mensaje' => substr($e->getMessage(), 0, 300)];
}

echo json_encode($salida), "\n";
