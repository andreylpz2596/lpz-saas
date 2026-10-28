<?php

/*
| Proceso hijo de las pruebas de concurrencia de Cartera (F3/F4, solo lpz_saas_testing). Un proceso PHP real, con SU
| conexion, que registra un pago (simple o combinado) y escribe el resultado como JSON en la ultima linea. Lo lanza
| PagosConcurrentesTest.
|
| Sincronizacion determinista con archivos de senal del padre (mismo criterio que proceso_confirmacion.php):
| - barrera: espera mientras exista, para arrancar a la vez que los demas;
| - retener_tras: gancho SOLO DE PRUEBA (DB::listen) que, la primera vez que se EJECUTA una consulta que contiene ese
|   texto, crea `senal` y espera mientras exista `retener`. La transaccion queda abierta con sus bloqueos tomados.
| Ademas registra cada consulta (texto corto + milisegundo) para poder ver, despues, que saldo leyo cada proceso.
*/

function pcc_esperarMientrasExista(string $archivo): void
{
    $limite = microtime(true) + 170;

    while (file_exists($archivo)) {
        if (microtime(true) > $limite) {
            throw new RuntimeException('Prueba de concurrencia: el padre no libero '.basename($archivo));
        }

        usleep(20_000);
    }
}

use ByLopez\Logistica\Contracts\GestionaCobroPedido;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException;
use ByLopez\VentasAsesor\Exceptions\RegistroPagoInvalidoException;
use ByLopez\VentasAsesor\Exceptions\RevisionPagoInvalidaException;
use ByLopez\VentasAsesor\Services\Cartera\ConfirmarPagoService;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarPagoService;
use ByLopez\VentasAsesor\Support\Cartera\RespuestaErrorNoControlado;
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

config(['ventasasesor.cartera.fecha_corte' => '2000-01-01']);

$consultas = [];
$inicio = microtime(true);

DB::listen(function ($q) use (&$consultas, $inicio, $p) {
    static $retenido = false;

    $consultas[] = round((microtime(true) - $inicio) * 1000).'ms '.substr(preg_replace('/\s+/', ' ', $q->sql), 0, 110);

    if (! $retenido && ! empty($p['retener_tras']) && str_contains($q->sql, $p['retener_tras'])) {
        $retenido = true;
        touch($p['senal']);
        pcc_esperarMientrasExista($p['retener']);
    }
});

// Solo de prueba: acorta la espera de bloqueo de ESTA sesion para provocar el timeout (1205) de forma determinista.
if (! empty($p['espera_bloqueo_s'])) {
    DB::statement('SET SESSION innodb_lock_wait_timeout = '.(int) $p['espera_bloqueo_s']);
}

// El padre observa el estado de ESTA conexion en INNODB_TRX (no el de cualquier otra transaccion de la base).
if (! empty($p['cid'])) {
    file_put_contents($p['cid'], (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
}

if (! empty($p['barrera'])) {
    pcc_esperarMientrasExista($p['barrera']);
}

$actor = Admin::query()->findOrFail($p['admin_id']);
$servicio = app(RegistrarPagoService::class);

try {
    if (($p['modo'] ?? '') === 'cobro') {
        // Cobro contra entrega tal como lo hace Logistica (registrar + confirmar), sin guia.
        $r = app(GestionaCobroPedido::class)->cobrar($actor, (int) $p['order_id'], (string) $p['monto'], 'efectivo', null, now()->toDateString(), $p['clave']);
        $salida = ['ok' => true] + $r;
    } elseif (($p['modo'] ?? '') === 'confirmar') {
        $r = app(ConfirmarPagoService::class)->confirmar($actor, (int) $p['pago_id']);
        $salida = ['ok' => true, 'estado' => $r->estado];
    } elseif (($p['modo'] ?? 'registrar') === 'combinado') {
        $r = $servicio->registrarCombinado($actor, (int) $p['order_id'], $p['partes'], ['fecha_pago' => now()->toDateString()], $p['clave']);
        $salida = ['ok' => true, 'pagos' => array_map(fn ($x) => $x->pagoId, $r['pagos']), 'duplicado' => collect($r['pagos'])->every(fn ($x) => $x->duplicado)];
    } else {
        $r = $servicio->registrar($actor, (int) $p['order_id'], [
            'monto' => (string) $p['monto'], 'medio_cobro' => 'efectivo', 'fecha_pago' => now()->toDateString(),
        ], $p['clave']);
        $salida = ['ok' => true, 'pago_id' => $r->pagoId, 'duplicado' => $r->duplicado, 'saldo_despues' => $r->saldo->saldo];
    }
} catch (RegistroPagoInvalidoException $e) {
    $salida = ['ok' => false, 'clase' => 'rechazo', 'codigo' => $e->codigo, 'detalles' => $e->detalles];
} catch (RevisionPagoInvalidaException $e) {
    $salida = ['ok' => false, 'clase' => 'revision', 'codigo' => $e->codigo, 'detalles' => $e->detalles];
} catch (OperacionLogisticaInvalidaException $e) {
    $salida = ['ok' => false, 'clase' => 'logistica', 'codigo' => $e->codigo, 'detalles' => $e->detalles];
} catch (QueryException $e) {
    $salida = ['ok' => false, 'clase' => 'sql', 'sql_error' => (int) ($e->errorInfo[1] ?? 0), 'mensaje' => substr($e->getMessage(), 0, 200), 'http_del_panel' => RespuestaErrorNoControlado::json($e)->getStatusCode()];
} catch (Throwable $e) {
    $salida = ['ok' => false, 'clase' => get_class($e), 'mensaje' => substr($e->getMessage(), 0, 200), 'http_del_panel' => RespuestaErrorNoControlado::json($e)->getStatusCode()];
}

echo json_encode($salida + ['consultas' => $consultas]), "\n";
