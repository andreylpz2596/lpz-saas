<?php

require_once __DIR__.'/../Support/QaFlujo.php';
require_once __DIR__.'/../Support/QaCartera.php';
require_once __DIR__.'/../Support/BaseExclusiva.php';

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenConfeccionDetalle;
use ByLopez\CorteConfeccion\Models\Reasignacion;
use ByLopez\CorteConfeccion\Models\Rollo;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenCorteService;
use ByLopez\CorteConfeccion\Tests\Support\CatalogoCorteFixtures;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
| Reasignacion (R13/R14) con procesos PHP reales, cada uno con SU conexion. Solo lpz_saas_testing; CONFIRMA datos y luego los borra.
|
| Ejecutar:  vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/Concurrencia
| (NO forma parte de las testsuites normales: no paralelizar con otros procesos sobre la misma base.)
|
| Los procesos arrancan a la vez con una barrera. El resultado debe ser UNO de los ordenes legitimos y las cantidades nunca
| se pierden ni se duplican: los esperados se calculan aqui, no con el servicio probado.
*/

uses()->group('concurrencia');

/**
 * Para que OTRO proceso vea las filas, la transaccion ambiental de la prueba se confirma y se reabre de inmediato (mismo recurso
 * que RET-16): todo lo creado se borra a mano en afterEach, sin depender del rollback automatico.
 */
function rcc_confirmar(): void
{
    DB::commit();
    DB::beginTransaction();
}

beforeEach(function () {
    $this->prefijo = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rcc_'.substr(uniqid(), -8);
    $this->procesos = [];
    $this->idsPrevios = null;

    // La limpieza borra por "id > maximo previo": con otra sesion escribiendo, borraria filas ajenas.
    if ($motivo = qa_motivo_base_compartida()) {
        $this->markTestSkipped($motivo);
    }

    $this->idsPrevios = rcc_snapshot();
});

afterEach(function () {
    if ($this->idsPrevios === null) {
        return;
    }

    foreach ($this->procesos as $proceso) {
        if ($proceso->isRunning()) {
            $proceso->stop(0);
        }
    }

    try {
        rcc_limpiar($this->idsPrevios);
    } finally {
        foreach (glob($this->prefijo.'*') ?: [] as $archivo) {
            @unlink($archivo);
        }
    }
});

/** Maximo id por tabla ANTES de la prueba: lo que nazca despues es de la prueba y es lo unico que se borra. */
function rcc_snapshot(): array
{
    $snap = [];

    foreach (DB::select("select table_name as t from information_schema.columns where table_schema = database() and column_name = 'id' and extra like '%auto_increment%'") as $f) {
        $snap[$f->t] = (int) DB::table($f->t)->max('id');
    }

    return $snap;
}

function rcc_limpiar(array $snap): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS=0');

    try {
        $productos = array_map('intval', DB::table('products')->where('id', '>', $snap['products'] ?? 0)->pluck('id')->all());

        foreach ($snap as $tabla => $max) {
            DB::table($tabla)->where('id', '>', $max)->delete();
        }

        if ($productos) {
            foreach (DB::select("select table_name as t from information_schema.columns where table_schema = database() and column_name = 'product_id'") as $f) {
                DB::table($f->t)->whereIn('product_id', $productos)->delete();
            }
        }
    } finally {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    rcc_confirmar();
}

function rcc_lanzar($test, array $parametros): Process
{
    $proceso = new Process([PHP_BINARY, __DIR__.'/proceso_reasignacion.php', base64_encode(json_encode($parametros))], base_path(), ['APP_ENV' => 'testing'], null, 180);
    $proceso->start();
    $test->procesos[] = $proceso;

    return $proceso;
}

function rcc_resultado(Process $proceso): array
{
    $proceso->wait();
    $lineas = array_values(array_filter(explode("\n", trim($proceso->getOutput()))));
    $json = json_decode((string) end($lineas), true);

    if (! is_array($json)) {
        throw new RuntimeException('El proceso hijo no devolvio JSON. Salida: '.$proceso->getOutput().' Error: '.$proceso->getErrorOutput());
    }

    return $json;
}

