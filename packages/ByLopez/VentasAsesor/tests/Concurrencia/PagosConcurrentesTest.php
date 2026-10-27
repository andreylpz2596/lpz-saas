<?php

use ByLopez\VentasAsesor\Services\Cartera\RegistrarPagoService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Webkul\Sales\Models\Order;

require_once __DIR__.'/../helpersPagB.php';

/*
| F3/F4 — concurrencia REAL al registrar pagos sobre el mismo pedido (auditoria E2E 2026-10-21). Solo lpz_saas_testing.
|
| Ejecutar:  vendor/bin/pest packages/ByLopez/VentasAsesor/tests/Concurrencia/PagosConcurrentesTest.php
|
| - Sin DatabaseTransactions: los fixtures se CONFIRMAN (otra conexion no veria una transaccion abierta) y se borran al final.
| - Procesos PHP reales (proceso_pago.php), cada uno con su conexion.
| - Entrelazado determinista: el primer proceso se retiene DESPUES de escribir su aplicacion (bloqueo del pedido tomado);
|   el segundo arranca y el padre espera a verlo en LOCK WAIT en INNODB_TRX antes de soltar al primero. Sin sleeps fijos.
| - El resultado esperado se calcula aqui (saldo inicial − montos aceptados), no con el servicio que se prueba.
*/

abstract class PagosConcurrenciaTestCase extends TestCase
{
    public function beginDatabaseTransaction() {}
}

uses(PagosConcurrenciaTestCase::class)->group('concurrencia');

beforeEach(function () {
    pagb_corte();
    $this->prefijo = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pcc_'.substr(uniqid(), -8);
    $this->procesos = [];
    $this->creados = ['pedidos' => [], 'clientes' => [], 'grupos' => [], 'admins' => [], 'roles' => []];

    $grupo = f03b_grupo('PCC');
    $this->cliente = f03b_cliente($grupo);
    $this->actor = f03b_admin('all');
    $this->creados['grupos'][] = $grupo->id;
    $this->creados['clientes'][] = $this->cliente->id;
    $this->creados['admins'][] = $this->actor->id;
    $this->creados['roles'][] = $this->actor->role_id;
});

afterEach(function () {
    foreach ($this->procesos as $proceso) {
        if ($proceso->isRunning()) {
            $proceso->stop(0);
        }
    }

    try {
        $c = $this->creados;
        $pagos = DB::table('bylopez_va_pagos')->whereIn('customer_id', $c['clientes'])->pluck('id')->all();
        DB::table('bylopez_va_pagos_eventos')->whereIn('pago_id', $pagos)->delete();
        DB::table('bylopez_va_aplicaciones_pago')->whereIn('pago_id', $pagos)->delete();
        DB::table('bylopez_va_pagos')->whereIn('id', $pagos)->delete();
        // Hechos que el listener de comisiones encola por cada pago/aplicacion (sin FK al pedido).
        DB::table('bylopez_va_comision_reevaluaciones')->whereIn('order_id', $c['pedidos'] ?: [0])->delete();
        DB::table('order_transactions')->whereIn('order_id', $c['pedidos'] ?: [0])->delete();
        DB::table('invoices')->whereIn('order_id', $c['pedidos'] ?: [0])->delete();
        DB::table('orders')->whereIn('id', $c['pedidos'])->delete();
        DB::table('customers')->whereIn('id', $c['clientes'])->delete();
        DB::table('customer_groups')->whereIn('id', $c['grupos'])->delete();
        DB::table('admins')->whereIn('id', $c['admins'])->delete();
        DB::table('roles')->whereIn('id', $c['roles'])->delete();
    } finally {
        foreach (glob($this->prefijo.'*') ?: [] as $archivo) {
            @unlink($archivo);
        }
    }
});

function pcc_pedido($test, float $total): int
{
    $pedido = pagb_pedido($test->cliente, $total);
    // Metodo de pago nativo: confirmar crea la Transaction con el metodo del pedido.
    DB::table('order_payment')->insert(['order_id' => $pedido->id, 'method' => 'cashondelivery', 'method_title' => 'Contra entrega', 'created_at' => now(), 'updated_at' => now()]);
    $test->creados['pedidos'][] = $pedido->id;

    return (int) $pedido->id;
}

