<?php

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Services\Configuracion\ActivarTipoService;
use ByLopez\Cargues\Services\Configuracion\ConexionTipo;
use ByLopez\Cargues\Services\Configuracion\ProteccionValidar;
use ByLopez\Cargues\Services\Configuracion\TiposService;
use ByLopez\Cargues\Tests\CarguesTestCase;

require_once __DIR__.'/../helpers.php';

uses(CarguesTestCase::class);

const CG_CUENTA = 'cg_validar';

beforeEach(function () {
    cg_limpiar();
    config(['cargues.cola.conexion' => 'sync']);
    cg_cuenta_restringida();
});

afterEach(function () {
    cg_limpiar();
    cg_conn()->unprepared('DROP USER IF EXISTS `'.CG_CUENTA.'`@`127.0.0.1`');
});

/** Crea (de nuevo) la cuenta restringida SIN permisos y la declara como cuenta de VALIDAR del tipo de pruebas. */
function cg_cuenta_restringida(bool $exigir = true): void
{
    $c = cg_conn();
    $c->unprepared('DROP USER IF EXISTS `'.CG_CUENTA.'`@`127.0.0.1`');
    $c->unprepared('CREATE USER `'.CG_CUENTA."`@`127.0.0.1` IDENTIFIED BY 'cg_validar_pw'");
    config([
        'cargues.validar.exigir_cuenta_restringida' => $exigir,
        'cargues.validar.conceder_al_activar' => true,
        'cargues.validar.cuentas.cargues_tipo_prueba' => ['usuario' => CG_CUENTA, 'clave' => 'cg_validar_pw'],
    ]);
}

/** Procedimiento que corre `$validar` en VALIDAR. `$seguridad`: INVOKER o DEFINER (el valor por defecto de MariaDB). */
function cg_sp_con(string $validar, string $seguridad = 'INVOKER', string $nombre = 'sp_cg_prueba'): void
{
    cg_sp($nombre, $validar);
    cg_conn()->unprepared("ALTER PROCEDURE `{$nombre}` SQL SECURITY {$seguridad}");
}

function cg_marca_sp(): string
{
    return 'UPDATE '.CG_TABLA." SET cg_estado = 'rechazada', cg_codigo = 'SIN_TELEFONO', cg_mensaje = 'sin telefono' WHERE cg_carga_id = p_carga_id AND telefono IS NULL;";
}

/** Activa un tipo cuyo procedimiento ya fue instalado (o falla con la excepcion del servicio). */
function cg_activar_con_sp(string $validar, string $seguridad = 'INVOKER', array $map = []): array
{
    cg_negocio();
    cg_sp_con($validar, $seguridad);
    [$tipo, $v, $admin] = cg_tipo(['map' => cg_mapa($map)]);
    $revision = app(TiposService::class)->confirmarContrato($admin, $v);

    return [$tipo, $v, $admin, $revision];
}

function cg_codigo_al_activar($admin, $v): ?string
{
    try {
        app(ActivarTipoService::class)->activar($admin, $v->fresh());
    } catch (OperacionInvalidaException $e) {
        return $e->codigo;
    }

    return null;
}

function cg_negocio_checksums(): array
{
    return ['a' => (string) cg_conn()->selectOne('CHECKSUM TABLE cg_negocio_a')->Checksum, 'b' => (string) cg_conn()->selectOne('CHECKSUM TABLE cg_negocio_b')->Checksum];
}

