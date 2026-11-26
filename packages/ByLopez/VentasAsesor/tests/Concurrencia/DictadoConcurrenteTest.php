<?php

use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Services\Catalogo\CodigoSkuService;
use ByLopez\VentasAsesor\Services\Venta\NuevaVentaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Webkul\Product\Models\Product;

require_once __DIR__.'/../helpersFase03b.php';
require_once __DIR__.'/../helpersFase03f.php';
require_once dirname(__DIR__, 3).'/CorteConfeccion/tests/Support/BaseExclusiva.php';

/*
| DIC-03 — Doc. 34 · Guardado del dictado con DOS SOLICITUDES REALES SIMULTANEAS (procesos PHP con su conexion). Solo
| lpz_saas_testing, sin DatabaseTransactions: los datos se CONFIRMAN con ids de esta corrida y se borran por id al final.
|
| Determinismo: un tercer proceso retiene la fila del borrador (FOR UPDATE); las dos peticiones arrancan juntas y se
| comprueba en el motor (INNODB_TRX / PROCESSLIST) que AMBAS esperan antes de soltarlo. Sin pausas fijas.
|  1. Misma operacion (doble clic simultaneo): se suma UNA vez; una respuesta es la original y la otra la repetida.
|  2. Operaciones distintas sobre la misma venta: se suman las dos (sin actualizacion perdida).
*/

abstract class DictadoConcurrenciaTestCase extends TestCase
{
    public function beginDatabaseTransaction() {}
}

uses(DictadoConcurrenciaTestCase::class)->group('concurrencia');

beforeEach(function () {
    // Hasta 30 s: las conexiones de los procesos hijos de la prueba anterior tardan unos segundos en cerrarse.
    if (($motivo = qa_motivo_base_compartida(30)) !== null) {
        $this->markTestSkipped($motivo);
    }

    Mail::fake();
    f03f_activarCarriers();
    $this->prefijo = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dic03_'.substr(uniqid(), -8);
    $this->procesos = [];
    $this->ids = ['opciones' => [], 'colores' => [], 'productos' => [], 'clientes' => [], 'grupos' => [], 'admins' => [], 'roles' => [], 'borradores' => [], 'carritos' => []];

    // Catalogo propio de la corrida: codigo de color libre y opcion de color con nombre unico.
    $codigo = (int) (DB::table(CodigoSkuService::TABLA_COLORES)->max('codigo') ?? 900) + 1;
    $aid = DB::table('attributes')->where('code', 'color')->value('id');
    $opcion = DB::table('attribute_options')->insertGetId(['attribute_id' => $aid, 'admin_name' => 'QA Dic03 '.$this->prefijo, 'sort_order' => 999]);
    $this->ids['opciones'][] = $opcion;
    $this->ids['colores'][] = DB::table(CodigoSkuService::TABLA_COLORES)->insertGetId(['attribute_option_id' => $opcion, 'codigo' => $codigo, 'created_at' => now(), 'updated_at' => now()]);

    $padre = f03b_configurable(10000.0);
    DB::table('products')->where('id', $padre->id)->update(['sku' => $codigo.'D-DZ']);
    DB::table('product_flat')->where('product_id', $padre->id)->update(['status' => 1]);
    $variante = $padre->variants()->orderBy('id')->first();
    DB::table('products')->where('id', $variante->id)->update(['sku' => $codigo.'D-DZ-M']);
    f03b_stock(Product::find($variante->id), 500);
    $this->ids['productos'] = [...$padre->variants()->pluck('id')->all(), (int) $padre->id];
    $this->padre = (int) $padre->id;
    $this->variante = (int) $variante->id;

    $grupo = f03b_grupo('D3');
    $cliente = f03b_cliente($grupo);
    $this->asesor = f03b_admin();
    $this->ids['grupos'][] = $grupo->id;
    $this->ids['clientes'][] = $cliente->id;
    $this->ids['admins'][] = $this->asesor->id;
    $this->ids['roles'][] = $this->asesor->role_id;

    // La venta ya tiene 3 unidades de la variante (lo que «ya hay»).
    auth()->guard('admin')->setUser($this->asesor);
    $estado = app(NuevaVentaService::class)->iniciar($cliente->id, $this->asesor, 'whatsapp');
    $this->borrador = (int) $estado->toArray()['borrador']['id'];
    app(NuevaVentaService::class)->aplicarTallas($this->borrador, $this->padre, [$this->variante => 3], $this->asesor);
    $this->ids['borradores'][] = $this->borrador;
    $this->ids['carritos'][] = (int) BorradorVenta::query()->whereKey($this->borrador)->value('cart_id');
});

