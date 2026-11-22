<?php

use ByLopez\VentasAsesor\Models\CambioPrecioGrupo;
use ByLopez\VentasAsesor\Models\LotePrecioGrupo;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

require_once __DIR__.'/../helpersPreciosGrupo.php';

/*
| «Precios por grupo»: DOS confirmaciones simultaneas sobre la MISMA variante y grupo, con procesos PHP reales (cada uno con su
| conexion). Solo lpz_saas_testing.
|
| Ejecutar:  vendor/bin/pest packages/ByLopez/VentasAsesor/tests/Concurrencia/PreciosGrupoConcurrenteTest.php
|
| - Ambos previsualizan sobre el MISMO estado (fijo 8.000) y quedan esperando al confirmar porque el padre tiene bloqueada la
|   variante. El padre espera a verlos a los dos en espera de bloqueo y suelta.
| - Esperado: UNO aplica y el otro recibe `conflicto` (relee tras obtener el bloqueo); nunca dos lotes ni una perdida silenciosa.
| - Mismo token dos veces a la vez: un lote; el otro devuelve el ya aplicado.
*/

abstract class PgConcurrenciaTestCase extends TestCase
{
    public function beginDatabaseTransaction() {}
}

uses(PgConcurrenciaTestCase::class)->group('concurrencia');

beforeEach(function () {
    $this->prefijo = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pgc_'.substr(uniqid(), -8);
    $this->procesos = [];

    [$this->padre, $this->variantes] = pg_catalogo(10000.0);
    $this->v = $this->variantes[0];
    $this->grupo = f03b_grupo('PGC');
    $this->admin = f03b_admin();
    pg_regla($this->v, $this->grupo, 1, 'fixed', 8000);
});

afterEach(function () {
    foreach ($this->procesos as $p) {
        if ($p->isRunning()) {
            $p->stop(0);
        }
    }

    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    $productos = [$this->padre->id, ...array_map(fn ($v) => $v->id, $this->variantes)];
    $lotes = LotePrecioGrupo::query()->where('customer_group_id', $this->grupo->id)->pluck('id')->all();

    DB::statement('SET FOREIGN_KEY_CHECKS=0');

    try {
        DB::table('bylopez_va_precio_grupo_cambios')->whereIn('lote_id', $lotes)->delete();
        DB::table('bylopez_va_precio_grupo_lotes')->whereIn('id', $lotes)->delete();

        foreach (DB::select('select table_name as t from information_schema.columns where table_schema = database() and column_name = ?', ['product_id']) as $f) {
            DB::table($f->t)->whereIn('product_id', $productos)->delete();
        }

        DB::table('products')->whereIn('id', $productos)->delete();
        DB::table('customer_groups')->where('id', $this->grupo->id)->delete();
        DB::table('admins')->where('id', $this->admin->id)->delete();
        DB::table('roles')->where('id', $this->admin->role_id)->delete();
    } finally {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        foreach (glob($this->prefijo.'*') ?: [] as $archivo) {
            @unlink($archivo);
        }
    }
});

function pgc_lanzar($test, string $nombre, string $valor): array
{
    $p = ['admin_id' => $test->admin->id, 'grupo' => $test->grupo->id, 'variante' => $test->v->id, 'valor' => $valor,
        'cid' => $test->prefijo.'_cid_'.$nombre, 'listo' => $test->prefijo.'_listo_'.$nombre];
    $proceso = new Process([PHP_BINARY, __DIR__.'/proceso_precios_grupo.php', base64_encode(json_encode($p))], base_path(), ['APP_ENV' => 'testing'], null, 180);
    $proceso->start();
    $test->procesos[] = $proceso;

    return [$proceso, $p];
}