/** Lanza N procesos con una barrera comun y devuelve sus resultados. */
function rcc_a_la_vez($test, array $trabajos): array
{
    rcc_confirmar();
    $barrera = $test->prefijo.'_barrera';
    touch($barrera);
    $procesos = [];
    $cids = [];

    foreach ($trabajos as $i => $trabajo) {
        $cids[$i] = $test->prefijo.'_cid'.$i;
        $procesos[$i] = rcc_lanzar($test, $trabajo + ['barrera' => $barrera, 'cid' => $cids[$i]]);
    }

    // Todos con su conexion abierta y esperando en la barrera.
    $limite = microtime(true) + 150;
    while (count(array_filter($cids, 'is_file')) < count($cids)) {
        if (microtime(true) > $limite) {
            throw new RuntimeException('Algun proceso no llego a la barrera.');
        }
        usleep(50_000);
    }

    unlink($barrera);

    return array_map('rcc_resultado', $procesos);
}

function rcc_guardar(string $nombre, array $datos): void
{
    $dir = storage_path('logs/concurrencia');
    @mkdir($dir, 0777, true);
    file_put_contents($dir.DIRECTORY_SEPARATOR.$nombre.'_'.date('Ymd_His').'.json', json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

test('RCC-1 (R13) dos reasignaciones simultaneas sobre el mismo saldo: la suma nunca supera lo elegible', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']);
    $tercero = qa_taller();

    // Elegible 100: se reportan 200 de las 300 M. Cada proceso intenta mover 60 (60 + 60 > 100).
    app(OrdenConfeccionService::class)->reportarTaller($e['ordJ']->fresh(), ['detalles' => [['id' => $d->id, 'cantidad' => 200]]]);

    $resultados = rcc_a_la_vez($this, [
        ['modo' => 'reasignar', 'admin_id' => $e['admin']->id, 'payload' => qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 60]])],
        ['modo' => 'reasignar', 'admin_id' => $e['admin']->id, 'payload' => qa_payload_reasignar($e['ordJ'], $tercero, [[$d->id, 60]])],
    ]);
    rcc_guardar('RCC-1', $resultados);

    $ok = array_values(array_filter($resultados, fn ($r) => $r['ok']));
    $fallos = array_values(array_filter($resultados, fn ($r) => ! $r['ok']));

    expect($ok)->toHaveCount(1)
        ->and($fallos)->toHaveCount(1)
        ->and($fallos[0]['clase'])->toBe('validacion');

    expect((int) DB::table('bylopez_cc_ordenes_confeccion_detalles')->where('id', $d->id)->value('cantidad_reasignada_salida'))->toBe(60)
        ->and(Reasignacion::where('orden_corte_id', $e['corte']->id)->count())->toBe(1)
        ->and(qa_conservacion($e['corte'], $e['M']))->toBe(400);
});

test('RCC-2 (R14) reasignar contra reportar sobre el mismo saldo: nunca se pierden ni duplican unidades', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']); // 300 pendientes

    $resultados = rcc_a_la_vez($this, [
        ['modo' => 'reasignar', 'admin_id' => $e['admin']->id, 'payload' => qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 100]])],
        ['modo' => 'reportar', 'admin_id' => $e['admin']->id, 'orden_id' => $e['ordJ']->id, 'payload' => ['detalles' => [['id' => $d->id, 'cantidad' => 250]]]],
    ]);
    rcc_guardar('RCC-2', $resultados);

    // 100 + 250 > 300: exactamente una de las dos gana.
    expect(count(array_filter($resultados, fn ($r) => $r['ok'])))->toBe(1);

    $cedida = (int) DB::table('bylopez_cc_ordenes_confeccion_detalles')->where('id', $d->id)->value('cantidad_reasignada_salida');
    $reportada = (int) DB::table('bylopez_cc_ordenes_confeccion_retorno_detalles')->where('orden_confeccion_detalle_id', $d->id)->sum('cantidad_reportada');

    expect($cedida + $reportada)->toBeLessThanOrEqual(300)
        ->and($cedida === 100 xor $reportada === 250)->toBeTrue()
        ->and(qa_conservacion($e['corte'], $e['M']))->toBe(400);
});