function pcc_lanzar($test, array $parametros): Process
{
    $proceso = new Process([PHP_BINARY, __DIR__.'/proceso_pago.php', base64_encode(json_encode($parametros))], base_path(), ['APP_ENV' => 'testing'], null, 180);
    $proceso->start();
    $test->procesos[] = $proceso;

    return $proceso;
}

function pcc_resultado(Process $proceso): array
{
    $proceso->wait();
    $lineas = array_values(array_filter(explode("\n", trim($proceso->getOutput()))));
    $json = json_decode((string) end($lineas), true);

    if (! is_array($json)) {
        throw new RuntimeException('El proceso hijo no devolvio JSON. Salida: '.$proceso->getOutput().' Error: '.$proceso->getErrorOutput());
    }

    return $json;
}

function pcc_esperar(callable $condicion, string $que, int $segundos = 150): void
{
    $limite = microtime(true) + $segundos;

    while (! $condicion()) {
        if (microtime(true) > $limite) {
            throw new RuntimeException("Tiempo agotado esperando: {$que}. INNODB_TRX: ".json_encode(DB::select('SELECT trx_state, trx_operation_state FROM information_schema.INNODB_TRX')));
        }

        usleep(50_000);
    }
}

/** La conexion del proceso (CONNECTION_ID escrito por el hijo) esta en LOCK WAIT. */
function pcc_hayEsperaDeBloqueo(string $archivoCid): bool
{
    $cid = is_file($archivoCid) ? (int) file_get_contents($archivoCid) : 0;

    return $cid > 0 && DB::table('information_schema.INNODB_TRX')->where('trx_mysql_thread_id', $cid)->where('trx_state', 'LOCK WAIT')->exists();
}

/** Suma aplicada al pedido segun la base (calculo independiente del servicio). */
function pcc_aplicado(int $orderId): float
{
    return (float) DB::table('bylopez_va_aplicaciones_pago')->where('order_id', $orderId)->whereNull('anulada_en')->sum('monto');
}

