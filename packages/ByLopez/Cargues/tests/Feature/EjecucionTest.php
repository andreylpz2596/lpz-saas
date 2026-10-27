<?php

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Jobs\EjecutarCarga;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Models\CargaDato;
use ByLopez\Cargues\Models\CargaIncidencia;
use ByLopez\Cargues\Services\Cargas\CargasService;
use ByLopez\Cargues\Services\Cargas\ConsolidacionService;
use ByLopez\Cargues\Services\Cargas\EjecucionService;
use ByLopez\Cargues\Services\Cargas\ProgresoService;
use ByLopez\Cargues\Services\Configuracion\ConexionTipo;
use ByLopez\Cargues\Tests\CarguesTestCase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../helpers.php';

uses(CarguesTestCase::class);

beforeEach(function () {
    cg_limpiar();
    config(['cargues.cola.conexion' => 'sync']);
});

afterEach(fn () => cg_limpiar());

/** SP sintetico E2: VALIDAR rechaza las filas sin telefono; EJECUTAR (por defecto) inserta en cg_negocio_b y marca. */
function e2_sp(?string $ejecutar = null, string $resultado = "'OK'"): void
{
    $t = CG_TABLA;
    $ejecutar ??= "INSERT INTO cg_negocio_b (guia, estado) SELECT guia, estado FROM {$t} WHERE cg_carga_id = p_carga_id AND cg_estado IS NULL;
        UPDATE {$t} SET cg_estado = 'aplicada', cg_detalle = 'insertada', cg_procesado_en = NOW() WHERE cg_carga_id = p_carga_id AND cg_estado IS NULL;";

    $c = cg_conn();
    $c->unprepared('DROP PROCEDURE IF EXISTS sp_cg_prueba');
    $c->unprepared(<<<SQL
        CREATE PROCEDURE sp_cg_prueba(IN p_carga_id BIGINT, IN p_accion VARCHAR(40), IN p_modo VARCHAR(10), OUT p_resultado VARCHAR(10), OUT p_mensaje VARCHAR(500))
        BEGIN
            SET p_resultado = 'OK';
            SET p_mensaje = NULL;
            IF p_modo = 'VALIDAR' THEN
                UPDATE {$t} SET cg_estado = 'rechazada', cg_codigo = 'SIN_TELEFONO', cg_mensaje = 'sin telefono' WHERE cg_carga_id = p_carga_id AND telefono IS NULL;
            ELSE
                {$ejecutar}
                SET p_resultado = {$resultado};
            END IF;
        END
        SQL);
}

/** Tipo activo con el SP E2 y una carga validada: 5 filas, las 2 ultimas sin telefono (rechazadas por el procedimiento). */
function e2_validada(string $politica = 'procesar_validas', ?string $ejecutar = null, string $resultado = "'OK'"): array
{
    cg_negocio();
    e2_sp($ejecutar, $resultado);
    [$tipo, , $admin] = cg_tipo_activo(['sin_sp' => true, 'politica' => $politica, 'map' => cg_mapa(['tablas_negocio' => ['cg_negocio_a', 'cg_negocio_b']])]);
    $filas = cg_filas(3);
    $filas[] = ['G0000091', '', 'ENTREGADO', '1,00', '01/10/2026 10:00'];
    $filas[] = ['G0000092', '', 'ENTREGADO', '1,00', '01/10/2026 10:00'];
    $carga = cg_validar(cg_carga($tipo, $admin, $filas), $admin);

    expect($carga->estado)->toBe('validada');

    return [$carga, $admin, $tipo];
}

function e2_ejecutar(Carga $carga, $admin, ?int $confirmar = 2): Carga
{
    return app(CargasService::class)->ejecutar($admin, $carga, $confirmar === null ? [] : ['confirmar_rechazadas' => $confirmar])->fresh();
}

function e2_negocio_b(): array
{
    return cg_conn()->table('cg_negocio_b')->orderBy('guia')->pluck('guia')->all();
}

