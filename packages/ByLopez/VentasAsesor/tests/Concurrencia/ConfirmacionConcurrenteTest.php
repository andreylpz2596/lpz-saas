<?php

use ByLopez\VentasAsesor\Enums\EstadoBorradorVenta as Estado;
use ByLopez\VentasAsesor\Exceptions\PedidoRechazadoPorCercoException;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Webkul\Product\Models\ProductOrderedInventory;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Repositories\OrderRepository;

require_once __DIR__.'/../helpersFase03f.php';

/*
| Fase 03F — concurrencia REAL (Doc. 05 §38.4). Solo lpz_saas_testing.
|
| Ejecutar:  vendor/bin/pest packages/ByLopez/VentasAsesor/tests/Concurrencia
|            (grupo `concurrencia`; fuera de las testsuites de phpunit.xml a proposito).
|
| - Sin DatabaseTransactions: otra conexion no veria datos de una transaccion abierta. Los
|   fixtures se CONFIRMAN y se borran al terminar cada prueba.
| - Dos procesos PHP reales (proceso_confirmacion.php), cada uno con su conexion.
| - Entrelazado determinista con archivos de senal del padre (barrera / senal / retener) y
|   observando el estado real del motor (INNODB_TRX en LOCK WAIT). Sin sleeps fijos.
| - Las garantias de stock se prueban con productos SIMPLES: la reserva nativa de la variante
|   de un configurable es 0 en Bagisto 2.4.9 (Doc. 05 §39; ver la prueba LIMITE en Feature).
*/

/** Base sin DatabaseTransactions: mantiene la guarda de lpz_saas_testing de Tests\TestCase. */
abstract class F03fConcurrenciaTestCase extends TestCase
{
    public function beginDatabaseTransaction() {}
}

uses(F03fConcurrenciaTestCase::class)->group('concurrencia');

beforeEach(function () {
    Mail::fake();
    f03f_activarCarriers();

    // Archivos de senal (barrera / senal / retener) en el temporal del sistema.
    $this->prefijo = sys_get_temp_dir().DIRECTORY_SEPARATOR.'f03f_'.substr(uniqid(), -8);
    $this->creados = ['clientes' => [], 'grupos' => [], 'productos' => [], 'admins' => [], 'roles' => [], 'borradores' => [], 'carritos' => []];
    $this->procesos = [];
    $this->asesor = f03fc_admin($this);
    $this->cliente = f03fc_cliente($this);
});

afterEach(function () {
    foreach ($this->procesos as $proceso) {
        if ($proceso->isRunning()) {
            $proceso->stop(0);
        }
    }

    try {
        f03fc_limpiar($this->creados);
    } finally {
        foreach (['_barrera', '_s', '_r', '_sa', '_ra', '_sb', '_rb'] as $sufijo) {
            f03fc_soltar($this->prefijo.$sufijo);
        }
    }
});

// ------------------------------------------------------------------ fixtures confirmados

function f03fc_admin($test)
{
    $admin = f03b_admin();
    $test->creados['admins'][] = $admin->id;
    $test->creados['roles'][] = $admin->role_id;

    return $admin;
}

function f03fc_cliente($test)
{
    $grupo = f03b_grupo('C');
    $cliente = f03b_cliente($grupo);
    $test->creados['grupos'][] = $grupo->id;
    $test->creados['clientes'][] = $cliente->id;

    return $cliente;
}

function f03fc_simple($test, int $stock)
{
    $simple = f03f_simple();
    f03b_stock($simple, $stock);
    $test->creados['productos'][] = $simple->id;

    return $simple;
}

/** @param  list<array{0: mixed, 1: int}>  $lineas  [producto simple, cantidad] */
function f03fc_borrador($test, array $lineas): BorradorVenta
{
    $borrador = f03f_borradorListo($test->cliente->id, $test->asesor, array_map(fn ($l) => [$l[0], $l[0], $l[1]], $lineas));
    $test->creados['borradores'][] = $borrador->id;
    $test->creados['carritos'][] = $borrador->cart_id;

    return $borrador;
}