function pcc_guardarEvidencia(string $nombre, array $datos): void
{
    $dir = storage_path('logs/concurrencia');
    @mkdir($dir, 0777, true);
    file_put_contents($dir.DIRECTORY_SEPARATOR.$nombre.'_'.date('Ymd_His').'.json', json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/** El primero escribe su aplicacion y se retiene con el pedido bloqueado; el segundo debe esperar y releer. */
function pcc_entrelazar($test, array $primero, array $segundo): array
{
    $senal = $test->prefijo.'_s';
    $retener = $test->prefijo.'_r';
    touch($retener);

    $p1 = pcc_lanzar($test, $primero + ['retener_tras' => 'insert into `bylopez_va_aplicaciones_pago`', 'senal' => $senal, 'retener' => $retener]);
    pcc_esperar(fn () => file_exists($senal) || ! $p1->isRunning(), 'que el primero escriba su aplicacion');
    expect($p1->isRunning())->toBeTrue('el primero termino antes de retenerse: '.$p1->getOutput().$p1->getErrorOutput());

    $cid = $test->prefijo.'_cid2';
    $p2 = pcc_lanzar($test, $segundo + ['cid' => $cid]);
    pcc_esperar(fn () => pcc_hayEsperaDeBloqueo($cid) || ! $p2->isRunning(), 'que el segundo quede esperando el bloqueo');
    $segundoEsperaba = pcc_hayEsperaDeBloqueo($cid);

    unlink($retener);

    return [pcc_resultado($p1), pcc_resultado($p2), $segundoEsperaba];
}

test('CONC-1 claves distintas, entrelazado determinista: el segundo relee el saldo bajo el bloqueo y no sobreaplica', function () {
    $pedido = pcc_pedido($this, 30000);

    [$r1, $r2, $esperaba] = pcc_entrelazar($this,
        ['order_id' => $pedido, 'admin_id' => $this->actor->id, 'monto' => 20000, 'clave' => 'pcc-conc1-a-'.uniqid()],
        ['order_id' => $pedido, 'admin_id' => $this->actor->id, 'monto' => 20000, 'clave' => 'pcc-conc1-b-'.uniqid()],
    );

    pcc_guardarEvidencia('CONC-1', compact('pedido', 'r1', 'r2', 'esperaba'));

    expect($esperaba)->toBeTrue()
        ->and($r1['ok'])->toBeTrue()
        ->and($r2['ok'])->toBeFalse()
        ->and($r2['codigo'] ?? null)->toBe('monto_excede_saldo')
        ->and((float) ($r2['detalles']['saldo'] ?? -1))->toBe(10000.0)
        ->and(pcc_aplicado($pedido))->toBe(20000.0)
        ->and(DB::table('bylopez_va_pagos')->where('customer_id', $this->cliente->id)->count())->toBe(1);
});

test('CONC-2 misma clave y misma solicitud en paralelo: un solo pago, el segundo recibe el existente', function () {
    $pedido = pcc_pedido($this, 30000);
    $clave = 'pcc-conc2-'.uniqid();
    $base = ['order_id' => $pedido, 'admin_id' => $this->actor->id, 'monto' => 20000, 'clave' => $clave];

    [$r1, $r2, $esperaba] = pcc_entrelazar($this, $base, $base);

    pcc_guardarEvidencia('CONC-2', compact('pedido', 'r1', 'r2', 'esperaba'));

    expect($r1['ok'])->toBeTrue()
        ->and($r2['ok'])->toBeTrue()
        ->and($r2['duplicado'])->toBeTrue()
        ->and($r2['pago_id'])->toBe($r1['pago_id'])
        ->and(pcc_aplicado($pedido))->toBe(20000.0)
        ->and(DB::table('bylopez_va_pagos')->where('customer_id', $this->cliente->id)->count())->toBe(1);
});

test('CONC-3 cuatro procesos simultaneos con claves distintas sobre saldo 30.000: se acepta uno y el saldo no queda negativo', function () {
    $pedido = pcc_pedido($this, 30000);
    $barrera = $this->prefijo.'_barrera';
    touch($barrera);

    $procesos = [];
    foreach (range(1, 4) as $i) {
        $procesos[] = pcc_lanzar($this, ['order_id' => $pedido, 'admin_id' => $this->actor->id, 'monto' => 20000, 'clave' => "pcc-conc3-{$i}-".uniqid(), 'barrera' => $barrera]);
    }

    usleep(1_500_000); // solo para que los cuatro terminen de arrancar Laravel; la barrera es la que sincroniza
    unlink($barrera);
    $resultados = array_map('pcc_resultado', $procesos);

    pcc_guardarEvidencia('CONC-3', compact('pedido', 'resultados'));

    $aceptados = array_filter($resultados, fn ($r) => $r['ok']);
    $rechazos = array_filter($resultados, fn ($r) => ! $r['ok']);

    expect(count($aceptados))->toBe(1)
        ->and(collect($rechazos)->pluck('codigo')->unique()->values()->all())->toBe(['monto_excede_saldo'])
        ->and(pcc_aplicado($pedido))->toBe(20000.0);
});

test('CONC-4 pago combinado y pago simple en paralelo: la suma nunca supera el saldo y el combinado es todo o nada', function () {
    $pedido = pcc_pedido($this, 30000);

    [$r1, $r2, $esperaba] = pcc_entrelazar($this,
        ['order_id' => $pedido, 'admin_id' => $this->actor->id, 'monto' => 20000, 'clave' => 'pcc-conc4-s-'.uniqid()],
        ['modo' => 'combinado', 'order_id' => $pedido, 'admin_id' => $this->actor->id, 'clave' => 'pcc-conc4-c-'.uniqid(), 'partes' => [
            ['monto' => 6000, 'plataforma' => 'nequi', 'referencia' => 'PCC-N1'],
            ['monto' => 6000, 'plataforma' => 'bancolombia', 'referencia' => 'PCC-B1'],
        ]],
    );

    pcc_guardarEvidencia('CONC-4', compact('pedido', 'r1', 'r2', 'esperaba'));

    // Saldo 30.000: el simple (20.000) entra; el combinado (12.000) ya no cabe en los 10.000 restantes y no deja partes.
    expect($r1['ok'])->toBeTrue()
        ->and($r2['ok'])->toBeFalse()
        ->and($r2['codigo'] ?? null)->toBe('monto_excede_saldo')
        ->and(pcc_aplicado($pedido))->toBe(20000.0)
        ->and(DB::table('bylopez_va_pagos')->where('customer_id', $this->cliente->id)->count())->toBe(1);
});

test('CONC-5 F4 dos cobros contra entrega simultaneos (registrar + confirmar): uno se cobra y el otro se rechaza controlado, sin 500', function () {
    $pedido = pcc_pedido($this, 27000);
    pagb_factura(Order::query()->findOrFail($pedido));

    [$r1, $r2, $esperaba] = pcc_entrelazar($this,
        ['modo' => 'cobro', 'order_id' => $pedido, 'admin_id' => $this->actor->id, 'monto' => 27000, 'clave' => 'pcc-conc5-a-'.uniqid()],
        ['modo' => 'cobro', 'order_id' => $pedido, 'admin_id' => $this->actor->id, 'monto' => 27000, 'clave' => 'pcc-conc5-b-'.uniqid()],
    );

    pcc_guardarEvidencia('CONC-5', compact('pedido', 'r1', 'r2', 'esperaba'));

    expect($esperaba)->toBeTrue()
        ->and($r1['ok'])->toBeTrue()->and($r1['confirmado'])->toBeTrue()
        ->and($r2['ok'])->toBeFalse()
        ->and($r2['clase'])->toBe('logistica')
        ->and($r2['codigo'])->toBe('cobro_rechazado')
        ->and(pcc_aplicado($pedido))->toBe(27000.0)
        ->and(DB::table('bylopez_va_pagos')->where('customer_id', $this->cliente->id)->count())->toBe(1)
        ->and(DB::table('order_transactions')->where('order_id', $pedido)->count())->toBe(1);
});

test('CONC-6 dos confirmaciones simultaneas sobre la misma factura: la segunda ve lo pagado por la primera y la factura queda pagada', function () {
    $pedido = pcc_pedido($this, 30000);
    $orden = Order::query()->findOrFail($pedido);
    $factura = pagb_factura($orden);
    $servicio = app(RegistrarPagoService::class);
    $a = $servicio->registrar($this->actor, $pedido, ['monto' => '15000', 'medio_cobro' => 'efectivo', 'fecha_pago' => now()->toDateString()], 'pcc-conc6-a-'.uniqid());
    $b = $servicio->registrar($this->actor, $pedido, ['monto' => '15000', 'medio_cobro' => 'efectivo', 'fecha_pago' => now()->toDateString()], 'pcc-conc6-b-'.uniqid());

    $senal = $this->prefijo.'_s';
    $retener = $this->prefijo.'_r';
    touch($retener);
    $p1 = pcc_lanzar($this, ['modo' => 'confirmar', 'pago_id' => $a->pagoId, 'admin_id' => $this->actor->id, 'retener_tras' => 'insert into `order_transactions`', 'senal' => $senal, 'retener' => $retener]);
    pcc_esperar(fn () => file_exists($senal) || ! $p1->isRunning(), 'que la primera confirmacion cree su Transaction');
    $cid = $this->prefijo.'_cid2';
    $p2 = pcc_lanzar($this, ['modo' => 'confirmar', 'pago_id' => $b->pagoId, 'admin_id' => $this->actor->id, 'cid' => $cid]);
    pcc_esperar(fn () => pcc_hayEsperaDeBloqueo($cid) || ! $p2->isRunning(), 'que la segunda espere el bloqueo');
    $esperaba = pcc_hayEsperaDeBloqueo($cid);
    unlink($retener);
    [$r1, $r2] = [pcc_resultado($p1), pcc_resultado($p2)];

    pcc_guardarEvidencia('CONC-6', compact('pedido', 'r1', 'r2', 'esperaba'));

    // Esperado (independiente): 15.000 + 15.000 = 30.000 = total de la factura → `paid`, dos Transactions, ambos confirmados.
    expect($esperaba)->toBeTrue()
        ->and([$r1['ok'], $r2['ok']])->toBe([true, true])
        ->and((float) DB::table('order_transactions')->where('invoice_id', $factura)->sum('amount'))->toBe(30000.0)
        ->and(DB::table('invoices')->where('id', $factura)->value('state'))->toBe('paid')
        ->and(DB::table('bylopez_va_pagos')->where('customer_id', $this->cliente->id)->where('estado', 'confirmado')->count())->toBe(2);
});
