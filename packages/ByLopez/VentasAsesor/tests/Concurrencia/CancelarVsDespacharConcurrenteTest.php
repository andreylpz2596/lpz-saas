<?php

use ByLopez\Logistica\DataObjects\SolicitudDespacho;
use ByLopez\Logistica\Services\Despachos\CancelarPendienteService;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

require_once __DIR__.'/../../../Logistica/tests/helpersDespacho.php';

/*
| Cancelar lo PENDIENTE contra DESPACHAR sobre el MISMO pedido, con procesos PHP reales (cada uno con su conexion). Solo lpz_saas_testing.
|
| Ejecutar:  vendor/bin/pest packages/ByLopez/VentasAsesor/tests/Concurrencia/CancelarVsDespacharConcurrenteTest.php
|
| - Pedido de 10 unidades, 10 reservadas, 50 fisicas. Despachar 6 = 44 fisicas; cancelar el pendiente libera solo la reserva.
| - Entrelazado determinista (mismo criterio que PagosConcurrentesTest): el primer proceso se retiene DENTRO de su transaccion con el
|   pedido bloqueado; el segundo arranca y el padre espera a verlo en LOCK WAIT en INNODB_TRX antes de soltar al primero.
| - CVD-3 lanza ambos a la vez con una barrera y comprueba que el resultado es UNO de los dos ordenes legitimos.
| - Los esperados se calculan aqui (10 − 6 = 4; 50 − 6 = 44), no con el servicio que se prueba. Evidencia: storage/logs/concurrencia/.
*/

abstract class CvdConcurrenciaTestCase extends TestCase
{
    public function beginDatabaseTransaction() {}
}

uses(CvdConcurrenciaTestCase::class)->group('concurrencia');

beforeEach(function () {
    pagb_corte('2000-01-01');
    $this->prefijo = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cvd_'.substr(uniqid(), -8);
    $this->procesos = [];
    $this->pedidos = [];
    $this->extras = ['productos' => [], 'clientes' => [], 'admins' => [], 'roles' => []];
    $this->actor = f03b_admin('custom', array_merge(LOG2_PERMISOS_BODEGA, ['logistica.despachos.cancelar_pendiente']));
    $this->extras['admins'][] = $this->actor->id;
    $this->extras['roles'][] = $this->actor->role_id;
});

afterEach(function () {
    foreach ($this->procesos as $proceso) {
        if ($proceso->isRunning()) {
            $proceso->stop(0);
        }
    }

    try {
        cvd_limpiar($this->pedidos, $this->extras);
    } finally {
        foreach (glob($this->prefijo.'*') ?: [] as $archivo) {
            @unlink($archivo);
        }
    }
});

/** Borra SOLO los fixtures de la prueba (por identificador) en lpz_saas_testing, en cualquier tabla que los referencie. */
function cvd_limpiar(array $pedidos, array $extras): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS=0');

    try {
        $porColumna = function (string $columna, array $ids) {
            if ($ids === []) {
                return;
            }

            foreach (DB::select('select table_name as t from information_schema.columns where table_schema = database() and column_name = ?', [$columna]) as $f) {
                DB::table($f->t)->whereIn($columna, $ids)->delete();
            }
        };

        $envios = $pedidos ? DB::table('shipments')->whereIn('order_id', $pedidos)->pluck('id')->all() : [];
        $cancelaciones = $pedidos ? DB::table('bylopez_lg_cancelaciones')->whereIn('order_id', $pedidos)->pluck('id')->all() : [];
        $items = $pedidos ? DB::table('order_items')->whereIn('order_id', $pedidos)->pluck('id')->all() : [];
        $productos = $extras['productos'];

        $porColumna('cancelacion_id', $cancelaciones);
        $porColumna('shipment_id', $envios);
        $porColumna('order_item_id', $items);
        $porColumna('order_id', $pedidos);
        $porColumna('product_id', $productos);
        $porColumna('customer_id', $extras['clientes']);
        DB::table('products')->whereIn('id', $productos)->delete();
        DB::table('customers')->whereIn('id', $extras['clientes'])->delete();
        DB::table('orders')->whereIn('id', $pedidos)->delete();
        DB::table('admins')->whereIn('id', $extras['admins'])->delete();
        DB::table('roles')->whereIn('id', $extras['roles'])->delete();
    } finally {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
}