afterEach(function () {
    foreach ($this->procesos ?? [] as $proceso) {
        if ($proceso->isRunning()) {
            $proceso->stop(0);
        }
    }
    foreach (['_retener', '_senal', '_barrera'] as $s) {
        if (isset($this->prefijo) && file_exists($this->prefijo.$s)) {
            unlink($this->prefijo.$s);
        }
    }
    if (! isset($this->ids)) {
        return;
    }

    $i = $this->ids;
    DB::table('bylopez_va_dictado_operaciones')->whereIn('admin_id', $i['admins'] ?: [0])->delete();
    DB::table('bylopez_va_borrador_solicitudes')->whereIn('borrador_id', $i['borradores'] ?: [0])->delete();
    DB::table('bylopez_va_borrador_grupo_eventos')->whereIn('borrador_id', $i['borradores'] ?: [0])->delete();
    DB::table('bylopez_va_borradores_venta')->whereIn('id', $i['borradores'] ?: [0])->delete();
    DB::table('cart_items')->whereIn('cart_id', $i['carritos'] ?: [0])->delete();
    DB::table('cart')->whereIn('id', $i['carritos'] ?: [0])->delete();
    DB::table('customers')->whereIn('id', $i['clientes'] ?: [0])->delete();
    DB::table('customer_groups')->whereIn('id', $i['grupos'] ?: [0])->delete();
    DB::table('products')->whereIn('id', $i['productos'] ?: [0])->delete();
    DB::table(CodigoSkuService::TABLA_COLORES)->whereIn('id', $i['colores'] ?: [0])->delete();
    DB::table('attribute_options')->whereIn('id', $i['opciones'] ?: [0])->delete();
    DB::table('admins')->whereIn('id', $i['admins'] ?: [0])->delete();
    DB::table('roles')->whereIn('id', $i['roles'] ?: [0])->delete();

    // Cada prueba arranca con su propia aplicacion: sin cerrar esta conexion, la siguiente la veria como «ajena» y se saltaria.
    DB::disconnect();
});

function dic03_lanzar($test, array $parametros): Process
{
    $proceso = new Process([PHP_BINARY, __DIR__.'/proceso_dictado.php', base64_encode(json_encode($parametros))], base_path(), ['APP_ENV' => 'testing'], null, 180);
    $proceso->start();
    $test->procesos[] = $proceso;

    return $proceso;
}

function dic03_esperar(callable $condicion, string $que, int $segundos = 150): void
{
    $limite = microtime(true) + $segundos;
    while (! $condicion()) {
        if (microtime(true) > $limite) {
            throw new RuntimeException("Tiempo agotado esperando: {$que}. INNODB_TRX: "
                .json_encode(DB::select('SELECT trx_state, trx_operation_state FROM information_schema.INNODB_TRX'))
                .' PROCESSLIST: '.json_encode(DB::select("SELECT command, time, state, LEFT(info, 120) AS info FROM information_schema.PROCESSLIST WHERE db = DATABASE() AND command <> 'Sleep'")));
        }
        usleep(50_000);
    }
}