function f03fc_limpiar(array $c): void
{
    $pedidos = DB::table('orders')->whereIn('customer_id', $c['clientes'])->orWhereIn('cart_id', $c['carritos'] ?: [0])->pluck('id')->all();

    DB::table('bylopez_va_attribution_incidents')->whereIn('order_id', $pedidos)->delete();
    DB::table('bylopez_va_advisor_assignment_logs')->whereIn('order_id', $pedidos)->delete();
    DB::table('bylopez_va_order_attributions')->whereIn('order_id', $pedidos)->delete();
    DB::table('bylopez_va_borradores_venta')->whereIn('id', $c['borradores'])->delete();
    DB::table('orders')->whereIn('id', $pedidos)->delete();
    DB::table('cart')->whereIn('id', $c['carritos'])->delete();
    DB::table('customers')->whereIn('id', $c['clientes'])->delete();
    DB::table('customer_groups')->whereIn('id', $c['grupos'])->delete();
    DB::table('products')->whereIn('id', $c['productos'])->delete();
    DB::table('admins')->whereIn('id', $c['admins'])->delete();
    DB::table('roles')->whereIn('id', $c['roles'])->delete();
}

// ------------------------------------------------------------------ procesos y sincronizacion

function f03fc_lanzar($test, array $parametros): Process
{
    $proceso = new Process(
        [PHP_BINARY, __DIR__.'/proceso_confirmacion.php', base64_encode(json_encode($parametros))],
        base_path(),
        ['APP_ENV' => 'testing'],
        null,
        180,
    );

    $proceso->start();
    $test->procesos[] = $proceso;

    return $proceso;
}

/** @return array{ok: bool, order_id?: int, ya_existia?: bool, clase?: string, codigo?: ?string, detalles?: ?array, sql_error?: ?int} */
function f03fc_resultado(Process $proceso): array
{
    $proceso->wait();
    $lineas = array_values(array_filter(explode("\n", trim($proceso->getOutput()))));
    $json = json_decode((string) end($lineas), true);

    if (! is_array($json)) {
        throw new RuntimeException('El proceso hijo no devolvio JSON. Salida: '.$proceso->getOutput().' Error: '.$proceso->getErrorOutput());
    }

    return $json;
}

function f03fc_confirmacion($test, BorradorVenta $borrador, array $extra = []): array
{
    return ['modo' => 'confirmar', 'borrador_id' => $borrador->id, 'admin_id' => $test->asesor->id, 'total' => f03f_total($borrador)] + $extra;
}

/** El hijo espera mientras exista el archivo (ver proceso_confirmacion.php). */
function f03fc_tomar(string $archivo): void
{
    expect(touch($archivo))->toBeTrue();
}

function f03fc_soltar(string $archivo): void
{
    if (file_exists($archivo)) {
        unlink($archivo);
    }
}

/**
 * Espera activa con limite sobre un estado REAL (archivo de senal o motor InnoDB), no un
 * tiempo supuesto. El limite solo evita colgar la suite.
 */
function f03fc_esperar(callable $condicion, string $que, int $segundos = 150): void
{
    $limite = microtime(true) + $segundos;

    while (! $condicion()) {
        if (microtime(true) > $limite) {
            $procesos = array_map(fn (Process $p) => ['vivo' => $p->isRunning(), 'salida' => substr($p->getOutput(), -400), 'error' => substr($p->getErrorOutput(), -400)], test()->procesos ?? []);

            throw new RuntimeException("Tiempo agotado esperando: {$que}. Procesos: ".json_encode($procesos)
                .' INNODB_TRX: '.json_encode(DB::select('SELECT trx_state, trx_operation_state, trx_tables_locked, trx_rows_locked FROM information_schema.INNODB_TRX'))
                .' PROCESSLIST: '.json_encode(DB::select("SELECT command, time, state, LEFT(info, 160) AS info FROM information_schema.PROCESSLIST WHERE db = DATABASE() AND command <> 'Sleep'")));
        }

        usleep(50_000);
    }
}

function f03fc_enUso(string $archivo): bool
{
    return file_exists($archivo);
}

/**
 * Otro proceso esta bloqueado por un bloqueo de fila. Dos senales, porque MariaDB 10.4 no
 * siempre lo refleja en INNODB_TRX: un `SELECT … WHERE id = ? FOR UPDATE` por clave primaria
 * espera durante la optimizacion (PROCESSLIST state = 'Statistics') antes de figurar alli. El
 * proceso retenido esta inactivo (espera un archivo), asi que un FOR UPDATE de otra conexion
 * que dura >= 1 s es la espera buscada.
 */
function f03fc_hayEsperaDeBloqueo(): bool
{
    return (int) DB::selectOne("SELECT COUNT(*) AS c FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'")->c > 0
        || (int) DB::selectOne("SELECT COUNT(*) AS c FROM information_schema.PROCESSLIST WHERE id <> CONNECTION_ID() AND db = DATABASE() AND command IN ('Query', 'Execute') AND time >= 1 AND info LIKE '%for update%'")->c > 0;
}