/** Pedido de 10 unidades a 10.000 (linea 100.000), 10 reservadas y 50 fisicas; se CONFIRMA para que lo vean otros procesos. */
function cvd_pedido($test): array
{
    $e = log2_pedido(10, ['pago' => 'cashondelivery', 'total' => 100000.0, 'stock' => 50]);
    $e['item']->update(['price' => 10000, 'base_price' => 10000, 'total' => 100000, 'base_total' => 100000, 'base_discount_amount' => 0, 'base_tax_amount' => 0]);
    DB::table('product_ordered_inventories')->updateOrInsert(
        ['product_id' => $e['item']->product_id, 'channel_id' => $e['pedido']->channel_id],
        ['qty' => 10],
    );
    $test->pedidos[] = (int) $e['pedido']->id;
    $test->extras['productos'][] = (int) $e['item']->product_id;
    $test->extras['clientes'][] = (int) $e['pedido']->customer_id;

    return $e;
}

/** Estado observable del pedido, leido de la base (independiente de los servicios que se prueban). */
function cvd_estado(array $e): array
{
    $id = (int) $e['pedido']->id;
    $item = DB::table('order_items')->where('id', $e['item']->id)->first();
    $envios = DB::table('shipments')->where('order_id', $id)->pluck('id')->all();

    return [
        'fisico' => (int) DB::table('product_inventories')->where('product_id', $e['item']->product_id)->sum('qty'),
        'reservado' => (int) DB::table('product_ordered_inventories')->where('product_id', $e['item']->product_id)->where('channel_id', $e['pedido']->channel_id)->sum('qty'),
        'qty_ordered' => (int) $item->qty_ordered,
        'qty_shipped' => (int) $item->qty_shipped,
        'qty_canceled' => (int) $item->qty_canceled,
        'envios' => count($envios),
        'unidades_enviadas' => $envios ? (int) DB::table('shipment_items')->whereIn('shipment_id', $envios)->sum('qty') : 0,
        'cancelaciones' => (int) DB::table('bylopez_lg_cancelaciones')->where('order_id', $id)->count(),
        'unidades_canceladas_hist' => (int) DB::table('bylopez_lg_cancelaciones')->where('order_id', $id)->sum('unidades'),
        'guias' => (int) DB::table('bylopez_lg_guias')->where('order_id', $id)->count(),
        'refunds' => (int) DB::table('refunds')->where('order_id', $id)->count(),
        'total_pedido' => (float) DB::table('orders')->where('id', $id)->value('base_grand_total'),
        'exigible' => app(CarteraConsultaService::class)->saldosDePedidos([$id])[$id]->saldo,
    ];
}

function cvd_lanzar($test, array $parametros): Process
{
    $proceso = new Process([PHP_BINARY, __DIR__.'/proceso_cancelar_despachar.php', base64_encode(json_encode($parametros))], base_path(), ['APP_ENV' => 'testing'], null, 180);
    $proceso->start();
    $test->procesos[] = $proceso;

    return $proceso;
}

function cvd_resultado(Process $proceso): array
{
    $proceso->wait();
    $lineas = array_values(array_filter(explode("\n", trim($proceso->getOutput()))));
    $json = json_decode((string) end($lineas), true);

    if (! is_array($json)) {
        throw new RuntimeException('El proceso hijo no devolvio JSON. Salida: '.$proceso->getOutput().' Error: '.$proceso->getErrorOutput());
    }

    return $json;
}

function cvd_esperar(callable $condicion, string $que, int $segundos = 150): void
{
    $limite = microtime(true) + $segundos;

    while (! $condicion()) {
        if (microtime(true) > $limite) {
            throw new RuntimeException("Tiempo agotado esperando: {$que}.");
        }

        usleep(50_000);
    }
}

