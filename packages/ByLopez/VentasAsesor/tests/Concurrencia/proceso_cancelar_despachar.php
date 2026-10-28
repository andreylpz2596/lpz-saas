<?php

/*
| Proceso hijo de la prueba CancelarVsDespacharConcurrenteTest (solo lpz_saas_testing). Un proceso PHP real, con SU conexion, que
| DESPACHA unidades de un pedido o CANCELA su pendiente, y escribe el resultado como JSON en la ultima linea.
|
| Sincronizacion determinista con archivos de senal del padre (mismo criterio que proceso_pago.php):
| - barrera: espera mientras exista, para arrancar a la vez;
| - retener_tras: gancho SOLO DE PRUEBA (DB::listen) que, la primera vez que se EJECUTA una consulta que contiene ese texto, crea
|   `senal` y espera mientras exista `retener`: la transaccion queda abierta con el bloqueo del pedido tomado.
| - cid: archivo donde escribe su CONNECTION_ID para que el padre lo vea en LOCK WAIT.
*/

function cvd_esperarMientrasExista(string $archivo): void
{
    $limite = microtime(true) + 170;

    while (file_exists($archivo)) {
        if (microtime(true) > $limite) {
            throw new RuntimeException('Prueba de concurrencia: el padre no libero '.basename($archivo));
        }

        usleep(20_000);
    }
}

use ByLopez\Logistica\DataObjects\SolicitudDespacho;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException;
use ByLopez\Logistica\Services\Despachos\CancelarPendienteService;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
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

$p = json_decode(base64_decode($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);

config(['ventasasesor.cartera.fecha_corte' => '2000-01-01']);
// El pedido de la prueba nace sin la capacidad F6 activa en esta base si la migracion no estuviera: no se toca aqui.

$consultas = [];
$inicio = microtime(true);

DB::listen(function ($q) use (&$consultas, $inicio, $p) {
    static $retenido = false;

    $consultas[] = round((microtime(true) - $inicio) * 1000).'ms '.substr(preg_replace('/\s+/', ' ', $q->sql), 0, 90);

    if (! $retenido && ! empty($p['retener_tras']) && str_contains($q->sql, $p['retener_tras'])) {
        $retenido = true;
        touch($p['senal']);
        cvd_esperarMientrasExista($p['retener']);
    }
});

if (! empty($p['cid'])) {
    file_put_contents($p['cid'], (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
}

if (! empty($p['barrera'])) {
    cvd_esperarMientrasExista($p['barrera']);
}

// El despacho notifica al cliente: en la prueba NO sale ningun correo (la configuracion del remitente no existe en este proceso).
Mail::fake();

$actor = Admin::query()->findOrFail($p['admin_id']);
$salida = [];

try {
    if ($p['modo'] === 'cancelar') {
        $c = app(CancelarPendienteService::class)->cancelar($actor, (int) $p['order_id'], $p['motivo'] ?? 'Prueba de concurrencia: cancelar el pendiente');
        $salida = ['ok' => true, 'cancelacion_id' => $c->id, 'unidades' => (int) $c->unidades];
    } else {
        $s = new SolicitudDespacho(
            orderId: (int) $p['order_id'],
            idempotencyKey: $p['clave'],
            transportadora: 'INTER_RAPIDISIMO',
            numeroGuia: $p['guia'],
            origenGuia: 'manual',
            fletePagador: 'cliente_al_recibir',
            unidades: array_map('intval', $p['unidades']),
        );
        $r = app(DespachoService::class)->despachar($actor, $s);
        $salida = ['ok' => true, 'shipment_id' => $r->shipmentId, 'guia_id' => $r->guia?->id, 'duplicado' => $r->yaExistia];
    }
} catch (OperacionLogisticaInvalidaException $e) {
    $salida = ['ok' => false, 'clase' => 'rechazo', 'codigo' => $e->codigo, 'detalles' => $e->detalles];
} catch (QueryException $e) {
    $salida = ['ok' => false, 'clase' => 'sql', 'sql_error' => (int) ($e->errorInfo[1] ?? 0), 'mensaje' => substr($e->getMessage(), 0, 200)];
} catch (Throwable $e) {
    $salida = ['ok' => false, 'clase' => get_class($e), 'mensaje' => substr($e->getMessage(), 0, 300)];
}

echo json_encode($salida + ['consultas' => $consultas]), "\n";