test('RCC-3 reasignar contra anular la misma orden: gana una sola y no se libera lo reasignado', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']);

    $resultados = rcc_a_la_vez($this, [
        ['modo' => 'reasignar', 'admin_id' => $e['admin']->id, 'payload' => qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 100]])],
        ['modo' => 'cancelar', 'admin_id' => $e['admin']->id, 'orden_id' => $e['ordJ']->id],
    ]);
    rcc_guardar('RCC-3', $resultados);

    expect(count(array_filter($resultados, fn ($r) => $r['ok'])))->toBe(1);

    $estado = DB::table('bylopez_cc_ordenes_confeccion')->where('id', $e['ordJ']->id)->value('estado');
    $reasignaciones = Reasignacion::where('orden_corte_id', $e['corte']->id)->count();

    // O se anulo (sin reasignaciones) o se reasigno (y la orden NO quedo anulada): jamas ambas.
    expect(($estado === 'anulada' && $reasignaciones === 0) || ($estado !== 'anulada' && $reasignaciones === 1))->toBeTrue();
});

test('RCC-4 la misma llave enviada a la vez por dos procesos produce UNA sola reasignacion y una sola orden destino', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']);
    $payload = qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 100]]);
    $ordenesAntes = OrdenConfeccion::count();

    $resultados = rcc_a_la_vez($this, [
        ['modo' => 'reasignar', 'admin_id' => $e['admin']->id, 'payload' => $payload],
        ['modo' => 'reasignar', 'admin_id' => $e['admin']->id, 'payload' => $payload],
    ]);
    rcc_guardar('RCC-4', $resultados);

    expect($resultados[0]['ok'])->toBeTrue()
        ->and($resultados[1]['ok'])->toBeTrue()
        ->and($resultados[0]['reasignacion_id'])->toBe($resultados[1]['reasignacion_id'])
        ->and(Reasignacion::where('orden_corte_id', $e['corte']->id)->count())->toBe(1)
        ->and(OrdenConfeccion::count())->toBe($ordenesAntes + 1)
        ->and(qa_vigente_taller($e['corte'], $e['jairo'], $e['M']))->toBe(200);
});

/*
|--------------------------------------------------------------------------
| H10 con concurrencia real: dos procesos, dos conexiones, a traves de los ENDPOINTS HTTP.
|   RCC-5: POST admin/corte-confeccion/ordenes-corte/reportar/{id}      (OrdenCorteController@reportar)
|   RCC-6: POST admin/corte-confeccion/recepciones-corte/create/{id}     (RecepcionCorteController@store)
|--------------------------------------------------------------------------
*/

function rcc_ruta(string $nombre, $parametro): string
{
    return parse_url(route($nombre, $parametro), PHP_URL_PATH);
}