function pgc_enEspera(string $archivoCid): bool
{
    $cid = is_file($archivoCid) ? (int) file_get_contents($archivoCid) : 0;

    return $cid > 0 && (
        DB::table('information_schema.INNODB_TRX')->where('trx_mysql_thread_id', $cid)->where('trx_state', 'LOCK WAIT')->exists()
        || DB::table('information_schema.PROCESSLIST')->where('id', $cid)->where('time', '>=', 1)->where('info', 'like', '%for update%')->exists()
    );
}

function pgc_esperar(callable $condicion, string $que, int $segundos = 150): void
{
    $limite = microtime(true) + $segundos;

    while (! $condicion()) {
        if (microtime(true) > $limite) {
            throw new RuntimeException("Tiempo agotado esperando: {$que}.");
        }

        usleep(50_000);
    }
}

function pgc_resultado(Process $proceso): array
{
    $proceso->wait();
    $lineas = array_values(array_filter(explode("\n", trim($proceso->getOutput()))));
    $json = json_decode((string) end($lineas), true);

    if (! is_array($json)) {
        throw new RuntimeException('El proceso hijo no devolvio JSON. Salida: '.$proceso->getOutput().' Error: '.$proceso->getErrorOutput());
    }

    return $json;
}

/** El padre bloquea la variante, lanza los dos hijos, espera a que ambos esten en espera y suelta. */
function pgc_carrera($test, string $valorA, string $valorB): array
{
    DB::beginTransaction();
    DB::table('products')->where('id', $test->v->id)->lockForUpdate()->first();

    [$a, $pa] = pgc_lanzar($test, 'a', $valorA);
    [$b, $pb] = pgc_lanzar($test, 'b', $valorB);

    pgc_esperar(fn () => (pgc_enEspera($pa['cid']) && pgc_enEspera($pb['cid'])) || ! $a->isRunning() || ! $b->isRunning(), 'que los dos hijos esperen el bloqueo');
    expect(file_exists($pa['listo']) && file_exists($pb['listo']))->toBeTrue('ambos debian previsualizar antes de esperar: '.$a->getErrorOutput().$b->getErrorOutput());

    DB::rollBack();

    return [pgc_resultado($a), pgc_resultado($b)];
}

test('PGC-1 dos ediciones distintas a la vez: una aplica, la otra recibe conflicto; un lote, sin perdida silenciosa', function () {
    [$ra, $rb] = pgc_carrera($this, '6000', '6500');

    $ok = array_values(array_filter([$ra, $rb], fn ($r) => $r['ok']));
    $no = array_values(array_filter([$ra, $rb], fn ($r) => ! $r['ok']));

    expect($ok)->toHaveCount(1, json_encode([$ra, $rb]))
        ->and($no[0]['codigo'] ?? $no[0])->toBe('conflicto')
        ->and((float) DB::table('product_customer_group_prices')->where('product_id', $this->v->id)->where('customer_group_id', $this->grupo->id)->value('value'))->toBe((float) $ok[0]['valor'])
        ->and(DB::table('product_customer_group_prices')->where('product_id', $this->v->id)->where('customer_group_id', $this->grupo->id)->count())->toBe(1)
        ->and(LotePrecioGrupo::query()->where('customer_group_id', $this->grupo->id)->count())->toBe(1)
        ->and(CambioPrecioGrupo::query()->where('customer_group_id', $this->grupo->id)->count())->toBe(1);
});

test('PGC-2 la MISMA edicion enviada dos veces a la vez: un solo lote, el otro devuelve el ya aplicado', function () {
    [$ra, $rb] = pgc_carrera($this, '6000', '6000');

    expect($ra['ok'] && $rb['ok'])->toBeTrue(json_encode([$ra, $rb]))
        ->and([$ra['ya_aplicado'], $rb['ya_aplicado']])->toEqualCanonicalizing([false, true])
        ->and($ra['lote'])->toBe($rb['lote'])
        ->and(LotePrecioGrupo::query()->where('customer_group_id', $this->grupo->id)->count())->toBe(1)
        ->and(CambioPrecioGrupo::query()->where('customer_group_id', $this->grupo->id)->count())->toBe(1);
});