/** A queda DENTRO de la transaccion del pedido, despues del cerco (con sus bloqueos). */
function f03fc_retenerDentroDelPedido($test, array $parametrosA): array
{
    $n = ['senal' => $test->prefijo.'_s', 'retener' => $test->prefijo.'_r'];
    f03fc_tomar($n['retener']);
    $a = f03fc_lanzar($test, $parametrosA + ['retener_en' => 'save.before'] + $n);
    f03fc_esperar(fn () => f03fc_enUso($n['senal']), 'A dentro del cerco');

    return [$a, $n];
}

// ------------------------------------------------------------------ 03F-37: dos pedidos, el mismo stock

test('03F-37 carrera real: stock 10, A pide 10 y B pide 10 al mismo tiempo → 1 pedido, 1 stock_insuficiente, reserva final 10', function () {
    $simple = f03fc_simple($this, 10);
    $bA = f03fc_borrador($this, [[$simple, 10]]);
    $bB = f03fc_borrador($this, [[$simple, 10]]);
    $barrera = $this->prefijo.'_barrera';
    f03fc_tomar($barrera);

    $a = f03fc_lanzar($this, f03fc_confirmacion($this, $bA, ['barrera' => $barrera]));
    $b = f03fc_lanzar($this, f03fc_confirmacion($this, $bB, ['barrera' => $barrera]));
    f03fc_soltar($barrera);

    $resultados = [f03fc_resultado($a), f03fc_resultado($b)];
    $ok = array_values(array_filter($resultados, fn ($r) => $r['ok']));
    $fallo = array_values(array_filter($resultados, fn ($r) => ! $r['ok']));

    expect($ok)->toHaveCount(1)
        ->and($fallo)->toHaveCount(1)
        ->and($fallo[0]['codigo'])->toBe('stock_insuficiente')
        ->and(Order::query()->whereIn('cart_id', [$bA->cart_id, $bB->cart_id])->count())->toBe(1)
        ->and((int) ProductOrderedInventory::query()->where('product_id', $simple->id)->sum('qty'))->toBe(10);
});

test('03F-37b entrelazado forzado: A retenido dentro del pedido, B ESPERA el bloqueo y al seguir recalcula → stock_insuficiente en el CERCO', function () {
    $simple = f03fc_simple($this, 10);
    $bA = f03fc_borrador($this, [[$simple, 10]]);
    $bB = f03fc_borrador($this, [[$simple, 10]]);

    [$a, $n] = f03fc_retenerDentroDelPedido($this, f03fc_confirmacion($this, $bA));
    $b = f03fc_lanzar($this, f03fc_confirmacion($this, $bB));
    f03fc_esperar(fn () => f03fc_hayEsperaDeBloqueo(), 'B bloqueado por el cerco de A');
    f03fc_soltar($n['retener']);

    $rA = f03fc_resultado($a);
    $rB = f03fc_resultado($b);

    expect($rA['ok'])->toBeTrue()
        ->and($rB['ok'])->toBeFalse()
        ->and($rB['codigo'])->toBe('stock_insuficiente')
        ->and($rB['detalles']['fase'])->toBe('cerco')
        ->and($rB['detalles']['variantes'][0])->toBe(['variante_id' => $simple->id, 'cantidad' => 10, 'stock_vendible' => 0])
        ->and(BorradorVenta::query()->find($bB->id)->estado)->toBe(Estado::ACTIVO)
        ->and((int) ProductOrderedInventory::query()->where('product_id', $simple->id)->sum('qty'))->toBe(10);
});

test('03F-37c global: una confirmacion ByLopez y un pedido NATIVO (panel/tienda) sobre el mismo stock tampoco se ignoran', function () {
    $simple = f03fc_simple($this, 10);
    $bA = f03fc_borrador($this, [[$simple, 10]]);
    $nativo = f03fc_borrador($this, [[$simple, 10]]);
    BorradorVenta::query()->whereKey($nativo->id)->delete(); // su carrito queda como uno normal de Bagisto

    [$a, $n] = f03fc_retenerDentroDelPedido($this, f03fc_confirmacion($this, $bA));
    $b = f03fc_lanzar($this, ['modo' => 'nativo', 'cart_id' => $nativo->cart_id]);
    f03fc_esperar(fn () => f03fc_hayEsperaDeBloqueo(), 'pedido nativo bloqueado por el cerco');
    f03fc_soltar($n['retener']);

    $rA = f03fc_resultado($a);
    $rB = f03fc_resultado($b);

    expect($rA['ok'])->toBeTrue()
        ->and($rB['ok'])->toBeFalse()
        ->and($rB['clase'])->toBe(PedidoRechazadoPorCercoException::class)
        ->and($rB['codigo'])->toBe('stock_insuficiente')
        ->and((int) ProductOrderedInventory::query()->where('product_id', $simple->id)->sum('qty'))->toBe(10);
});