function e2_foto(Carga $carga): array
{
    return [
        CargaDato::where('carga_id', $carga->id)->orderBy('id')->get(['id', 'estado_final', 'detalle'])->toArray(),
        CargaIncidencia::where('carga_id', $carga->id)->orderBy('id')->get(['fila_origen', 'etapa', 'codigo', 'severidad', 'motivo'])->toArray(),
    ];
}

test('F1-E1/E1b procesar las válidas aplica EXACTAMENTE las válidas, con efecto ⇔ marca; las rechazadas no tocan nada', function () {
    [$carga, $admin] = e2_validada();
    $carga = e2_ejecutar($carga, $admin);

    $aplicadas = cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->where('cg_estado', 'aplicada')->orderBy('guia')->pluck('guia')->all();

    expect($carga->estado)->toBe('completada')
        ->and(e2_negocio_b())->toBe(['G0000001', 'G0000002', 'G0000003'])->and($aplicadas)->toBe(e2_negocio_b())   // efecto ⇔ marca
        ->and($carga->contadores['filas_aplicadas'])->toBe(3)->and($carga->contadores['filas_rechazadas'])->toBe(2)
        ->and(CargaDato::where('carga_id', $carga->id)->where('estado_final', 'aplicada')->where('detalle', 'insertada')->count())->toBe(3)
        ->and($carga->ejecucion_token)->not->toBeNull()->and($carga->bloqueo_nombre)->toContain(':'.$carga->id);
});

test('F1-E1b «bloquear todo» con rechazadas: 409, sin efectos ni marcas de ejecución', function () {
    [$carga, $admin] = e2_validada('bloquear_todo');

    expect(fn () => e2_ejecutar($carga, $admin))->toThrow(OperacionInvalidaException::class);
    expect(e2_negocio_b())->toBe([])->and(Carga::find($carga->id)->estado)->toBe('validada')
        ->and(cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->where('cg_estado', 'aplicada')->count())->toBe(0);
});

test('F1-E2 doble clic / dos pestañas / job reentregado: una sola ejecución', function () {
    // Cola real (sin worker): el primer pedido gana el CAS y el segundo ya no encuentra la carga ejecutable.
    [$carga, $admin] = e2_validada();
    config(['cargues.cola.conexion' => 'cargues']);
    $primera = e2_ejecutar($carga, $admin);

    expect($primera->estado)->toBe('lista')
        ->and(fn () => e2_ejecutar($carga, $admin))->toThrow(OperacionInvalidaException::class, 'Solo se puede ejecutar una carga validada');
    expect(DB::table('jobs')->where('queue', 'cargues')->count())->toBe(1);

    // El job corre una vez; reentregarlo (mismo uuid) o lanzar uno viejo no hace nada.
    (new EjecutarCarga($carga->id, $primera->job_uuid))->handle(app(EjecucionService::class));
    (new EjecutarCarga($carga->id, $primera->job_uuid))->handle(app(EjecucionService::class));
    (new EjecutarCarga($carga->id, 'otro-uuid'))->handle(app(EjecucionService::class));

    expect(Carga::find($carga->id)->estado)->toBe('completada')->and(e2_negocio_b())->toBe(['G0000001', 'G0000002', 'G0000003']);
});