test('RCC-5 (H10) dos reportes simultaneos del mismo resultado de corte (con un adicional): se aplica uno solo, el otro recibe 422', function () {
    Auth::guard('admin')->setUser(qa_admin('Bodega RCC5 '.uniqid()));
    $fx = CatalogoCorteFixtures::nuevo();
    $negro = $fx->opcion($fx->color, 'Negro');
    $tallas = $fx->tallas(['M', 'L']);
    $familia = $fx->familia('Camiseta Hombre  DURZ');
    $fx->configurableConEje($familia, $negro, $tallas, ['sku' => 'RCC5-'.uniqid(), 'nombre' => 'Camiseta RCC5']);

    $rolCortador = Role::factory()->create(['name' => 'CORTADOR', 'permission_type' => 'all']);
    $cortadorAdmin = Admin::factory()->create(['role_id' => $rolCortador->id]);
    Cortador::query()->create(['usuario_id' => $cortadorAdmin->id, 'nombre' => 'Cortador RCC5 '.uniqid(), 'estado' => 'activo']);

    $rollo = Rollo::create([
        'codigo' => 'R-RCC5-'.uniqid(), 'proveedor' => 'Proveedor RCC5', 'color' => $negro->admin_name, 'color_option_id' => $negro->id,
        'tipo_tela' => $fx->tela->admin_name, 'tipo_tela_id' => $fx->tela->id, 'referencia' => 'REF-RCC5', 'gramaje' => '180',
        'peso_inicial' => 25, 'peso_disponible' => 25, 'estado' => EstadoRollo::Disponible->value,
    ]);

    $servicio = app(OrdenCorteService::class);
    $orden = $servicio->crearDesdeSeleccion([
        'rollo_ids' => [$rollo->id], 'cortador_id' => $cortadorAdmin->id, 'observacion' => null,
        'seleccion' => [['attribute_family_id' => $familia->id, 'color_option_id' => $negro->id, 'eje_option_id' => $tallas['M']->id, 'filtros' => []]],
    ]);
    $orden = $servicio->cambiarEstado($servicio->entregar($orden), EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);
    $solicitado = $orden->detalles()->sole();

    $datos = [
        'resultados' => [['detalle_id' => $solicitado->id, 'cantidad_real_cortada' => 20]],
        'adicionales' => [['attribute_family_id' => $familia->id, 'color_option_id' => $negro->id, 'eje_option_id' => $tallas['L']->id, 'cantidad_real_cortada' => 6, 'filtros' => []]],
    ];
    $trabajo = ['modo' => 'http', 'metodo' => 'POST', 'uri' => rcc_ruta('admin.corte_confeccion.ordenes_corte.reportar', $orden->id), 'datos' => $datos, 'admin_id' => $cortadorAdmin->id];

    $resultados = rcc_a_la_vez($this, [$trabajo, $trabajo]);
    rcc_guardar('RCC-5', $resultados);

    $estados = array_column($resultados, 'status');
    sort($estados);

    expect($estados)->toBe([302, 422]);

    $detalles = DB::table('bylopez_cc_ordenes_corte_detalles')->where('orden_corte_id', $orden->id)->orderBy('id')->get();

    expect(DB::table('bylopez_cc_ordenes_corte')->where('id', $orden->id)->value('estado'))->toBe(EstadoOrdenCorte::ReportadoPorCortador->value)
        ->and($detalles)->toHaveCount(2)
        ->and($detalles->where('es_adicional', 1))->toHaveCount(1)
        ->and((int) $detalles->firstWhere('id', $solicitado->id)->cantidad_real_cortada)->toBe(20)
        ->and((int) $detalles->firstWhere('es_adicional', 1)->cantidad_real_cortada)->toBe(6)
        ->and(DB::table('bylopez_cc_orden_corte_cantidad_audits')->where('orden_corte_id', $orden->id)->count())->toBe(2);
});

test('RCC-6 (H10) dos recepciones simultaneas de la misma orden: una sola recepcion, un solo juego de detalles, la otra recibe 422', function () {
    $p1 = Product::factory()->simple()->create();
    $p2 = Product::factory()->simple()->create();
    $orden = qa_orden_corte_recibida([
        ['product' => $p1, 'cantidad_recibida' => 30, 'cantidad_defectuosa' => 0],
        ['product' => $p2, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0],
    ], ['sin_recepcion' => true]);

    $bodega = qa_admin('Bodega', 'all');
    $datos = [
        'fecha_recepcion' => now()->toDateString(), 'merma_total' => 0, 'recibido_por' => 'QA Bodega RCC6',
        'detalles' => $orden->detalles->map(fn ($d) => ['orden_corte_detalle_id' => $d->id, 'cantidad_recibida' => (int) $d->cantidad_real_cortada, 'cantidad_defectuosa' => 0])->values()->all(),
    ];
    $trabajo = ['modo' => 'http', 'metodo' => 'POST', 'uri' => rcc_ruta('admin.corte_confeccion.recepciones_corte.store', $orden->id), 'datos' => $datos, 'admin_id' => $bodega->id];

    $resultados = rcc_a_la_vez($this, [$trabajo, $trabajo]);
    rcc_guardar('RCC-6', $resultados);

    $estados = array_column($resultados, 'status');
    sort($estados);

    expect($estados)->toBe([302, 422]);

    $recepcionId = DB::table('bylopez_cc_recepciones_corte')->where('orden_corte_id', $orden->id)->value('id');

    expect(DB::table('bylopez_cc_recepciones_corte')->where('orden_corte_id', $orden->id)->count())->toBe(1)
        ->and(DB::table('bylopez_cc_recepciones_corte_detalles')->where('recepcion_corte_id', $recepcionId)->count())->toBe(2)
        ->and((int) DB::table('bylopez_cc_recepciones_corte_detalles')->where('recepcion_corte_id', $recepcionId)->sum('cantidad_recibida'))->toBe(50)
        ->and(DB::table('bylopez_cc_ordenes_corte')->where('id', $orden->id)->value('estado'))->toBe(EstadoOrdenCorte::RecibidoBodega->value);
});

