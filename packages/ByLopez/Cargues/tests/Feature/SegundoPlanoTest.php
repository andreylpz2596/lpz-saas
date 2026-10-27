<?php

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Jobs\LeerYAuditarCarga;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Models\CargaDato;
use ByLopez\Cargues\Services\Cargas\CargasService;
use ByLopez\Cargues\Services\Cargas\ProgresoService;
use ByLopez\Cargues\Tests\CarguesTestCase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../helpers.php';

uses(CarguesTestCase::class);

/*
| Segundo plano con WORKERS REALES (procesos `queue:work cargues`) sobre la cola `database` de la instancia aislada.
| Cada prueba arranca y detiene sus propios procesos; ninguno queda vivo al terminar.
*/

beforeEach(function () {
    cg_limpiar();
    config(['cargues.cola.conexion' => 'cargues', 'cargues.umbral_sin_noticias' => 120]);
    $GLOBALS['cg_workers'] = [];
});

afterEach(function () {
    foreach ($GLOBALS['cg_workers'] ?? [] as $w) {
        if (is_resource($w['proc'])) {
            @proc_terminate($w['proc']);
            @proc_close($w['proc']);
        }
    }

    $GLOBALS['cg_workers'] = [];
    cg_limpiar();
});

/** Arranca un worker de la cola `cargues` (termina solo cuando la cola queda vacía). */
function cg_worker(array $env = [], string $nombre = 'w'): array
{
    $log = sys_get_temp_dir().DIRECTORY_SEPARATOR."cg_worker_{$nombre}_".uniqid().'.log';
    $proc = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=512M', 'artisan', 'queue:work', 'cargues', '--queue=cargues', '--tries=1', '--timeout=600', '--sleep=1', '--stop-when-empty', '--memory=512'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
        $pipes,
        base_path(),
        array_merge(getenv(), ['APP_ENV' => 'testing', 'QUEUE_CONNECTION' => 'sync'], $env),
    );

    expect(is_resource($proc))->toBeTrue();
    $w = ['proc' => $proc, 'log' => $log];
    $GLOBALS['cg_workers'][] = $w;

    return $w;
}

function cg_vivo(array $w): bool
{
    return is_resource($w['proc']) && proc_get_status($w['proc'])['running'];
}

/** Espera hasta que la condición se cumpla; devuelve true/false (sin lanzar). */
function cg_esperar(callable $cond, float $segundos = 90, int $pausaMs = 100): bool
{
    $limite = microtime(true) + $segundos;

    while (microtime(true) < $limite) {
        if ($cond()) {
            return true;
        }

        usleep($pausaMs * 1000);
    }

    return false;
}

function cg_log(array $w): string
{
    return is_file($w['log']) ? (string) file_get_contents($w['log']) : '';
}

function cg_carga_grande(int $filas, ?array $opciones = null): array
{
    [$tipo, , $admin] = cg_tipo_activo($opciones ?? []);
    $carga = cg_carga($tipo, $admin, cg_filas($filas));

    return [$carga, $tipo, $admin];
}

test('F1-B1 «Validar archivo» responde en menos de 1 s con el id de la carga y encola el trabajo: la petición no lee el archivo', function () {
    [$carga, , $admin] = cg_carga_grande(10000);

    $t0 = microtime(true);
    $c = app(CargasService::class)->validar($admin, $carga, ['relaciones' => $carga->encabezados['sugerencias']]);
    $ms = (microtime(true) - $t0) * 1000;

    expect($ms)->toBeLessThan(1000)
        ->and($c->estado)->toBe('en_cola')->and($c->id)->toBe($carga->id)->and($c->job_uuid)->toHaveLength(36)
        ->and(CargaDato::where('carga_id', $c->id)->count())->toBe(0)                 // nada se leyó
        ->and(DB::table('jobs')->where('queue', 'cargues')->count())->toBe(1)         // un job en la cola dedicada
        ->and(DB::table('jobs')->where('queue', '!=', 'cargues')->count())->toBe(0);  // y ninguno en otra cola

    $payload = json_decode(DB::table('jobs')->value('payload'), true);
    expect($payload['displayName'])->toBe(LeerYAuditarCarga::class)->and($payload['maxTries'])->toBe(1)->and($payload['timeout'])->toBe(900);

    // Mientras no haya worker, la UI ve «en cola» con su antigüedad.
    $p = app(ProgresoService::class)->consultar($c);
    expect($p['en_cola'])->toBeTrue()->and($p['en_curso'])->toBeTrue()->and($p['terminal'])->toBeFalse()->and($p['sin_noticias'])->toBeFalse();
});