// ------------------------------------------------------------------ 03F-38, 39: orden de bloqueos y espera

test('03F-38 A usa [v1, v2] y B usa [v2, v1]: sin deadlock por el orden ASCENDENTE; los dos pedidos se crean', function () {
    $v1 = f03fc_simple($this, 100);
    $v2 = f03fc_simple($this, 100);
    $bA = f03fc_borrador($this, [[$v1, 3], [$v2, 3]]);
    $bB = f03fc_borrador($this, [[$v2, 4], [$v1, 4]]);

    // Retenido: A tiene v1 y v2; B espera sin cruzarse.
    [$a, $n] = f03fc_retenerDentroDelPedido($this, f03fc_confirmacion($this, $bA));
    $b = f03fc_lanzar($this, f03fc_confirmacion($this, $bB));
    f03fc_esperar(fn () => f03fc_hayEsperaDeBloqueo(), 'B esperando a A');
    f03fc_soltar($n['retener']);
    $retenido = [f03fc_resultado($a), f03fc_resultado($b)];

    // Carrera libre con barrera, orden inverso otra vez.
    $cA = f03fc_borrador($this, [[$v1, 1], [$v2, 1]]);
    $cB = f03fc_borrador($this, [[$v2, 1], [$v1, 1]]);
    $barrera = $this->prefijo.'_barrera';
    f03fc_tomar($barrera);
    $a2 = f03fc_lanzar($this, f03fc_confirmacion($this, $cA, ['barrera' => $barrera]));
    $b2 = f03fc_lanzar($this, f03fc_confirmacion($this, $cB, ['barrera' => $barrera]));
    f03fc_soltar($barrera);
    $libre = [f03fc_resultado($a2), f03fc_resultado($b2)];

    foreach ([...$retenido, ...$libre] as $r) {
        expect($r['ok'])->toBeTrue()->and($r['sql_error'] ?? null)->not->toBe(1213);
    }

    // 3 + 4 (retenido) + 1 + 1 (libre) por producto.
    expect((int) ProductOrderedInventory::query()->where('product_id', $v1->id)->sum('qty'))->toBe(9)
        ->and((int) ProductOrderedInventory::query()->where('product_id', $v2->id)->sum('qty'))->toBe(9);
});

test('03F-39 espera de bloqueo agotada: B falla de forma controlada (1205 tras los reintentos), sin pedido y de vuelta a activo', function () {
    $simple = f03fc_simple($this, 10);
    $bA = f03fc_borrador($this, [[$simple, 5]]);
    $bB = f03fc_borrador($this, [[$simple, 5]]);

    [$a, $n] = f03fc_retenerDentroDelPedido($this, f03fc_confirmacion($this, $bA));
    $rB = f03fc_resultado(f03fc_lanzar($this, f03fc_confirmacion($this, $bB, ['lock_wait' => 1])));
    f03fc_soltar($n['retener']);
    $rA = f03fc_resultado($a);

    expect($rB['ok'])->toBeFalse()
        ->and($rB['sql_error'])->toBe(1205)
        ->and(Order::query()->where('cart_id', $bB->cart_id)->count())->toBe(0)
        ->and(BorradorVenta::query()->find($bB->id)->estado)->toBe(Estado::ACTIVO)
        ->and(BorradorVenta::query()->find($bB->id)->getRawOriginal('confirmation_token'))->toBeNull()
        ->and($rA['ok'])->toBeTrue();
});

// ------------------------------------------------------------------ 03F-40, 41: fencing y recuperacion