/*
|--------------------------------------------------------------------------
| Cartera: doble clic / dos usuarios simultaneos (procesos reales, cada uno con SU conexion).
|--------------------------------------------------------------------------
*/

test('RCC-7 cartera: la MISMA transferencia registrada a la vez por dos usuarios con llaves distintas se contabiliza UNA sola vez', function () {
    $e = cc_escenario();
    $payload = fn (string $llave) => [
        'tipo' => 'abono', 'prestador_tipo' => 'taller', 'prestador_id' => $e['jairo']->id, 'valor' => '20000.00', 'fecha_efectiva' => now()->toDateString(),
        'medio' => 'nequi', 'modo_detalle' => 'detalle', 'idempotency_key' => $llave,
        'operaciones' => [cc_op('CONC-7', 20000)],
    ];

    $resultados = rcc_a_la_vez($this, [
        ['modo' => 'cartera', 'admin_id' => $e['admin']->id, 'payload' => $payload((string) Str::uuid())],
        ['modo' => 'cartera', 'admin_id' => $e['admin']->id, 'payload' => $payload((string) Str::uuid())],
    ]);
    rcc_guardar('RCC-7', $resultados);

    expect(count(array_filter($resultados, fn ($r) => $r['ok'])))->toBe(1);
    expect(collect($resultados)->firstWhere('ok', false)['clase'])->toBe('validacion');
    expect(DB::table('bylopez_cc_cartera_movimientos')->where('taller_id', $e['jairo']->id)->where('tipo', 'abono')->count())->toBe(1)
        ->and(DB::table('bylopez_cc_cartera_operaciones')->where('estado', 'aceptada')->whereNotNull('huella_activa')->count())->toBe(1)
        ->and(cc_cuentas()->cuenta($e['jairo']->fresh())['total_abonado'])->toBe('20000.00');
});

test('RCC-8 cartera: doble clic (misma llave y mismo contenido) crea UN movimiento y ambos reciben el mismo resultado', function () {
    $e = cc_escenario();
    $payload = [
        'tipo' => 'abono', 'prestador_tipo' => 'taller', 'prestador_id' => $e['jairo']->id, 'valor' => '50000.00', 'fecha_efectiva' => now()->toDateString(),
        'medio' => 'nequi', 'modo_detalle' => 'total', 'num_transferencias' => 3, 'idempotency_key' => (string) Str::uuid(),
    ];

    $resultados = rcc_a_la_vez($this, [
        ['modo' => 'cartera', 'admin_id' => $e['admin']->id, 'payload' => $payload],
        ['modo' => 'cartera', 'admin_id' => $e['admin']->id, 'payload' => $payload],
    ]);
    rcc_guardar('RCC-8', $resultados);

    expect($resultados[0]['ok'])->toBeTrue()->and($resultados[1]['ok'])->toBeTrue()
        ->and($resultados[0]['movimiento_id'])->toBe($resultados[1]['movimiento_id'])
        ->and(DB::table('bylopez_cc_cartera_movimientos')->where('taller_id', $e['jairo']->id)->where('tipo', 'abono')->count())->toBe(1)
        ->and(cc_cuentas()->cuenta($e['jairo']->fresh())['total_abonado'])->toBe('50000.00');
});

