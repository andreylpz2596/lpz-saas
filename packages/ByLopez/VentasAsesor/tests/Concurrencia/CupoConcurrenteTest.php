<?php

use ByLopez\VentasAsesor\Enums\EstadoBorradorVenta as Estado;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Services\Cupo\CupoCarteraClienteService;
use ByLopez\VentasAsesor\Services\Cupo\GestionCupoCarteraService;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Webkul\Sales\Models\Order;

require_once __DIR__.'/../helpersCupo.php';

/*
| Cupo de cartera (Doc. 35) — concurrencia REAL con procesos PHP independientes (CUPO-10). Solo lpz_saas_testing o la base exclusiva
| de la rama (la guarda de Tests\TestCase se aplica tambien en cada hijo).
|
| Ejecutar:  vendor/bin/pest packages/ByLopez/VentasAsesor/tests/Concurrencia/CupoConcurrenteTest.php
|
| - Sin DatabaseTransactions: otra conexion no veria datos de una transaccion abierta. Los fixtures se CONFIRMAN y se borran al terminar.
| - Cada hijo es proceso_confirmacion.php (el de 03F) con su propia conexion y los servicios reales; el cupo se activa por variable de
|   entorno del hijo (VENTASASESOR_CUPO_ACTIVO / VENTASASESOR_CARTERA_FECHA_CORTE), no por la configuracion del padre.
| - Las dos ventas de una carrera usan productos DISTINTOS: asi el bloqueo de `products` no las serializa y lo unico que lo hace es el
|   bloqueo del CLIENTE (si no, la prueba pasaria por la razon equivocada).
| - Entrelazado determinista con archivos de senal del padre y observando el estado real del motor (LOCK WAIT). Sin sleeps fijos.
*/

/** Base sin DatabaseTransactions: mantiene la guarda de Tests\TestCase. */
abstract class CupoConcurrenciaTestCase extends TestCase
{
    public function beginDatabaseTransaction() {}
}

uses(CupoConcurrenciaTestCase::class)->group('concurrencia');

beforeEach(function () {
    Mail::fake();
    f03f_activarCarriers();
    pagb_corte('2000-01-01');
    config(['ventasasesor.cupo.activo' => true]);

    $this->prefijo = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cupoc_'.substr(uniqid(), -8);
    $this->creados = ['clientes' => [], 'grupos' => [], 'productos' => [], 'admins' => [], 'roles' => [], 'borradores' => [], 'carritos' => [], 'pedidos' => []];
    $this->procesos = [];

    $this->admin = d10_admin();
    $this->asesor = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_MODULO, AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR, BorradorVentaService::PERMISO]);
    $this->creados['admins'] = [(int) $this->admin->id, (int) $this->asesor->id];
    $this->creados['roles'] = [(int) $this->admin->role_id, (int) $this->asesor->role_id];
});

afterEach(function () {
    foreach ($this->procesos as $proceso) {
        if ($proceso->isRunning()) {
            $proceso->stop(0);
        }
    }

    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    try {
        cupoc_limpiar($this->creados);
    } finally {
        foreach (['_barrera', '_s', '_r'] as $sufijo) {
            @unlink($this->prefijo.$sufijo);
        }
    }
});

// ------------------------------------------------------------------ fixtures confirmados

function cupoc_cliente($test)
{
    $grupo = f03b_grupo('CQ');
    $cliente = f03b_cliente($grupo);
    $test->creados['grupos'][] = $grupo->id;
    $test->creados['clientes'][] = (int) $cliente->id;

    return $cliente;
}

/** Venta lista de UN producto simple propio (precio y stock), confirmable por un hijo. */
function cupoc_venta($test, $cliente, float $precio): array
{
    $simple = f03f_simple($precio);
    f03b_stock($simple, 20);
    $test->creados['productos'][] = (int) $simple->id;
    $borrador = f03f_borradorListo($cliente->id, $test->asesor, [[$simple, $simple, 1]]);
    $test->creados['borradores'][] = (int) $borrador->id;
    $test->creados['carritos'][] = (int) $borrador->cart_id;

    return [$borrador, f03f_total($borrador)];
}

/**
 * Borra TODO lo que creo la prueba, por ids exactos (nunca por ventana de tiempo): primero los dependientes por FK RESTRICT (a cualquier
 * profundidad, con el mapa de claves foraneas leido UNA vez: information_schema tarda segundos en esta base) y luego la fila. Se hace por
 * Query Builder a proposito: el historial del cupo es de solo adicion por modelo y estos son fixtures de prueba.
 */