test('el contrato se exige ANTES del COMMIT: cada violación deshace TODO (0 efectos, 0 marcas) y la carga queda lista para ejecutar de nuevo', function () {
    $t = CG_TABLA;
    $casos = [
        'MARCAS_INCOMPLETAS' => ["INSERT INTO cg_negocio_b (guia, estado) VALUES ('X', 'Y'); UPDATE {$t} SET cg_estado = 'aplicada' WHERE cg_carga_id = p_carga_id AND cg_estado IS NULL LIMIT 1;", "'OK'"],
        'ESTADO_INVALIDO' => ["INSERT INTO cg_negocio_b (guia, estado) VALUES ('X', 'Y'); UPDATE {$t} SET cg_estado = 'hecha' WHERE cg_carga_id = p_carga_id AND cg_estado IS NULL;", "'OK'"],
        'EJECUTAR_MODIFICO_ENTRADA' => ["UPDATE {$t} SET estado = 'CAMBIADO', cg_estado = 'aplicada' WHERE cg_carga_id = p_carga_id AND cg_estado IS NULL;", "'OK'"],
        'MARCAS_PREVIAS_ALTERADAS' => ["UPDATE {$t} SET cg_estado = 'aplicada', cg_codigo = NULL WHERE cg_carga_id = p_carga_id;", "'OK'"],
        'PROCEDIMIENTO_ERROR' => ["INSERT INTO cg_negocio_b (guia, estado) VALUES ('X', 'Y'); UPDATE {$t} SET cg_estado = 'aplicada' WHERE cg_carga_id = p_carga_id AND cg_estado IS NULL;", "'ERROR'"],
        'PROCEDIMIENTO_FALLO' => ["INSERT INTO cg_negocio_b (guia, estado) VALUES ('X', 'Y'); SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'regla rota';", "'OK'"],
    ];

    foreach ($casos as $codigo => [$sql, $resultado]) {
        cg_limpiar();
        [$carga, $admin] = e2_validada('procesar_validas', $sql, $resultado);
        $marcasAntes = cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->orderBy('cg_id')->pluck('cg_estado')->all();
        $c = e2_ejecutar($carga, $admin);

        expect($c->estado)->toBe('fallida_ejecucion', $codigo)->and($c->mensaje)->toContain($codigo)
            ->and(e2_negocio_b())->toBe([], $codigo)
            ->and(cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->orderBy('cg_id')->pluck('cg_estado')->all())->toBe($marcasAntes, $codigo)
            ->and(CargaDato::where('carga_id', $carga->id)->where('estado_final', 'pendiente')->count())->toBe(3, $codigo);
    }
});

test('si otra sesión tiene el bloqueo de la carga, no se ejecuta nada; liberado, se ejecuta de nuevo y completa', function () {
    [$carga, $admin] = e2_validada();
    $otra = app(ConexionTipo::class)->pdoDedicada('cargues_tipo_prueba');
    $nombre = 'cg:'.app(ConexionTipo::class)->esquema(cg_conn()).':'.$carga->id;
    $otra->query("SELECT GET_LOCK('{$nombre}', 0)")->fetchAll();

    $c = e2_ejecutar($carga, $admin);
    expect($c->estado)->toBe('fallida_ejecucion')->and($c->mensaje)->toContain('CARGA_OCUPADA')->and(e2_negocio_b())->toBe([]);

    $otra->query("SELECT RELEASE_LOCK('{$nombre}')")->fetchAll();
    expect(e2_ejecutar($c, $admin)->estado)->toBe('completada')->and(e2_negocio_b())->toHaveCount(3);
});

test('recuperar: no clasifica mientras la sesión ejecutora siga viva; con el servidor libre distingue revertida (nada aplicado) de confirmada (consolida)', function () {
    [$carga, $admin] = e2_validada();
    $nombre = 'cg:'.app(ConexionTipo::class)->esquema(cg_conn()).':'.$carga->id;
    $elegibles = 3;
    $simular = fn () => Carga::where('id', $carga->id)->update(['estado' => 'ejecutando', 'bloqueo_nombre' => $nombre, 'conexion_ejecutor' => 999999, 'ejecucion_token' => str_repeat('a', 64),
        'resultado' => json_encode(['ejecucion' => ['elegibles' => $elegibles]])]);

    // 1. Sesión viva con el bloqueo: no se toca nada (409 por HTTP).
    $simular();
    $otra = app(ConexionTipo::class)->pdoDedicada('cargues_tipo_prueba');
    $otra->query("SELECT GET_LOCK('{$nombre}', 0)")->fetchAll();
    $this->actingAs($admin, 'admin');
    $r = $this->postJson(route('admin.cargues.cargas.recuperar', $carga->id));
    expect($r->status())->toBe(409)->and($r->json('codigo'))->toBe('EJECUCION_EN_CURSO')->and(Carga::find($carga->id)->estado)->toBe('ejecutando');
    $otra = null;

    // 2. Libre y sin marcas: el servidor revirtió → fallida_ejecucion (se puede ejecutar de nuevo).
    $c = app(CargasService::class)->recuperar(Carga::find($carga->id));
    expect($c->estado)->toBe('fallida_ejecucion')->and($c->mensaje)->toContain('REVERTIDA');

    // 3. Libre y con TODAS las marcas confirmadas (la respuesta se perdió): consolida y completa.
    $pdo = app(ConexionTipo::class)->pdoDedicada('cargues_tipo_prueba');
    $pdo->exec('UPDATE '.CG_TABLA." SET cg_estado = 'aplicada', cg_detalle = 'insertada' WHERE cg_carga_id = {$carga->id} AND cg_estado IS NULL");
    $simular();
    $c = app(CargasService::class)->recuperar(Carga::find($carga->id));
    expect($c->estado)->toBe('completada')->and($c->contadores['filas_aplicadas'])->toBe(3);
});