test('RCC-9 cartera: dos abonos simultaneos que aplican al mismo saldo nunca superan lo que se debe', function () {
    $e = cc_escenario('1450', 600, 0); // OF-A recibida 870.000, sin pendientes
    $payload = fn () => [
        'tipo' => 'abono', 'prestador_tipo' => 'taller', 'prestador_id' => $e['jairo']->id, 'valor' => '600000.00', 'fecha_efectiva' => now()->toDateString(),
        'medio' => 'nequi', 'modo_detalle' => 'total', 'idempotency_key' => (string) Str::uuid(),
        'aplicaciones' => [['destino' => 'orden', 'id' => $e['ordA']->id, 'valor' => '600000.00']],
    ];

    $resultados = rcc_a_la_vez($this, [
        ['modo' => 'cartera', 'admin_id' => $e['admin']->id, 'payload' => $payload()],
        ['modo' => 'cartera', 'admin_id' => $e['admin']->id, 'payload' => $payload()],
    ]);
    rcc_guardar('RCC-9', $resultados);

    // 600.000 + 600.000 > 870.000: solo una aplicacion cabe; la otra falla de forma controlada.
    expect(count(array_filter($resultados, fn ($r) => $r['ok'])))->toBe(1);
    $aplicado = DB::table('bylopez_cc_cartera_aplicaciones')->where('orden_confeccion_id', $e['ordA']->id)->whereNull('anulada_en')->sum('valor');
    expect((float) $aplicado)->toBe(600000.0);
});

/*
| Doc. 09: cambios de prioridad por cantidad con procesos reales.
*/

function rcc_prioridad(OrdenConfeccionDetalle $d, int $cantidad, string $desde, string $hacia, ?string $llave = null): array
{
    return [
        'idempotency_key' => $llave ?? (string) Str::uuid(),
        'nota' => null,
        'cambios' => [['detalle_id' => $d->id, 'cantidad' => $cantidad, 'desde' => $desde, 'hacia' => $hacia]],
    ];
}

test('RCC-P1 dos cambios de prioridad simultaneos sobre el mismo saldo: nunca hay mas prioritarias que el pendiente', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordA'], $e['M']); // 100 Normal

    $resultados = rcc_a_la_vez($this, [
        ['modo' => 'prioridad', 'admin_id' => $e['admin']->id, 'payload' => rcc_prioridad($d, 60, 'normal', 'alta')],
        ['modo' => 'prioridad', 'admin_id' => $e['admin']->id, 'payload' => rcc_prioridad($d, 60, 'normal', 'urgente')],
    ]);
    rcc_guardar('RCC-P1', $resultados);

    $fila = DB::table('bylopez_cc_ordenes_confeccion_detalles')->where('id', $d->id)->first();

    expect(count(array_filter($resultados, fn ($r) => $r['ok'])))->toBe(1)
        ->and(array_values(array_filter($resultados, fn ($r) => ! $r['ok']))[0]['clase'])->toBe('validacion')
        ->and((int) $fila->cantidad_alta + (int) $fila->cantidad_urgente)->toBe(60)
        ->and(DB::table('bylopez_cc_prioridad_operaciones')->where('taller_id', $e['andrea']->id)->count())->toBe(1)
        ->and(DB::table('bylopez_cc_notificaciones')->where('admin_id', $e['andrea']->usuario_id)->count())->toBe(1);
});

test('RCC-P2 doble clic real (misma llave, a la vez): una operacion y una sola alerta', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordA'], $e['M']);
    $payload = rcc_prioridad($d, 40, 'normal', 'urgente', 'doble-'.uniqid());

    $resultados = rcc_a_la_vez($this, [
        ['modo' => 'prioridad', 'admin_id' => $e['admin']->id, 'payload' => $payload],
        ['modo' => 'prioridad', 'admin_id' => $e['admin']->id, 'payload' => $payload],
    ]);
    rcc_guardar('RCC-P2', $resultados);

    $ok = array_values(array_filter($resultados, fn ($r) => $r['ok']));

    // La segunda espera el bloqueo y devuelve la misma operacion; si el motor la corta por la llave unica, falla sin efectos.
    expect(count($ok))->toBeGreaterThanOrEqual(1)
        ->and(collect($ok)->pluck('operacion_id')->unique())->toHaveCount(1)
        ->and((int) DB::table('bylopez_cc_ordenes_confeccion_detalles')->where('id', $d->id)->value('cantidad_urgente'))->toBe(40)
        ->and(DB::table('bylopez_cc_prioridad_operaciones')->where('idempotency_key', $payload['idempotency_key'])->count())->toBe(1)
        ->and(DB::table('bylopez_cc_notificaciones')->where('admin_id', $e['andrea']->usuario_id)->count())->toBe(1);
});