test('analizarGrants: acepta lo mínimo y rechaza escritura, GRANT OPTION, FILE, roles, EXECUTE de esquema y escritura por columna', function () {
    $p = app(ProteccionValidar::class);
    $ok = $p->analizarGrants([
        "GRANT USAGE ON *.* TO `u`@`h` IDENTIFIED BY PASSWORD '*X'",
        'GRANT SELECT ON `db`.`negocio` TO `u`@`h`',
        'GRANT SELECT, UPDATE ON `db`.`bylopez_cgi_t` TO `u`@`h`',
        'GRANT EXECUTE ON PROCEDURE `db`.`sp` TO `u`@`h`',
    ], 'db', 'bylopez_cgi_t');
    expect($ok['violaciones'])->toBe([])->and($ok['rutinas'])->toBe(['sp'])->and($ok['actualiza_intermedia'])->toBeTrue();

    $malas = [
        'GRANT ALL PRIVILEGES ON *.* TO `u`@`h`' => 'ALL PRIVILEGES sobre',
        'GRANT SELECT, INSERT ON `db`.* TO `u`@`h`' => 'INSERT',
        'GRANT UPDATE ON `db`.`negocio` TO `u`@`h`' => 'UPDATE sobre',
        'GRANT SELECT, UPDATE (estado) ON `db`.`negocio` TO `u`@`h`' => 'UPDATE sobre',
        'GRANT FILE ON *.* TO `u`@`h`' => 'FILE',
        'GRANT SELECT ON `db`.* TO `u`@`h` WITH GRANT OPTION' => 'WITH GRANT OPTION',
        'GRANT EXECUTE ON `db`.* TO `u`@`h`' => 'EXECUTE sobre',
        'GRANT EXECUTE ON PROCEDURE `otra`.`sp` TO `u`@`h`' => 'EXECUTE sobre PROCEDURE',
        'GRANT `un_rol` TO `u`@`h`' => 'no interpretable',
        'GRANT UPDATE ON `db`.`bylopez_cgi_otra` TO `u`@`h`' => 'UPDATE sobre',
    ];

    foreach ($malas as $linea => $esperado) {
        $r = $p->analizarGrants([$linea], 'db', 'bylopez_cgi_t');
        expect(implode(' | ', $r['violaciones']))->toContain($esperado);
    }
});

test('sin cuenta restringida configurada no se activa (y el motivo es explícito)', function () {
    config(['cargues.validar.cuentas.cargues_tipo_prueba' => null]);
    [, $v, $admin, $revision] = cg_activar_con_sp(cg_marca_sp());

    expect(collect($revision['hallazgos'])->pluck('codigo'))->toContain('VALIDAR_SIN_CUENTA_RESTRINGIDA')
        ->and($v->fresh()->revision_huella)->toBeNull()                       // con errores no hay confirmación
        ->and(cg_codigo_al_activar($admin, $v))->toBe('CONTRATO_PROCEDIMIENTO');
});

test('LA PREGUNTA: una cuenta con SELECT NO limita a un procedimiento SQL SECURITY DEFINER; se demuestra y se bloquea', function () {
    // El procedimiento (DEFINER = root, por defecto) escribe una tabla NO declarada con SQL dinámico: la revisión estática
    // solo avisa y el CHECKSUM no la mira.
    cg_negocio();
    cg_sp_con("SET @s = 'UPDATE cg_negocio_b SET estado = \"HACK\"'; PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;", 'DEFINER');
    cg_conn()->unprepared('GRANT SELECT ON `cg_tipo_test`.* TO `'.CG_CUENTA.'`@`127.0.0.1`');
    cg_conn()->unprepared('GRANT EXECUTE ON PROCEDURE `cg_tipo_test`.`sp_cg_prueba` TO `'.CG_CUENTA.'`@`127.0.0.1`');

    cg_conn()->table('cg_negocio_b')->insert(['guia' => 'B-1', 'estado' => 'ok']);
    $antes = cg_negocio_checksums();
    $cuenta = app(ConexionTipo::class)->pdoDedicada('cargues_tipo_prueba', 60, ['usuario' => CG_CUENTA, 'clave' => 'cg_validar_pw']);
    $cuenta->exec('CALL sp_cg_prueba(1, "x", "VALIDAR", @r, @m)');

    // La cuenta SOLO tiene SELECT y aun así la tabla cambió: corrió con los privilegios del definidor.
    expect(cg_negocio_checksums()['b'])->not->toBe($antes['b']);

    [, $v, $admin, $revision] = (function () {
        [$tipo, $v, $admin] = cg_tipo();

        return [$tipo, $v, $admin, app(TiposService::class)->confirmarContrato($admin, $v)];
    })();
    $hallazgos = collect($revision['hallazgos'])->where('nivel', 'error')->pluck('codigo');

    expect($hallazgos)->toContain('PROCEDIMIENTO_DEFINER')->and($v->fresh()->revision_huella)->toBeNull()
        ->and(cg_codigo_al_activar($admin, $v))->toBe('CONTRATO_PROCEDIMIENTO');
});