test('F1-E5 la consolidación es idempotente y una carga `consolidando` se completa al recuperar (también por comando)', function () {
    [$carga, $admin] = e2_validada('procesar_validas', null);
    $carga = e2_ejecutar($carga, $admin);
    $foto = e2_foto($carga);

    app(ConsolidacionService::class)->consolidar($carga);
    app(ConsolidacionService::class)->consolidar($carga);
    expect(e2_foto($carga))->toBe($foto);

    // Interrumpida tras el COMMIT y antes de consolidar: el comando la completa igual que la primera vez.
    CargaDato::where('carga_id', $carga->id)->where('estado_final', 'aplicada')->update(['estado_final' => 'pendiente', 'detalle' => null]);
    Carga::where('id', $carga->id)->update(['estado' => 'consolidando']);
    $this->artisan('cargues:recuperar', ['carga' => $carga->id])->assertExitCode(0);

    expect(Carga::find($carga->id)->estado)->toBe('completada')->and(e2_foto($carga))->toBe($foto);
});

test('las observaciones del procedimiento (fila aplicada con cg_codigo) quedan como advertencia de la etapa ejecución y se cuentan aparte', function () {
    $t = CG_TABLA;
    [$carga, $admin] = e2_validada('procesar_validas', "INSERT INTO cg_negocio_b (guia, estado) SELECT guia, estado FROM {$t} WHERE cg_carga_id = p_carga_id AND cg_estado IS NULL;
        UPDATE {$t} SET cg_estado = 'aplicada', cg_detalle = 'insertada', cg_codigo = IF(guia = 'G0000002', 'DISCREPANCIA_X', NULL), cg_mensaje = IF(guia = 'G0000002', 'valor distinto', NULL) WHERE cg_carga_id = p_carga_id AND cg_estado IS NULL;");
    $carga = e2_ejecutar($carga, $admin);

    expect($carga->contadores['filas_aplicadas'])->toBe(3)->and($carga->contadores['filas_observadas'])->toBe(1)
        ->and(CargaIncidencia::where('carga_id', $carga->id)->where('etapa', 'ejecucion')->get(['codigo', 'severidad'])->toArray())->toBe([['codigo' => 'DISCREPANCIA_X', 'severidad' => 'advertencia']]);
});

test('progreso: una ejecución sin noticias más allá del timeout ofrece «Recuperar», nunca «Reintentar»', function () {
    [$carga] = e2_validada();
    Carga::where('id', $carga->id)->update(['estado' => 'ejecutando', 'progreso_actualizado_en' => now()->subSeconds((int) config('cargues.cola.timeout') + 30)]);
    $p = app(ProgresoService::class)->consultar(Carga::find($carga->id));

    expect($p['en_curso'])->toBeTrue()->and($p['en_ejecucion'])->toBeTrue()->and($p['sin_noticias'])->toBeTrue()
        ->and($p['puede_recuperar'])->toBeTrue()->and($p['puede_reintentar'])->toBeFalse();
});