test('F1-B2 durante el job, el progreso es monótono creciente hasta filas_total y la etapa final es correcta', function () {
    [$carga, , $admin] = cg_carga_grande(20000);
    app(CargasService::class)->validar($admin, $carga, ['relaciones' => $carga->encabezados['sugerencias']]);

    $w = cg_worker();
    $muestras = [];
    $servicio = app(ProgresoService::class);

    $fin = cg_esperar(function () use ($carga, $servicio, &$muestras) {
        $p = $servicio->consultar(Carga::find($carga->id));
        $muestras[] = $p;

        return $p['terminal'];
    }, 180, 80);

    expect($fin)->toBeTrue(cg_log($w));

    $porEtapa = [];

    foreach ($muestras as $m) {
        $porEtapa[$m['etapa'] ?? '-'][] = $m['filas_procesadas'];
    }

    // Monótono dentro de cada etapa.
    foreach ($porEtapa as $etapa => $valores) {
        $ordenados = $valores;
        sort($ordenados);

        expect($valores)->toBe($ordenados, "etapa {$etapa}");
    }

    $intermedias = array_filter($muestras, fn ($m) => $m['etapa'] === 'leyendo' && $m['filas_procesadas'] > 0 && $m['filas_procesadas'] < $m['filas_total']);
    $ultimo = end($muestras);

    expect(count($intermedias))->toBeGreaterThanOrEqual(2, 'se observó el avance en curso')
        ->and($ultimo['estado'])->toBe('validada')->and($ultimo['etapa'])->toBe('finalizado')
        ->and($ultimo['filas_procesadas'])->toBe(20000)->and($ultimo['filas_total'])->toBe(20000)->and($ultimo['porcentaje'])->toBe(100)
        ->and($ultimo['en_curso'])->toBeFalse()->and($ultimo['terminal'])->toBeTrue();

    $etapas = array_values(array_unique(array_column($muestras, 'etapa')));
    expect($etapas)->toContain('leyendo')->toContain('llenando_intermedia')->toContain('finalizado');

    // La petición web nunca hizo el trabajo: lo hizo el worker.
    expect(CargaDato::where('carga_id', $carga->id)->count())->toBe(20000)
        ->and(cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->count())->toBe(20000)
        ->and(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('F1-B3 si el worker muere a mitad de la lectura, la carga no queda «en curso» para siempre: «sin noticias» y reintento sin duplicar', function () {
    [$carga, , $admin] = cg_carga_grande(60000);
    app(CargasService::class)->validar($admin, $carga, ['relaciones' => $carga->encabezados['sugerencias']]);

    $w = cg_worker();
    $enLectura = cg_esperar(fn () => ($c = Carga::find($carga->id))->estado === 'leyendo' && $c->filas_procesadas > 0, 60);
    expect($enLectura)->toBeTrue(cg_log($w));

    proc_terminate($w['proc']);                 // el worker muere en plena lectura
    proc_close($w['proc']);
    $parciales = CargaDato::where('carga_id', $carga->id)->count();

    expect($parciales)->toBeGreaterThan(0)->toBeLessThan(60000);   // quedó a medias

    // Recién muerto: todavía no hay motivo para dudar.
    config(['cargues.umbral_sin_noticias' => 3]);
    expect(app(ProgresoService::class)->consultar(Carga::find($carga->id))['sin_noticias'])->toBeFalse();

    sleep(4);
    $p = app(ProgresoService::class)->consultar(Carga::find($carga->id));

    expect($p['sin_noticias'])->toBeTrue()->and($p['puede_reintentar'])->toBeTrue()->and($p['en_curso'])->toBeTrue()
        ->and(Carga::find($carga->id)->estado)->toBe('leyendo');   // no se cambió nada por su cuenta

    // Antes del umbral NO se puede relanzar encima de un job vivo; pasado el umbral sí.
    $viejoUuid = Carga::find($carga->id)->job_uuid;
    $c = app(CargasService::class)->reintentar(Carga::find($carga->id));
    expect($c->estado)->toBe('en_cola')->and($c->job_uuid)->not->toBe($viejoUuid);

    $w2 = cg_worker();
    expect(cg_esperar(fn () => Carga::find($carga->id)->estado === 'validada', 240))->toBeTrue(cg_log($w2).' | estado='.Carga::find($carga->id)->estado.' '.Carga::find($carga->id)->mensaje);

    $c = Carga::find($carga->id);
    $distintas = CargaDato::where('carga_id', $carga->id)->distinct()->count('fila_origen');

    expect(CargaDato::where('carga_id', $carga->id)->count())->toBe(60000)->and($distintas)->toBe(60000)   // sin filas duplicadas (UNIQUE carga+fila)
        ->and($c->contadores['filas_validas'])->toBe(60000)
        ->and(cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->count())->toBe(60000);
});

test('F1-B3 el reintento solo se acepta cuando el proceso lleva más del umbral sin avanzar (CAS)', function () {
    [$carga, , $admin] = cg_carga_grande(50);
    $svc = app(CargasService::class);
    $c = $svc->validar($admin, $carga, ['relaciones' => $carga->encabezados['sugerencias']]);   // queda en cola (sin worker)

    expect($c->estado)->toBe('en_cola');

    // En cola y reciente: otro «Validar» o «Reintentar» no lo pisa.
    expect(fn () => $svc->validar($admin, $c->fresh(), ['relaciones' => $carga->encabezados['sugerencias']]))->toThrow(OperacionInvalidaException::class);
    expect(fn () => $svc->reintentar($c->fresh()))->toThrow(OperacionInvalidaException::class);
    expect(DB::table('jobs')->count())->toBe(1);

    // Envejecido sin ningún avance: ya sí.
    Carga::where('id', $c->id)->update(['encolada_en' => now()->subMinutes(10), 'progreso_actualizado_en' => now()->subMinutes(10)]);
    $r = $svc->reintentar($c->fresh());

    expect($r->estado)->toBe('en_cola')->and($r->job_uuid)->not->toBe($c->job_uuid)->and(DB::table('jobs')->count())->toBe(2);
});

test('F1-B4 con retry_after mal configurado, la segunda entrega del mismo job NO procesa en paralelo: el CAS la detiene', function () {
    [$carga, , $admin] = cg_carga_grande(60000);
    app(CargasService::class)->validar($admin, $carga, ['relaciones' => $carga->encabezados['sugerencias']]);

    // retry_after = 1 s (menor que la duración del job): otro worker vuelve a recibir el mismo job mientras el primero trabaja.
    $a = cg_worker(['CARGUES_QUEUE_RETRY_AFTER' => '1'], 'a');
    expect(cg_esperar(fn () => Carga::find($carga->id)->estado === 'leyendo', 60))->toBeTrue(cg_log($a));

    $b = cg_worker(['CARGUES_QUEUE_RETRY_AFTER' => '1'], 'b');

    expect(cg_esperar(fn () => Carga::find($carga->id)->estado === 'validada' && ! cg_vivo($a) && ! cg_vivo($b), 300))->toBeTrue(cg_log($a).' || '.cg_log($b));

    $c = Carga::find($carga->id);

    expect($c->estado)->toBe('validada')
        ->and(CargaDato::where('carga_id', $carga->id)->count())->toBe(60000)
        ->and(CargaDato::where('carga_id', $carga->id)->distinct()->count('fila_origen'))->toBe(60000)
        ->and(cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->count())->toBe(60000)
        ->and($c->contadores['filas_validas'])->toBe(60000);

    // Hubo reentrega (el job duplicado se registra como fallido por exceder los intentos), y NO marcó la carga como fallida.
    $fallidos = DB::table('failed_jobs')->where('queue', 'cargues')->get();
    expect($fallidos->count())->toBeGreaterThanOrEqual(1)->and($fallidos->first()->exception)->toContain('MaxAttemptsExceeded');
    expect(str_contains(cg_log($a).cg_log($b), 'Cargues: error inesperado'))->toBeFalse();
});

test('F1-B5 dos cargas de tipos distintos avanzan en paralelo con dos workers, sin mezclar su progreso', function () {
    cg_negocio();
    $admin = cg_admin();
    $cargas = [];

    foreach (['a' => 8000, 'b' => 12000] as $k => $n) {
        cg_sp_nulo('sp_cg_par_'.$k);
        [$tipo] = cg_tipo_activo(['sin_sp' => true, 'admin' => $admin, 'procedimiento' => 'sp_cg_par_'.$k, 'nombre' => 'Par '.$k, 'map' => cg_mapa(['tabla' => 'bylopez_cgi_par_'.$k])]);
        $c = cg_carga($tipo, $admin, cg_filas($n));
        app(CargasService::class)->validar($admin, $c, ['relaciones' => $c->encabezados['sugerencias']]);
        $cargas[$k] = ['id' => $c->id, 'n' => $n, 'totales' => [], 'tabla' => 'bylopez_cgi_par_'.$k];
    }

    $w1 = cg_worker([], 'p1');
    $w2 = cg_worker([], 'p2');
    $servicio = app(ProgresoService::class);
    $simultaneas = 0;

    $fin = cg_esperar(function () use (&$cargas, $servicio, &$simultaneas) {
        $enCurso = 0;
        $terminadas = 0;

        foreach ($cargas as $k => $c) {
            $p = $servicio->consultar(Carga::find($c['id']));
            $enCurso += ($p['estado'] === 'leyendo' || $p['estado'] === 'preparando' || $p['estado'] === 'validando') ? 1 : 0;
            $terminadas += $p['terminal'] ? 1 : 0;

            if ($p['filas_total'] !== null) {
                $cargas[$k]['totales'][$p['filas_total']] = true;
            }
        }

        $simultaneas += $enCurso === 2 ? 1 : 0;

        return $terminadas === 2;
    }, 240, 80);

    expect($fin)->toBeTrue(cg_log($w1).' || '.cg_log($w2));

    foreach ($cargas as $k => $c) {
        $fila = Carga::find($c['id']);

        expect($fila->estado)->toBe('validada')->and($fila->filas_total)->toBe($c['n'])
            ->and(CargaDato::where('carga_id', $c['id'])->count())->toBe($c['n'])
            ->and(cg_conn()->table($c['tabla'])->where('cg_carga_id', $c['id'])->count())->toBe($c['n']);
        // Ninguna muestra de A superó los 8 000 ni mezcló el total de B (12 000); B sí llegó a 12 000.
        expect(max(array_keys($c['totales'])))->toBeLessThanOrEqual($c['n']);
    }

    expect(array_key_exists(12000, $cargas['b']['totales']))->toBeTrue()->and(array_key_exists(12000, $cargas['a']['totales']))->toBeFalse()
        ->and($simultaneas)->toBeGreaterThanOrEqual(1);   // hubo momentos con las dos en proceso a la vez
});

test('la cola de Cargues es DEDICADA: no cambia la cola por defecto ni las demás conexiones de la aplicación', function () {
    expect(config('queue.default'))->not->toBe('cargues')->and(config('queue.connections.cargues.queue'))->toBe('cargues')
        ->and(config('queue.connections.cargues.driver'))->toBe('database')
        ->and(array_keys(config('queue.connections')))->toContain('sync')->toContain('database')->toContain('redis')
        ->and(config('queue.connections.database.retry_after'))->toBe(90);   // la de los demás módulos sigue igual

    // retry_after de la cola de Cargues es MAYOR que el timeout de sus jobs.
    expect((int) config('queue.connections.cargues.retry_after'))->toBeGreaterThan((int) config('cargues.cola.timeout'));

    // Un job de Cargues nunca va a la cola por defecto.
    $job = new LeerYAuditarCarga(1, 'u');
    expect($job->connection)->toBe('cargues')->and($job->queue)->toBe('cargues')->and($job->tries)->toBe(1)->and($job->failOnTimeout)->toBeTrue();
});

test('F1-E3 el worker MUERE durante el CALL de EJECUTAR: la recuperación no clasifica mientras el servidor sigue; al terminar sin COMMIT, nada quedó aplicado', function () {
    cg_negocio();
    $t = CG_TABLA;
    cg_conn()->unprepared('DROP PROCEDURE IF EXISTS sp_cg_prueba');
    cg_conn()->unprepared(<<<SQL
        CREATE PROCEDURE sp_cg_prueba(IN p_carga_id BIGINT, IN p_accion VARCHAR(40), IN p_modo VARCHAR(10), OUT p_resultado VARCHAR(10), OUT p_mensaje VARCHAR(500))
        BEGIN
            SET p_resultado = 'OK';
            SET p_mensaje = NULL;
            IF p_modo = 'EJECUTAR' THEN
                INSERT INTO cg_negocio_b (guia, estado) SELECT guia, estado FROM {$t} WHERE cg_carga_id = p_carga_id AND cg_estado IS NULL;
                UPDATE {$t} SET cg_estado = 'aplicada', cg_detalle = 'insertada' WHERE cg_carga_id = p_carga_id AND cg_estado IS NULL;
                DO SLEEP(6);
            END IF;
        END
        SQL);
    [$tipo, , $admin] = cg_tipo_activo(['sin_sp' => true, 'politica' => 'procesar_validas', 'map' => cg_mapa(['tablas_negocio' => ['cg_negocio_a', 'cg_negocio_b']])]);

    config(['cargues.cola.conexion' => 'sync']);
    $carga = cg_validar(cg_carga($tipo, $admin, cg_filas(3)), $admin);
    config(['cargues.cola.conexion' => 'cargues']);
    app(CargasService::class)->ejecutar($admin, $carga, []);

    $w = cg_worker();
    expect(cg_esperar(fn () => Carga::find($carga->id)->estado === 'ejecutando', 60))->toBeTrue();
    usleep(1500000);                              // ya dentro del CALL (SLEEP)
    proc_terminate($w['proc']);                   // el worker muere sin poder confirmar nada
    expect(cg_esperar(fn () => ! cg_vivo($w), 10))->toBeTrue();

    // El servidor sigue ejecutando el procedimiento: la recuperación NO clasifica.
    expect(fn () => app(CargasService::class)->recuperar(Carga::find($carga->id)))->toThrow(OperacionInvalidaException::class, 'sigue');
    expect(Carga::find($carga->id)->estado)->toBe('ejecutando');

    // Cuando el CALL termina, la sesión se cierra sin COMMIT: el servidor revierte efectos y marcas.
    $nombre = Carga::find($carga->id)->bloqueo_nombre;
    expect(cg_esperar(fn () => cg_conn()->selectOne('SELECT IS_USED_LOCK(?) AS u', [$nombre])->u === null, 30))->toBeTrue();
    $c = app(CargasService::class)->recuperar(Carga::find($carga->id));

    expect($c->estado)->toBe('fallida_ejecucion')->and($c->mensaje)->toContain('REVERTIDA')
        ->and(cg_conn()->table('cg_negocio_b')->count())->toBe(0)
        ->and(cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->whereNotNull('cg_estado')->count())->toBe(0);
});

test('EJECUTAR con un worker real de la cola `cargues` completa y consolida', function () {
    cg_negocio();
    $t = CG_TABLA;
    cg_conn()->unprepared('DROP PROCEDURE IF EXISTS sp_cg_prueba');
    cg_conn()->unprepared(<<<SQL
        CREATE PROCEDURE sp_cg_prueba(IN p_carga_id BIGINT, IN p_accion VARCHAR(40), IN p_modo VARCHAR(10), OUT p_resultado VARCHAR(10), OUT p_mensaje VARCHAR(500))
        BEGIN
            SET p_resultado = 'OK';
            SET p_mensaje = NULL;
            IF p_modo = 'EJECUTAR' THEN
                INSERT INTO cg_negocio_b (guia, estado) SELECT guia, estado FROM {$t} WHERE cg_carga_id = p_carga_id AND cg_estado IS NULL;
                UPDATE {$t} SET cg_estado = 'aplicada', cg_detalle = 'insertada' WHERE cg_carga_id = p_carga_id AND cg_estado IS NULL;
            END IF;
        END
        SQL);
    [$tipo, , $admin] = cg_tipo_activo(['sin_sp' => true, 'politica' => 'procesar_validas', 'map' => cg_mapa(['tablas_negocio' => ['cg_negocio_a', 'cg_negocio_b']])]);
    config(['cargues.cola.conexion' => 'sync']);
    $carga = cg_validar(cg_carga($tipo, $admin, cg_filas(4)), $admin);
    config(['cargues.cola.conexion' => 'cargues']);

    app(CargasService::class)->ejecutar($admin, $carga, []);
    $w = cg_worker();

    expect(cg_esperar(fn () => Carga::find($carga->id)->estado === 'completada', 60))->toBeTrue(cg_log($w))
        ->and(Carga::find($carga->id)->contadores['filas_aplicadas'])->toBe(4)->and(cg_conn()->table('cg_negocio_b')->count())->toBe(4);
});