test('con INVOKER y la cuenta restringida: activa, concede lo mínimo y VALIDAR funciona sin tocar el negocio', function () {
    [, $v, $admin, $revision] = cg_activar_con_sp(cg_marca_sp());
    expect(collect($revision['hallazgos'])->where('nivel', 'error')->all())->toBe([]);

    $v = app(ActivarTipoService::class)->activar($admin, $v->fresh());
    [$tipo] = [$v->tipo];
    $antes = cg_negocio_checksums();
    $carga = cg_carga($tipo->fresh(), $admin, array_merge(cg_filas(3), [['G9', '', 'ENTREGADO', '1,00', '01/10/2026 10:00']]));
    $carga = cg_validar($carga, $admin);

    expect($carga->estado)->toBe('validada')->and(cg_negocio_checksums())->toBe($antes);

    $p = app(ProteccionValidar::class);
    expect($p->verificar(cg_conn(), 'cargues_tipo_prueba', 'sp_cg_prueba', CG_TABLA, null, true))->toBe([]);

    // Privilegios reales de la cuenta: nada de escritura sobre el negocio.
    $grants = implode("\n", array_column(cg_conn()->select('SHOW GRANTS FOR `'.CG_CUENTA.'`@`127.0.0.1`'), 'Grants for '.CG_CUENTA.'@127.0.0.1'));
    expect($grants)->toContain('GRANT SELECT, UPDATE ON `cg_tipo_test`.`'.CG_TABLA.'`')->and($grants)->toContain('GRANT SELECT ON `cg_tipo_test`.`cg_negocio_a`')
        ->and($grants)->not->toMatch('/INSERT|DELETE|ALL PRIVILEGES|GRANT OPTION/');
});

test('PREVENCIÓN: escritura en tabla DECLARADA o NO declarada, con COMMIT interno incluido, la rechaza el servidor y no queda nada', function () {
    $dinamico = "SET @s = 'UPDATE cg_negocio_b SET estado = \"HACK\"'; PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;";

    foreach ([
        'declarada' => "UPDATE cg_negocio_a SET estado = 'HACK';",
        'no declarada (SQL dinámico)' => $dinamico,
        'inserción' => "INSERT INTO cg_negocio_a (guia, estado) VALUES ('X', 'Y');",
        'borrado' => 'DELETE FROM cg_negocio_a;',
    ] as $caso => $sql) {
        cg_limpiar();
        [$tipo, $v, $admin] = cg_activar_con_sp($sql);
        $v = app(ActivarTipoService::class)->activar($admin, $v->fresh());
        $antes = cg_negocio_checksums();
        $carga = cg_validar(cg_carga($v->tipo->fresh(), $admin, cg_filas(2)), $admin);

        expect($carga->estado)->toBe('fallida_validacion', $caso)->and($carga->resultado['error']['codigo'])->toBe('PROCEDIMIENTO_FALLO', $caso)
            ->and($carga->mensaje)->toMatch('/denied|denegad/i', $caso)
            ->and(cg_negocio_checksums())->toBe($antes, $caso);   // ni siquiera con COMMIT: el servidor no lo dejó empezar
    }
});