test('03F-40 FENCING: A toma TA y se pausa; vence el lease; B recupera con TB; A continua → confirmacion_obsoleta ANTES del pedido; B confirma; UN pedido', function () {
    $simple = f03fc_simple($this, 20);
    $borrador = f03fc_borrador($this, [[$simple, 2]]);
    $nA = ['senal' => $this->prefijo.'_sa', 'retener' => $this->prefijo.'_ra'];
    $nB = ['senal' => $this->prefijo.'_sb', 'retener' => $this->prefijo.'_rb'];
    f03fc_tomar($nA['retener']);
    f03fc_tomar($nB['retener']);

    // A: paso A hecho (TA) y pausado en la revalidacion, antes de OrderRepository.
    $a = f03fc_lanzar($this, f03fc_confirmacion($this, $borrador, ['retener_en' => 'collect.totals'] + $nA));
    f03fc_esperar(fn () => f03fc_enUso($nA['senal']), 'A pausado con TA');
    $ta = DB::table('bylopez_va_borradores_venta')->where('id', $borrador->id)->value('confirmation_token');

    // B: lease 0 → el de A esta vencido → reconciliacion (0 pedidos) → recuperable → TB.
    $b = f03fc_lanzar($this, f03fc_confirmacion($this, $borrador, ['lease' => 0, 'retener_en' => 'collect.totals'] + $nB));
    f03fc_esperar(fn () => f03fc_enUso($nB['senal']), 'B pausado con TB');
    $tb = DB::table('bylopez_va_borradores_venta')->where('id', $borrador->id)->value('confirmation_token');

    // A despierta y llega a OrderRepository con TA.
    f03fc_soltar($nA['retener']);
    $rA = f03fc_resultado($a);
    $pedidosTrasA = Order::query()->where('cart_id', $borrador->cart_id)->count();

    f03fc_soltar($nB['retener']);
    $rB = f03fc_resultado($b);
    $final = BorradorVenta::query()->find($borrador->id);

    expect($ta)->not->toBeNull()
        ->and($tb)->not->toBeNull()
        ->and($tb)->not->toBe($ta)
        ->and($rA['ok'])->toBeFalse()
        ->and($rA['codigo'])->toBe('confirmacion_obsoleta')
        ->and($rA['detalles']['fase'])->toBe('cerco')
        ->and($pedidosTrasA)->toBe(0)
        ->and($rB['ok'])->toBeTrue()
        ->and(Order::query()->where('cart_id', $borrador->cart_id)->count())->toBe(1)
        ->and($final->estado)->toBe(Estado::CONFIRMADO)
        ->and($final->order_id)->toBe($rB['order_id'])
        ->and((int) ProductOrderedInventory::query()->where('product_id', $simple->id)->sum('qty'))->toBe(2);
});

test('03F-41 la recuperacion de B ESPERA mientras A esta dentro de su transaccion; A confirma y B recibe el MISMO pedido (ya_existia)', function () {
    $simple = f03fc_simple($this, 20);
    $borrador = f03fc_borrador($this, [[$simple, 2]]);

    [$a, $n] = f03fc_retenerDentroDelPedido($this, f03fc_confirmacion($this, $borrador));
    $b = f03fc_lanzar($this, f03fc_confirmacion($this, $borrador, ['lease' => 0]));
    f03fc_esperar(fn () => f03fc_hayEsperaDeBloqueo(), 'B esperando la fila del borrador');
    f03fc_soltar($n['retener']);

    $rA = f03fc_resultado($a);
    $rB = f03fc_resultado($b);

    expect($rA['ok'])->toBeTrue()
        ->and($rB['ok'])->toBeTrue()
        ->and($rB['ya_existia'])->toBeTrue()
        ->and($rB['order_id'])->toBe($rA['order_id'])
        ->and(Order::query()->where('cart_id', $borrador->cart_id)->count())->toBe(1);
});

// ------------------------------------------------------------------ 03F-34: por que NO hay transaccion exterior

test('03F-34 (arquitectura) dentro de una transaccion exterior, un fallo de OrderRepository la CONFIRMA antes de tiempo', function () {
    config(['database.connections.f03f_observador' => config('database.connections.'.config('database.default'))]);
    Event::listen('checkout.order.save.before', function () {
        throw new RuntimeException('Fallo simulado (03F-34).');
    });

    DB::beginTransaction(); // la "transaccion exterior" que 03F NO debe abrir
    $marca = DB::table('customer_groups')->insertGetId(['code' => 'f03f_marca_'.uniqid(), 'name' => 'F03F marca', 'is_user_defined' => 1]);
    $this->creados['grupos'][] = $marca;

    try {
        app(OrderRepository::class)->createOrderIfNotThenRetry(['cart_id' => 0, 'items' => []], 1, 1);
    } catch (RuntimeException) {
    }

    // El exterior ya no existe y su INSERT es visible desde OTRA conexion: fue confirmado.
    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::connection('f03f_observador')->table('customer_groups')->where('id', $marca)->exists())->toBeTrue();
});