function cupoc_limpiar(array $c): void
{
    $pedidos = DB::table('orders')->whereIn('customer_id', $c['clientes'] ?: [0])->orWhereIn('cart_id', $c['carritos'] ?: [0])->pluck('id')->all();

    cupoc_borrar('orders', 'id', $pedidos);                       // incluye los borradores que apuntan al pedido
    cupoc_borrar('bylopez_va_borradores_venta', 'id', $c['borradores']);
    DB::table('cart')->whereIn('id', $c['carritos'] ?: [0])->delete();
    cupoc_borrar('customers', 'id', $c['clientes']);              // incluye politica e historial del cupo
    cupoc_borrar('customer_groups', 'id', $c['grupos']);
    cupoc_borrar('products', 'id', $c['productos']);
    cupoc_borrar('admins', 'id', $c['admins']);                   // incluye el perfil de asesor
    cupoc_borrar('roles', 'id', $c['roles']);
}

/**
 * Clave foranea RESTRICT hacia `id`: tabla referenciada => [[tabla hija, columna]]. UNA consulta por proceso.
 *
 * @return array<string, list<array{0: string, 1: string}>>
 */
function cupoc_fks(): array
{
    static $mapa = null;

    if ($mapa !== null) {
        return $mapa;
    }

    $mapa = [];

    foreach (DB::select("SELECT k.REFERENCED_TABLE_NAME AS padre, k.TABLE_NAME AS hija, k.COLUMN_NAME AS columna
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_COLUMN_NAME = 'id' AND r.DELETE_RULE NOT IN ('CASCADE', 'SET NULL')") as $f) {
        $mapa[$f->padre][] = [$f->hija, $f->columna];
    }

    return $mapa;
}

/** Borra las filas de `$tabla` con `$columna` en `$valores`, despues de borrar (recursivo) las que las referencian. */
function cupoc_borrar(string $tabla, string $columna, array $valores, int $nivel = 0): void
{
    if ($valores === [] || $nivel > 8) {
        return;
    }

    $hijas = cupoc_fks()[$tabla] ?? [];

    if ($hijas !== []) {
        $ids = DB::table($tabla)->whereIn($columna, $valores)->pluck('id')->all();

        foreach ($hijas as [$hija, $col]) {
            cupoc_borrar($hija, $col, $ids, $nivel + 1);
        }
    }

    DB::table($tabla)->whereIn($columna, $valores)->delete();
}

// ------------------------------------------------------------------ procesos y sincronizacion

function cupoc_lanzar($test, array $parametros): Process
{
    $proceso = new Process(
        [PHP_BINARY, __DIR__.'/proceso_confirmacion.php', base64_encode(json_encode($parametros))],
        base_path(),
        // El hijo carga .env.testing; estas variables mandan sobre el archivo (Dotenv no pisa lo ya definido).
        ['APP_ENV' => 'testing', 'VENTASASESOR_CUPO_ACTIVO' => 'true', 'VENTASASESOR_CARTERA_FECHA_CORTE' => '2000-01-01'],
        null,
        180,
    );

    $proceso->start();
    $test->procesos[] = $proceso;

    return $proceso;
}

/** @return array{ok: bool, order_id?: int, ya_existia?: bool, clase?: string, codigo?: ?string, detalles?: ?array, sql_error?: ?int} */
function cupoc_resultado(Process $proceso): array
{
    $proceso->wait();
    $lineas = array_values(array_filter(explode("\n", trim($proceso->getOutput()))));
    $json = json_decode((string) end($lineas), true);

    if (! is_array($json)) {
        throw new RuntimeException('El proceso hijo no devolvio JSON. Salida: '.$proceso->getOutput().' Error: '.$proceso->getErrorOutput());
    }

    return $json;
}

function cupoc_confirmacion($test, BorradorVenta $borrador, float $total, array $extra = []): array
{
    return ['modo' => 'confirmar', 'borrador_id' => $borrador->id, 'admin_id' => $test->asesor->id, 'total' => $total] + $extra;
}

function cupoc_esperar(callable $condicion, string $que, int $segundos = 150): void
{
    $limite = microtime(true) + $segundos;

    while (! $condicion()) {
        if (microtime(true) > $limite) {
            $procesos = array_map(fn (Process $p) => ['vivo' => $p->isRunning(), 'salida' => substr($p->getOutput(), -400), 'error' => substr($p->getErrorOutput(), -400)], test()->procesos ?? []);

            throw new RuntimeException("Tiempo agotado esperando: {$que}. Procesos: ".json_encode($procesos)
                .' INNODB_TRX: '.json_encode(DB::select('SELECT trx_state, trx_operation_state, trx_tables_locked, trx_rows_locked FROM information_schema.INNODB_TRX')));
        }

        usleep(50_000);
    }
}

/**
 * Otro proceso espera un bloqueo de fila. Dos senales (MariaDB 10.4 no siempre lo refleja en INNODB_TRX): estado LOCK WAIT, o un
 * FOR UPDATE de otra conexion que dura >= 1 s mientras el retenido esta inactivo.
 */
function cupoc_hayEsperaDeBloqueo(): bool
{
    return (int) DB::selectOne("SELECT COUNT(*) AS c FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'")->c > 0
        || (int) DB::selectOne("SELECT COUNT(*) AS c FROM information_schema.PROCESSLIST WHERE id <> CONNECTION_ID() AND db = DATABASE() AND command IN ('Query', 'Execute') AND time >= 1 AND info LIKE '%for update%'")->c > 0;
}

/** A queda DENTRO de la transaccion del pedido, DESPUES del cerco (con el bloqueo del cliente tomado). */
function cupoc_retenerDentroDelPedido($test, array $parametrosA): array
{
    $n = ['senal' => $test->prefijo.'_s', 'retener' => $test->prefijo.'_r'];
    expect(touch($n['retener']))->toBeTrue();
    $a = cupoc_lanzar($test, $parametrosA + ['retener_en' => 'save.before'] + $n);
    cupoc_esperar(fn () => file_exists($n['senal']), 'A dentro del cerco');

    return [$a, $n];
}

// ------------------------------------------------------------------ CUPO-10: carrera real

test('CUPO-10 (real) cupo disponible 500.000 y dos ventas de 400.000 del MISMO cliente a la vez: solo una se confirma, la otra recibe cupo_excedido', function () {
    $cliente = cupoc_cliente($this);
    cupo_fijar($this->admin, $cliente, 500000);
    [$bA, $totalA] = cupoc_venta($this, $cliente, 400000);
    [$bB, $totalB] = cupoc_venta($this, $cliente, 400000);
    $barrera = $this->prefijo.'_barrera';
    expect(touch($barrera))->toBeTrue()->and($totalA)->toBe(400000.0)->and($totalB)->toBe(400000.0);

    $a = cupoc_lanzar($this, cupoc_confirmacion($this, $bA, $totalA, ['barrera' => $barrera]));
    $b = cupoc_lanzar($this, cupoc_confirmacion($this, $bB, $totalB, ['barrera' => $barrera]));
    @unlink($barrera);

    $resultados = [cupoc_resultado($a), cupoc_resultado($b)];
    $ok = array_values(array_filter($resultados, fn ($r) => $r['ok']));
    $fallo = array_values(array_filter($resultados, fn ($r) => ! $r['ok']));
    $c = cupo_de($cliente);

    expect($ok)->toHaveCount(1)->and($fallo)->toHaveCount(1)
        ->and($fallo[0]['codigo'])->toBe('cupo_excedido')->and($fallo[0]['detalles']['fase'])->toBe('cerco')
        ->and($fallo[0]['sql_error'] ?? null)->not->toBe(1213)
        ->and(Order::query()->whereIn('cart_id', [$bA->cart_id, $bB->cart_id])->count())->toBe(1)
        ->and($c->exposicion)->toBe(400000.0)->and($c->disponible)->toBe(100000.0)->and($c->exceso)->toBe(0.0)
        ->and(collect([$bA, $bB])->map(fn ($b) => BorradorVenta::query()->find($b->id)->estado)->all())->toContain(Estado::CONFIRMADO, Estado::ACTIVO);
});

test('CUPO-10b entrelazado forzado: A retenido DENTRO del pedido, B ESPERA el bloqueo del cliente y al seguir ve el pedido de A → cupo_excedido con la exposicion real', function () {
    $cliente = cupoc_cliente($this);
    cupo_fijar($this->admin, $cliente, 500000);
    [$bA, $totalA] = cupoc_venta($this, $cliente, 400000);
    [$bB, $totalB] = cupoc_venta($this, $cliente, 400000);

    [$a, $n] = cupoc_retenerDentroDelPedido($this, cupoc_confirmacion($this, $bA, $totalA));
    $b = cupoc_lanzar($this, cupoc_confirmacion($this, $bB, $totalB));
    cupoc_esperar(fn () => cupoc_hayEsperaDeBloqueo(), 'B bloqueado por el bloqueo del cliente que tiene A');

    // B no avanzo mientras A sigue dentro: ninguna de las dos ventas tiene pedido todavia.
    expect(Order::query()->whereIn('cart_id', [$bA->cart_id, $bB->cart_id])->count())->toBe(0);

    @unlink($n['retener']);
    $rA = cupoc_resultado($a);
    $rB = cupoc_resultado($b);

    expect($rA['ok'])->toBeTrue()
        ->and($rB['ok'])->toBeFalse()->and($rB['codigo'])->toBe('cupo_excedido')
        // La instantanea de B nacio DESPUES del bloqueo: vio el pedido de A ya confirmado (exposicion 400.000, no 0).
        ->and($rB['detalles'])->toMatchArray(['fase' => 'cerco', 'autorizado' => 500000, 'exposicion' => 400000, 'disponible' => 100000, 'nueva_obligacion' => 400000, 'exceso' => 300000])
        ->and(BorradorVenta::query()->find($bB->id)->estado)->toBe(Estado::ACTIVO)
        ->and(Order::query()->where('cart_id', $bB->cart_id)->count())->toBe(0)
        ->and(Order::query()->where('cart_id', $bA->cart_id)->count())->toBe(1);
});

test('CUPO-10c un cambio de cupo concurrente: B espera mientras el administrador baja el cupo y, al seguir, aplica el cupo NUEVO (no el que habia al empezar)', function () {
    $cliente = cupoc_cliente($this);
    cupo_fijar($this->admin, $cliente, 500000);
    [$bB, $totalB] = cupoc_venta($this, $cliente, 100000);

    // El administrador tiene el bloqueo del cliente en una transaccion abierta (como lo hace GestionCupoCarteraService) y baja el cupo.
    DB::beginTransaction();
    app(GestionCupoCarteraService::class)->establecer($this->admin, $cliente->id, '50000', 'activo', 'Se reduce por riesgo (concurrencia)');

    $b = cupoc_lanzar($this, cupoc_confirmacion($this, $bB, $totalB));
    cupoc_esperar(fn () => cupoc_hayEsperaDeBloqueo(), 'B esperando el bloqueo del cliente que tiene el cambio de cupo');
    DB::commit();

    $rB = cupoc_resultado($b);

    // Con el cupo VIEJO (500.000) la venta de 100.000 cabia; con el NUEVO (50.000) no.
    expect($rB['ok'])->toBeFalse()->and($rB['codigo'])->toBe('cupo_excedido')
        ->and($rB['detalles'])->toMatchArray(['autorizado' => 50000, 'exposicion' => 0, 'nueva_obligacion' => 100000, 'exceso' => 50000])
        ->and(Order::query()->where('cart_id', $bB->cart_id)->count())->toBe(0);
});

test('CUPO-10d dos administradores cambian el cupo a la vez: se serializan, ninguno pisa al otro y el historial encadena anterior → nuevo', function () {
    $cliente = cupoc_cliente($this);
    cupo_fijar($this->admin, $cliente, 100000, 'activo', 'Alta');

    DB::beginTransaction();
    app(GestionCupoCarteraService::class)->establecer($this->admin, $cliente->id, '200000', 'activo', 'Primer cambio');

    // El hijo es OTRO administrador autorizado: el rol designado como administrador se pasa por entorno (el padre solo lo fijo en su memoria).
    $hijo = new Process(
        [PHP_BINARY, __DIR__.'/proceso_cupo.php', base64_encode(json_encode([
            'admin_id' => $this->admin->id, 'customer_id' => $cliente->id, 'cupo' => '300000', 'estado' => 'activo', 'motivo' => 'Segundo cambio',
        ]))],
        base_path(),
        ['APP_ENV' => 'testing', 'VENTASASESOR_CREDITO_ROL_ADMIN_ID' => (string) $this->admin->role_id],
        null,
        180,
    );
    $hijo->start();
    $this->procesos[] = $hijo;
    cupoc_esperar(fn () => cupoc_hayEsperaDeBloqueo(), 'el segundo administrador espera el bloqueo del cliente');
    DB::commit();

    $r = cupoc_resultado($hijo);
    $h = DB::table('bylopez_va_clientes_cupo_cartera_historial')->where('customer_id', $cliente->id)->orderBy('id')->get(['cupo_anterior', 'cupo_nuevo', 'motivo']);

    expect($r)->toMatchArray(['ok' => true, 'accion' => 'cambio_cupo'])
        ->and($h)->toHaveCount(3)
        ->and([(float) $h[1]->cupo_anterior, (float) $h[1]->cupo_nuevo, $h[1]->motivo])->toBe([100000.0, 200000.0, 'Primer cambio'])
        ->and([(float) $h[2]->cupo_anterior, (float) $h[2]->cupo_nuevo, $h[2]->motivo])->toBe([200000.0, 300000.0, 'Segundo cambio'])
        ->and((float) DB::table('bylopez_va_clientes_cupo_cartera')->where('customer_id', $cliente->id)->value('cupo_autorizado'))->toBe(300000.0);
});

test('CUPO-10f (real) DOS ventas compiten por UNA autorizacion de exceso: solo una la consume y se confirma; la otra recibe cupo_sin_asignar', function () {
    $cliente = cupoc_cliente($this);   // sin cupo: toda venta necesita la autorizacion
    $auth = app(\ByLopez\VentasAsesor\Services\Cupo\ExcesoCupoService::class)->autorizar($this->admin, (int) $cliente->id, 400000, 'Una sola venta de contado');
    [$bA, $totalA] = cupoc_venta($this, $cliente, 400000);
    [$bB, $totalB] = cupoc_venta($this, $cliente, 400000);
    $barrera = $this->prefijo.'_barrera';
    expect(touch($barrera))->toBeTrue();

    $a = cupoc_lanzar($this, cupoc_confirmacion($this, $bA, $totalA, ['barrera' => $barrera]));
    $b = cupoc_lanzar($this, cupoc_confirmacion($this, $bB, $totalB, ['barrera' => $barrera]));
    @unlink($barrera);

    $resultados = [cupoc_resultado($a), cupoc_resultado($b)];
    $ok = array_values(array_filter($resultados, fn ($r) => $r['ok']));
    $fallo = array_values(array_filter($resultados, fn ($r) => ! $r['ok']));
    $fila = \ByLopez\VentasAsesor\Models\ClienteCupoCarteraExceso::query()->findOrFail($auth['id']);

    expect($ok)->toHaveCount(1)->and($fallo)->toHaveCount(1)->and($fallo[0]['codigo'])->toBe('cupo_sin_asignar')
        ->and($fallo[0]['sql_error'] ?? null)->not->toBe(1213)
        ->and(Order::query()->whereIn('cart_id', [$bA->cart_id, $bB->cart_id])->count())->toBe(1)
        ->and($fila->estado)->toBe('consumida')->and($fila->order_id)->toBe($ok[0]['order_id'])->and((float) $fila->exceso_consumido)->toBe(400000.0)
        ->and(\ByLopez\VentasAsesor\Models\ClienteCupoCarteraExceso::query()->where('customer_id', $cliente->id)->where('estado', 'consumida')->count())->toBe(1);
});

test('CUPO-10e el bloqueo es POR CLIENTE: mientras A tiene retenido a un cliente, la venta de OTRO cliente se confirma sin esperar', function () {
    $x = cupoc_cliente($this);
    $y = cupoc_cliente($this);
    cupo_fijar($this->admin, $x, 500000);
    cupo_fijar($this->admin, $y, 500000);
    [$bX, $totalX] = cupoc_venta($this, $x, 100000);
    [$bY, $totalY] = cupoc_venta($this, $y, 100000);

    [$a, $n] = cupoc_retenerDentroDelPedido($this, cupoc_confirmacion($this, $bX, $totalX));
    $rY = cupoc_resultado(cupoc_lanzar($this, cupoc_confirmacion($this, $bY, $totalY)));

    // Y termino mientras X sigue retenido: el cupo no es un candado global.
    expect($rY['ok'])->toBeTrue()->and(Order::query()->where('cart_id', $bY->cart_id)->count())->toBe(1)
        ->and(Order::query()->where('cart_id', $bX->cart_id)->count())->toBe(0);

    @unlink($n['retener']);
    expect(cupoc_resultado($a)['ok'])->toBeTrue()->and(Order::query()->where('cart_id', $bX->cart_id)->count())->toBe(1);
});