test('si cambian los permisos de la cuenta después de activar, VALIDAR se bloquea ANTES de llamar al procedimiento', function () {
    [, $v, $admin] = cg_activar_con_sp(cg_marca_sp());
    $v = app(ActivarTipoService::class)->activar($admin, $v->fresh());
    $carga = cg_carga($v->tipo->fresh(), $admin, cg_filas(2));

    cg_conn()->unprepared('GRANT INSERT ON `cg_tipo_test`.`cg_negocio_b` TO `'.CG_CUENTA.'`@`127.0.0.1`');
    $carga = cg_validar($carga, $admin);

    expect($carga->estado)->toBe('fallida_validacion')->and($carga->resultado['error']['codigo'])->toBe('VALIDAR_SIN_PROTECCION')
        ->and($carga->mensaje)->toContain('INSERT');
});

test('triggers en la intermedia, rutinas ejecutables DEFINER y una cuenta igual a la del tipo se bloquean', function () {
    [, $v, $admin] = cg_activar_con_sp(cg_marca_sp());
    $v = app(ActivarTipoService::class)->activar($admin, $v->fresh());
    $p = app(ProteccionValidar::class);
    $codigos = fn () => collect($p->verificar(cg_conn(), 'cargues_tipo_prueba', 'sp_cg_prueba', CG_TABLA, null, true))->pluck('codigo')->all();

    expect($codigos())->toBe([]);

    // Una función DEFINER (root) que la cuenta pueda ejecutar es una vía de escalada.
    cg_conn()->unprepared('CREATE FUNCTION fn_cg_escala() RETURNS INT DETERMINISTIC RETURN 1');
    cg_conn()->unprepared('GRANT EXECUTE ON FUNCTION `cg_tipo_test`.`fn_cg_escala` TO `'.CG_CUENTA.'`@`127.0.0.1`');
    expect($codigos())->toContain('RUTINA_EJECUTABLE_DEFINER');
    cg_conn()->unprepared('DROP FUNCTION fn_cg_escala');

    // Un trigger se ejecuta con privilegios de su definidor, no con los de la cuenta.
    cg_conn()->unprepared('CREATE TRIGGER tg_cg_escala AFTER UPDATE ON `'.CG_TABLA.'` FOR EACH ROW UPDATE cg_negocio_b SET estado = "x"');
    expect($codigos())->toContain('INTERMEDIA_CON_TRIGGER');
    cg_conn()->unprepared('DROP TRIGGER tg_cg_escala');
    expect($codigos())->toBe([]);

    // Misma cuenta que la conexión del tipo: no restringe nada.
    config(['cargues.validar.cuentas.cargues_tipo_prueba' => ['usuario' => config('database.connections.cargues_tipo_prueba.username'), 'clave' => config('database.connections.cargues_tipo_prueba.password')]]);
    expect($codigos())->toContain('VALIDAR_CUENTA_ES_LA_DEL_TIPO');
});

test('sin conceder al activar, VALIDAR dice exactamente qué falta; el comando lo aplica y entonces funciona', function () {
    config(['cargues.validar.conceder_al_activar' => false]);
    [, $v, $admin] = cg_activar_con_sp(cg_marca_sp());
    $v = app(ActivarTipoService::class)->activar($admin, $v->fresh());
    $carga = cg_validar(cg_carga($v->tipo->fresh(), $admin, cg_filas(2)), $admin);

    expect($carga->estado)->toBe('fallida_validacion')->and($carga->resultado['error']['codigo'])->toBe('VALIDAR_SIN_PROTECCION')
        ->and($carga->mensaje)->toContain('GRANT SELECT, UPDATE')->and($carga->mensaje)->toContain('GRANT EXECUTE ON PROCEDURE');

    $this->artisan('cargues:proteccion-validar')->assertExitCode(1);    // solo muestra: no concede
    $this->artisan('cargues:proteccion-validar', ['--aplicar' => true])->assertExitCode(0);

    $reintento = cg_validar(cg_carga($v->tipo->fresh(), $admin, cg_filas(2)), $admin);
    expect($reintento->estado)->toBe('validada');
});

