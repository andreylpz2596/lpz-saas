<?php

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

require_once __DIR__.'/../helpersD10.php';

/*
| D10-04 — concurrencia REAL: dos administradores consumen a la vez el MISMO credito de 20.000 (uno lo APLICA a otro pedido y otro lo
| REEMBOLSA). Solo lpz_saas_testing.
|
| Ejecutar:  vendor/bin/pest packages/ByLopez/VentasAsesor/tests/Concurrencia/D10CreditoConcurrenteTest.php
|
| - Sin DatabaseTransactions: los fixtures se CONFIRMAN (otra conexion no veria una transaccion abierta) y se borran al final.
| - Dos procesos PHP reales (proceso_credito.php), cada uno con su conexion y los servicios reales.
| - Entrelazado determinista: el padre toma el bloqueo del pedido ORIGEN; ambos hijos arrancan y el padre espera a verlos a los dos
|   en espera de bloqueo antes de soltarlo. Asi los dos hicieron TODO lo previo a su bloqueo y solo uno puede ganar.
| - El resultado esperado se calcula aqui, desde la base: credito generado 20.000 → consumido final 20.000 (nunca 40.000).
*/

abstract class D10ConcurrenciaTestCase extends TestCase
{
    public function beginDatabaseTransaction() {}
}

uses(D10ConcurrenciaTestCase::class)->group('concurrencia');

function d10c_limpiar(array $pedidos, int $clienteId, array $admins, array $roles): void
{
    $pagos = DB::table('bylopez_va_pagos')->where('customer_id', $clienteId)->pluck('id')->all();
    DB::table('bylopez_va_creditos_cliente_movimientos')->where('customer_id', $clienteId)->whereNotNull('revierte_id')->delete();
    DB::table('bylopez_va_creditos_cliente_movimientos')->where('customer_id', $clienteId)->delete();
    DB::table('bylopez_va_ajustes_obligacion')->where('customer_id', $clienteId)->delete();
    DB::table('bylopez_va_pagos_eventos')->whereIn('pago_id', $pagos ?: [0])->delete();
    DB::table('bylopez_va_aplicaciones_pago')->whereIn('pago_id', $pagos ?: [0])->delete();
    DB::table('bylopez_va_pagos')->whereIn('id', $pagos ?: [0])->delete();
    DB::table('bylopez_va_comision_reevaluaciones')->whereIn('order_id', $pedidos)->delete();
    DB::table('order_transactions')->whereIn('order_id', $pedidos)->delete();
    DB::table('invoices')->whereIn('order_id', $pedidos)->delete();
    DB::table('order_items')->whereIn('order_id', $pedidos)->delete();
    DB::table('order_payment')->whereIn('order_id', $pedidos)->delete();
    DB::table('orders')->whereIn('id', $pedidos)->delete();
    DB::table('customers')->where('id', $clienteId)->delete();
    DB::table('admins')->whereIn('id', $admins)->delete();
    DB::table('roles')->whereIn('id', $roles)->delete();
}

function d10c_lanzar(array $parametros): Process
{
    $proceso = new Process([PHP_BINARY, __DIR__.'/proceso_credito.php', base64_encode(json_encode($parametros))], base_path(), ['APP_ENV' => 'testing'], null, 180);
    $proceso->start();

    return $proceso;
}

function d10c_resultado(Process $proceso): array
{
    $proceso->wait();
    $lineas = array_values(array_filter(explode("\n", trim($proceso->getOutput()))));
    $json = json_decode((string) end($lineas), true);

    if (! is_array($json)) {
        throw new RuntimeException('El proceso hijo no devolvio JSON. Salida: '.$proceso->getOutput().' Error: '.$proceso->getErrorOutput());
    }

    return $json;
}

function d10c_enEspera(string $archivoCid): bool
{
    $cid = is_file($archivoCid) ? (int) file_get_contents($archivoCid) : 0;

    return $cid > 0 && DB::table('information_schema.INNODB_TRX')->where('trx_mysql_thread_id', $cid)->where('trx_state', 'LOCK WAIT')->exists();
}

test('D10-04 (real) aplicar y reembolsar el mismo credito a la vez: solo uno gana y el disponible nunca queda negativo', function () {
    $e = d10_escenario();
    $prefijo = sys_get_temp_dir().DIRECTORY_SEPARATOR.'d10c_'.substr(uniqid(), -8);
    $cids = [$prefijo.'_1', $prefijo.'_2'];
    $pedidos = [(int) $e['a']->id, (int) $e['b']->id];
    $admin = $e['admin'];
    $cliente = (int) $e['cliente']->id;

    try {
        expect(d10_credito($e['a']->id)['disponible'])->toBe(20000.0);

        // El padre retiene el bloqueo del pedido origen: los dos hijos llegaran a su propio bloqueo y esperaran.
        DB::beginTransaction();
        DB::table('orders')->where('id', $e['a']->id)->lockForUpdate()->value('id');

        $base = ['admin_id' => $admin->id, 'origen' => $e['a']->id, 'monto' => '20000'];
        $p1 = d10c_lanzar($base + ['modo' => 'aplicar', 'destino' => $e['b']->id, 'clave' => 'd10c-aplicar-'.uniqid(), 'cid' => $cids[0]]);
        $p2 = d10c_lanzar($base + ['modo' => 'reembolsar', 'clave' => 'd10c-reembolso-'.uniqid(), 'cid' => $cids[1]]);

        $limite = microtime(true) + 150;

        while (! (d10c_enEspera($cids[0]) && d10c_enEspera($cids[1]))) {
            if (microtime(true) > $limite || (! $p1->isRunning() && ! $p2->isRunning())) {
                throw new RuntimeException('Los dos procesos no llegaron a esperar el bloqueo. '.$p1->getOutput().$p2->getOutput().$p1->getErrorOutput().$p2->getErrorOutput());
            }

            usleep(50_000);
        }

        DB::commit();

        $r = [d10c_resultado($p1), d10c_resultado($p2)];
        $gano = array_values(array_filter($r, fn ($x) => $x['ok']));
        $perdio = array_values(array_filter($r, fn ($x) => ! $x['ok']));
        $consumido = (float) DB::table('bylopez_va_creditos_cliente_movimientos as m')
            ->leftJoin('bylopez_va_creditos_cliente_movimientos as v', 'v.revierte_id', '=', 'm.id')
            ->where('m.order_origen_id', $e['a']->id)->whereNull('v.id')->sum('m.monto');

        expect($gano)->toHaveCount(1)->and($perdio)->toHaveCount(1)
            ->and($perdio[0]['codigo'] ?? null)->toBe('credito_insuficiente')
            ->and($consumido)->toBe(20000.0)
            ->and(d10_credito($e['a']->id)['disponible'])->toBe(0.0)
            ->and(DB::table('bylopez_va_creditos_cliente_movimientos')->where('order_origen_id', $e['a']->id)->count())->toBe(1);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        foreach ($cids as $f) {
            @unlink($f);
        }

        d10c_limpiar($pedidos, $cliente, [(int) $admin->id], [(int) $admin->role_id]);
    }
});