/** Conexiones de esta base esperando un bloqueo (InnoDB lo marca LOCK WAIT o la consulta lleva >= 1 s ejecutandose). */
function dic03_esperando(): int
{
    $lockWait = (int) DB::selectOne("SELECT COUNT(*) AS c FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'")->c;
    $largas = (int) DB::selectOne("SELECT COUNT(*) AS c FROM information_schema.PROCESSLIST WHERE id <> CONNECTION_ID() AND db = DATABASE()
        AND command IN ('Query', 'Execute') AND time >= 1 AND (info LIKE '%for update%' OR info LIKE '%bylopez_va_dictado_operaciones%')")->c;

    return max($lockWait, $largas);
}

function dic03_resultado(Process $p): array
{
    $p->wait();
    $lineas = array_values(array_filter(explode("\n", trim($p->getOutput()))));

    return json_decode((string) end($lineas), true) ?? ['ok' => false, 'error' => 'sin salida: '.substr($p->getErrorOutput(), -400)];
}

/** Retiene el borrador, lanza A y B juntas, comprueba que AMBAS esperan y suelta. */
function dic03_simultaneas($test, array $a, array $b): array
{
    $retener = $test->prefijo.'_retener';
    $senal = $test->prefijo.'_senal';
    $barrera = $test->prefijo.'_barrera';
    file_put_contents($retener, '1');
    file_put_contents($barrera, '1');

    $retenedor = dic03_lanzar($test, ['modo' => 'retener', 'borrador_id' => $test->borrador, 'senal' => $senal, 'retener' => $retener]);
    dic03_esperar(fn () => file_exists($senal), 'el retenedor bloquea el borrador');

    $base = ['modo' => 'agregar', 'borrador_id' => $test->borrador, 'admin_id' => $test->asesor->id, 'barrera' => $barrera];
    $pa = dic03_lanzar($test, $base + $a);
    $pb = dic03_lanzar($test, $base + $b);
    usleep(300_000);
    unlink($barrera);

    // Las dos peticiones quedan esperando a la vez (el retenedor no hace nada mientras tanto).
    dic03_esperar(fn () => dic03_esperando() >= 2, 'que las dos peticiones esperen a la vez');
    $esperando = dic03_esperando();
    unlink($retener);

    $ra = dic03_resultado($pa);
    $rb = dic03_resultado($pb);
    $retenedor->wait();

    return [$ra, $rb, $esperando];
}

function dic03_en_venta(int $borrador, int $variante): int
{
    $cartId = BorradorVenta::query()->whereKey($borrador)->value('cart_id');
    $padre = DB::table('cart_items as hijo')->join('cart_items as padre', 'padre.id', '=', 'hijo.parent_id')
        ->where('hijo.cart_id', $cartId)->where('hijo.product_id', $variante)->value('padre.quantity');

    return (int) $padre;
}

test('DIC03-1 doble clic SIMULTANEO (misma operacion): se suma una sola vez', function () {
    $op = 'op-dic03-1-'.substr(uniqid(), -8);
    $lineas = [['producto_id' => $this->padre, 'variante_id' => $this->variante, 'cantidad' => 2, 'sku' => 'x']];

    [$ra, $rb, $esperando] = dic03_simultaneas($this, ['operacion' => $op, 'lineas' => $lineas], ['operacion' => $op, 'lineas' => $lineas]);

    expect($esperando)->toBeGreaterThanOrEqual(2)
        ->and($ra['ok'])->toBeTrue($ra['error'] ?? '')->and($rb['ok'])->toBeTrue($rb['error'] ?? '')
        // Una respuesta es la que aplico; la otra, la misma operacion repetida (sin sumar).
        ->and([$ra['repetida'], $rb['repetida']])->toEqualCanonicalizing([false, true])
        ->and($ra['resumen'])->toBe($rb['resumen'])
        ->and(dic03_en_venta($this->borrador, $this->variante))->toBe(5)
        ->and(DB::table('bylopez_va_dictado_operaciones')->where('admin_id', $this->asesor->id)->where('operacion', $op)->count())->toBe(1);

    // Evidencia de simultaneidad: los dos intervalos de ejecucion se solapan.
    expect(max($ra['inicio'], $rb['inicio']))->toBeLessThan(min($ra['fin'], $rb['fin']));
});

test('DIC03-2 dos dictados distintos A LA VEZ sobre la misma venta: se suman los dos, sin perder ninguno', function () {
    $lineas = fn (int $c) => [['producto_id' => $this->padre, 'variante_id' => $this->variante, 'cantidad' => $c, 'sku' => 'x']];

    [$ra, $rb, $esperando] = dic03_simultaneas($this,
        ['operacion' => 'op-dic03-2a-'.substr(uniqid(), -8), 'lineas' => $lineas(2)],
        ['operacion' => 'op-dic03-2b-'.substr(uniqid(), -8), 'lineas' => $lineas(4)],
    );

    expect($esperando)->toBeGreaterThanOrEqual(2)
        ->and($ra['ok'])->toBeTrue($ra['error'] ?? '')->and($rb['ok'])->toBeTrue($rb['error'] ?? '')
        ->and([$ra['repetida'], $rb['repetida']])->toBe([false, false])
        // 3 que habia + 2 + 4 = 9: el segundo sumo sobre lo que dejo el primero (leido con el borrador bloqueado).
        ->and(dic03_en_venta($this->borrador, $this->variante))->toBe(9)
        ->and(collect([$ra['resumen'][0], $rb['resumen'][0]])->sortBy('antes')->map(fn ($r) => [$r['antes'], $r['despues']])->values()->all())
        ->toBeIn([[[3, 5], [5, 9]], [[3, 7], [7, 9]]])
        ->and(max($ra['inicio'], $rb['inicio']))->toBeLessThan(min($ra['fin'], $rb['fin']));
});