test('la huella protege el DEFINER: recrear el procedimiento como DEFINER tras activar bloquea VALIDAR', function () {
    [, $v, $admin] = cg_activar_con_sp(cg_marca_sp());
    $v = app(ActivarTipoService::class)->activar($admin, $v->fresh());
    $carga = cg_carga($v->tipo->fresh(), $admin, cg_filas(2));
    cg_sp_con(cg_marca_sp(), 'DEFINER');
    $carga = cg_validar($carga, $admin);

    expect($carga->estado)->toBe('fallida_validacion')->and($carga->resultado['error']['codigo'])->toBe('PROCEDIMIENTO_CAMBIO');
});

test('LÍMITE CERRADO: el COMMIT interno (modelo «procedimiento») que en la prueba sin cuenta confirmaba la escritura ahora la rechaza el servidor', function () {
    [, $v, $admin] = cg_activar_con_sp("UPDATE cg_negocio_a SET estado = 'HACK'; COMMIT;", 'INVOKER', ['transaccion' => 'procedimiento']);
    $v = app(ActivarTipoService::class)->activar($admin, $v->fresh());
    $antes = cg_negocio_checksums();
    $carga = cg_validar(cg_carga($v->tipo->fresh(), $admin, cg_filas(2)), $admin);

    expect($carga->estado)->toBe('fallida_validacion')->and($carga->resultado['error']['codigo'])->toBe('PROCEDIMIENTO_FALLO')
        ->and(cg_negocio_checksums())->toBe($antes);
});

test('cuenta compartida por dos tipos de la misma conexión: UPDATE solo sobre intermedias REGISTRADAS; otra tabla sigue bloqueando', function () {
    $p = app(ProteccionValidar::class);
    $lineas = ['GRANT SELECT, UPDATE ON `db`.`bylopez_cgi_t` TO `u`@`h`', 'GRANT SELECT, UPDATE ON `db`.`bylopez_cgi_otro` TO `u`@`h`', 'GRANT EXECUTE ON PROCEDURE `db`.`sp` TO `u`@`h`'];

    expect($p->analizarGrants($lineas, 'db', 'bylopez_cgi_t', ['bylopez_cgi_t', 'bylopez_cgi_otro'])['violaciones'])->toBe([])
        ->and(implode('|', $p->analizarGrants($lineas, 'db', 'bylopez_cgi_t', ['bylopez_cgi_t'])['violaciones']))->toContain('UPDATE sobre `db`.`bylopez_cgi_otro`');

    // Integración: dos tipos activos en la misma conexión, ambos con la protección cumplida.
    [, $v1, $admin] = cg_activar_con_sp(cg_marca_sp());
    app(ActivarTipoService::class)->activar($admin, $v1->fresh());
    $otraTabla = 'bylopez_cgi_envios_otro';
    cg_sp('sp_cg_otro', "UPDATE {$otraTabla} SET cg_estado = 'rechazada' WHERE cg_carga_id = p_carga_id AND telefono IS NULL;");
    cg_conn()->unprepared('ALTER PROCEDURE sp_cg_otro SQL SECURITY INVOKER');
    [, $v2] = cg_tipo(['admin' => $admin, 'procedimiento' => 'sp_cg_otro', 'map' => cg_mapa(['tabla' => $otraTabla])]);
    app(TiposService::class)->confirmarContrato($admin, $v2);
    app(ActivarTipoService::class)->activar($admin, $v2->fresh());

    expect($p->verificar(cg_conn(), 'cargues_tipo_prueba', 'sp_cg_prueba', CG_TABLA, null, true))->toBe([])
        ->and($p->verificar(cg_conn(), 'cargues_tipo_prueba', 'sp_cg_otro', $otraTabla, null, true))->toBe([]);
});