function cvd_enEspera(string $archivoCid): bool
{
    $cid = is_file($archivoCid) ? (int) file_get_contents($archivoCid) : 0;

    if ($cid <= 0) {
        return false;
    }

    return DB::table('information_schema.INNODB_TRX')->where('trx_mysql_thread_id', $cid)->where('trx_state', 'LOCK WAIT')->exists()
        // Respaldo, con evidencia (CONC-4, 2026-10-28): INNODB_TRX puede NO mostrar «LOCK WAIT» durante decenas de segundos mientras la
        // sesion sigue bloqueada en su `select ... for update` (la lista de procesos la muestra en «Statistics» con la consulta
        // detenida). Sin este respaldo el padre no soltaba al primero y el segundo agotaba los 50 s del motor (contencion
        // ARTIFICIAL del montaje, no concurrencia del negocio).
        || DB::table('information_schema.PROCESSLIST')->where('id', $cid)->where('time', '>=', 2)->where('info', 'like', '%for update%')->exists();
}

function cvd_guardar(string $nombre, array $datos): void
{
    $dir = storage_path('logs/concurrencia');
    @mkdir($dir, 0777, true);
    file_put_contents($dir.DIRECTORY_SEPARATOR.$nombre.'_'.date('Ymd_His').'.json', json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function cvd_despacho($test, array $e, int $unidades, array $extra = []): array
{
    return ['modo' => 'despachar', 'order_id' => $e['pedido']->id, 'admin_id' => $test->actor->id, 'clave' => 'cvd-d-'.uniqid(), 'guia' => 'CVD'.strtoupper(uniqid()), 'unidades' => [(string) $e['item']->id => $unidades]] + $extra;
}

function cvd_cancelacion($test, array $e, array $extra = []): array
{
    return ['modo' => 'cancelar', 'order_id' => $e['pedido']->id, 'admin_id' => $test->actor->id, 'motivo' => 'Prueba de concurrencia: cancelar el pendiente'] + $extra;
}

/** El primero se retiene dentro de su transaccion; el segundo debe ESPERAR el bloqueo del pedido y releer. */
function cvd_entrelazar($test, array $primero, string $retenerTras, array $segundo): array
{
    $senal = $test->prefijo.'_s';
    $retener = $test->prefijo.'_r';
    $cid = $test->prefijo.'_cid2';
    touch($retener);
    $t0 = microtime(true);
    $ms = fn () => (int) round((microtime(true) - $t0) * 1000);

    $p1 = cvd_lanzar($test, $primero + ['retener_tras' => $retenerTras, 'senal' => $senal, 'retener' => $retener]);
    cvd_esperar(fn () => file_exists($senal) || ! $p1->isRunning(), 'que el primero llegue a su punto de retencion');
    expect($p1->isRunning())->toBeTrue('el primero termino antes de retenerse: '.$p1->getOutput().$p1->getErrorOutput());

    $linea = ['primero_retenido_ms' => $ms()];
    $p2 = cvd_lanzar($test, $segundo + ['cid' => $cid]);
    $instantaneas = [];
    $ultima = microtime(true);
    cvd_esperar(function () use ($cid, $p2, &$instantaneas, &$ultima, $ms) {
        // Diagnostico: si la espera se alarga, guarda que ve el motor (transacciones y conexiones) cada 10 s.
        if (microtime(true) - $ultima > 10) {
            $ultima = microtime(true);
            $instantaneas[] = ['ms' => $ms(), 'trx' => DB::select('select trx_mysql_thread_id, trx_state, trx_started, trx_wait_started, trx_query from information_schema.INNODB_TRX'), 'procesos' => DB::select('select id, time, state, left(info, 80) as info from information_schema.PROCESSLIST where db = database()')];
        }

        return cvd_enEspera($cid) || ! $p2->isRunning();
    }, 'que el segundo quede esperando el bloqueo del pedido');
    $esperaba = cvd_enEspera($cid);
    $linea += ['instantaneas' => $instantaneas, 'segundo_en_lock_wait_ms' => $ms(), 'segundo_sigue_vivo' => $p2->isRunning()];
    unlink($retener);
    $linea['liberado_ms'] = $ms();
    $test->linea = $linea;

    return [cvd_resultado($p1), cvd_resultado($p2), $esperaba];
}

/** Reintentos tras la carrera: nada se duplica ni se vuelve a liberar. */
function cvd_reintentar($test, array $e, array $despachoOriginal): array
{
    $codigo = fn (callable $f) => log2_error($f);

    $repetirDespacho = $codigo(fn () => app(DespachoService::class)->despachar($test->actor, new SolicitudDespacho(
        orderId: (int) $e['pedido']->id, idempotencyKey: $despachoOriginal['clave'], transportadora: 'INTER_RAPIDISIMO',
        numeroGuia: $despachoOriginal['guia'], origenGuia: 'manual', fletePagador: 'cliente_al_recibir',
        unidades: array_map('intval', $despachoOriginal['unidades']),
    )));
    $repetirCancelacion = $codigo(fn () => app(CancelarPendienteService::class)->cancelar($test->actor, (int) $e['pedido']->id, 'Reintento de la cancelacion'));

    return ['repetir_despacho_misma_clave' => $repetirDespacho ?? 'sin_error (devuelve el despacho existente)', 'repetir_cancelacion' => $repetirCancelacion];
}

test('CVD-1 el despacho de 6 retiene el pedido; la cancelacion espera y cancela EXACTAMENTE las 4 restantes', function () {
    $e = cvd_pedido($this);
    $antes = cvd_estado($e);
    $despacho = cvd_despacho($this, $e, 6);

    [$r1, $r2, $esperaba] = cvd_entrelazar($this, $despacho, 'insert into `shipments`', cvd_cancelacion($this, $e));
    $despues = cvd_estado($e);
    $reintentos = cvd_reintentar($this, $e, $despacho);
    $final = cvd_estado($e);

    cvd_guardar('CVD-1', compact('antes', 'r1', 'r2', 'esperaba', 'despues', 'reintentos', 'final') + ['linea_de_tiempo' => $this->linea]);

    expect($antes)->toMatchArray(['fisico' => 50, 'reservado' => 10, 'qty_shipped' => 0, 'qty_canceled' => 0, 'envios' => 0, 'cancelaciones' => 0])
        ->and($esperaba)->toBeTrue('la cancelacion debia esperar el bloqueo del pedido')
        ->and($r1['ok'])->toBeTrue()
        ->and($r2['ok'])->toBeTrue()
        // La cancelacion releyo el pendiente bajo el bloqueo: 10 − 6 = 4, NO 10.
        ->and($r2['unidades'])->toBe(4)
        ->and($despues)->toMatchArray([
            'fisico' => 44, 'reservado' => 0, 'qty_shipped' => 6, 'qty_canceled' => 4, 'envios' => 1, 'unidades_enviadas' => 6,
            'cancelaciones' => 1, 'unidades_canceladas_hist' => 4, 'guias' => 1, 'refunds' => 0, 'total_pedido' => 100000.0, 'exigible' => 60000.0,
        ])
        // Reintentos: el despacho con la misma clave no crea otro envio; la cancelacion repetida no libera dos veces.
        ->and($reintentos['repetir_cancelacion'])->toBe('cancelacion_sin_pendiente')
        ->and($final)->toBe($despues);
});

test('CVD-2 la cancelacion de todo el pendiente retiene el pedido; el despacho que espera es RECHAZADO y no sale ninguna unidad', function () {
    $e = cvd_pedido($this);
    $antes = cvd_estado($e);
    $despacho = cvd_despacho($this, $e, 6);

    [$r1, $r2, $esperaba] = cvd_entrelazar($this, cvd_cancelacion($this, $e), 'insert into `bylopez_lg_cancelaciones`', $despacho);
    $despues = cvd_estado($e);
    $reintentos = cvd_reintentar($this, $e, $despacho);
    $final = cvd_estado($e);

    cvd_guardar('CVD-2', compact('antes', 'r1', 'r2', 'esperaba', 'despues', 'reintentos', 'final') + ['linea_de_tiempo' => $this->linea]);

    expect($esperaba)->toBeTrue('el despacho debia esperar el bloqueo del pedido')
        ->and($r1['ok'])->toBeTrue()->and($r1['unidades'])->toBe(10)
        ->and($r2['ok'])->toBeFalse()
        ->and($r2['clase'])->toBe('rechazo')
        ->and($despues)->toMatchArray([
            'fisico' => 50, 'reservado' => 0, 'qty_shipped' => 0, 'qty_canceled' => 10, 'envios' => 0, 'unidades_enviadas' => 0,
            'cancelaciones' => 1, 'unidades_canceladas_hist' => 10, 'guias' => 0, 'refunds' => 0, 'total_pedido' => 100000.0, 'exigible' => 0.0,
        ])
        ->and($reintentos['repetir_cancelacion'])->toBe('cancelacion_sin_pendiente')
        ->and($reintentos['repetir_despacho_misma_clave'])->not->toBe('sin_error (devuelve el despacho existente)')
        ->and($final)->toBe($despues);
});

test('CVD-3 ambos a la vez (barrera), varias rondas: siempre uno de los dos ordenes legitimos y nunca una unidad cancelada Y despachada', function () {
    $rondas = [];

    foreach (range(1, 4) as $n) {
        $e = cvd_pedido($this);
        $barrera = $this->prefijo.'_barrera'.$n;
        touch($barrera);
        $despacho = cvd_despacho($this, $e, 6, ['barrera' => $barrera]);
        $p1 = cvd_lanzar($this, $despacho);
        $p2 = cvd_lanzar($this, cvd_cancelacion($this, $e, ['barrera' => $barrera]));
        usleep(2_000_000); // solo para que ambos terminen de arrancar Laravel; la barrera es la que sincroniza
        unlink($barrera);
        [$r1, $r2] = [cvd_resultado($p1), cvd_resultado($p2)];
        $despues = cvd_estado($e);
        $reintentos = cvd_reintentar($this, $e, $despacho);
        $final = cvd_estado($e);
        $orden = $r1['ok'] ? 'despacho_primero' : 'cancelacion_primero';
        $rondas[] = compact('n', 'orden', 'r1', 'r2', 'despues', 'reintentos', 'final');

        // Conservacion: lo enviado + lo cancelado nunca supera lo pedido, y nada se descuadra entre tablas.
        expect($despues['qty_shipped'] + $despues['qty_canceled'])->toBeLessThanOrEqual(10)
            ->and($despues['unidades_enviadas'])->toBe($despues['qty_shipped'])
            ->and($despues['unidades_canceladas_hist'])->toBe($despues['qty_canceled'])
            ->and($despues['reservado'])->toBe(0)
            ->and($despues['fisico'])->toBe(50 - $despues['qty_shipped'])
            ->and($despues['cancelaciones'])->toBeLessThanOrEqual(1)
            ->and($despues['envios'])->toBeLessThanOrEqual(1)
            ->and($despues['refunds'])->toBe(0)
            ->and($final)->toBe($despues);

        if ($r1['ok']) {
            // El despacho gano: 6 enviadas; la cancelacion tomo solo las 4 restantes (o, si llego antes de cerrar, el despacho fue rechazado).
            expect($despues)->toMatchArray(['qty_shipped' => 6, 'qty_canceled' => 4, 'exigible' => 60000.0]);
            expect($r2['ok'])->toBeTrue()->and($r2['unidades'])->toBe(4);
        } else {
            // La cancelacion gano: todo cancelado; el despacho fue rechazado sin sacar nada.
            expect($despues)->toMatchArray(['qty_shipped' => 0, 'qty_canceled' => 10, 'envios' => 0, 'exigible' => 0.0]);
            expect($r2['ok'])->toBeTrue()->and($r2['unidades'])->toBe(10)->and($r1['clase'])->toBe('rechazo');
        }
    }

    cvd_guardar('CVD-3', compact('rondas'));
});