test('RCC-P3 cambio de prioridad contra reporte del taller a la vez: el resultado es uno de los ordenes legitimos', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordA'], $e['M']); // 100 Normal

    $resultados = rcc_a_la_vez($this, [
        ['modo' => 'prioridad', 'admin_id' => $e['admin']->id, 'payload' => rcc_prioridad($d, 50, 'normal', 'alta')],
        ['modo' => 'reportar', 'admin_id' => $e['admin']->id, 'orden_id' => $e['ordA']->id, 'payload' => ['detalles' => [['id' => $d->id, 'cantidad' => 70]]]],
    ]);
    rcc_guardar('RCC-P3', $resultados);

    $alta = (int) DB::table('bylopez_cc_ordenes_confeccion_detalles')->where('id', $d->id)->value('cantidad_alta');
    $altaReportada = (int) DB::table('bylopez_cc_ordenes_confeccion_retorno_detalles')->where('orden_confeccion_detalle_id', $d->id)->value('cantidad_alta');

    expect($resultados[1]['ok'])->toBeTrue();

    // P1. Prioridad primero: Alta 50 y el reporte de 70 (sugerido Urgente -> Alta -> Normal) descuenta 50 Altas + 20.
    // Reporte primero: solo quedan 30 por confeccionar y el cambio de 50 se rechaza.
    if ($resultados[0]['ok']) {
        expect($alta)->toBe(0)->and($altaReportada)->toBe(50);
    } else {
        expect($resultados[0]['clase'])->toBe('validacion')->and($alta)->toBe(0)->and($altaReportada)->toBe(0);
    }
});

test('RCC-P4 reporte con el saldo por prioridad que vio la pantalla contra un cambio de prioridad a la vez: nunca se descuenta un reparto viejo', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordA'], $e['M']); // 100 Normal: la pantalla del taller vio «0:0:100»

    $resultados = rcc_a_la_vez($this, [
        ['modo' => 'prioridad', 'admin_id' => $e['admin']->id, 'payload' => rcc_prioridad($d, 40, 'normal', 'urgente')],
        ['modo' => 'reportar', 'admin_id' => $e['admin']->id, 'orden_id' => $e['ordA']->id, 'payload' => ['detalles' => [['id' => $d->id, 'cantidad' => 30, 'base' => '0:0:100']]]],
    ]);
    rcc_guardar('RCC-P4', $resultados);

    $fila = DB::table('bylopez_cc_ordenes_confeccion_detalles')->where('id', $d->id)->first();
    $reportes = DB::table('bylopez_cc_ordenes_confeccion_retorno_detalles')->where('orden_confeccion_detalle_id', $d->id)->get();

    expect($resultados[0]['ok'])->toBeTrue()
        ->and((int) $fila->cantidad_urgente)->toBe(40);

    if ($resultados[1]['ok']) {
        // El reporte entro antes: 30 Normales descontadas; despues 40 de las 70 restantes pasaron a Urgente.
        expect($reportes)->toHaveCount(1)->and((int) $reportes[0]->cantidad_urgente)->toBe(0);
    } else {
        // El cambio entro antes: el saldo por nivel ya no es el que se vio; el taller debe revisar. Nada se reporto.
        expect($resultados[1]['clase'])->toBe('validacion')
            ->and(array_key_first($resultados[1]['errores']))->toBe('detalles.0.reparto')
            ->and($reportes)->toHaveCount(0);
    }
});
