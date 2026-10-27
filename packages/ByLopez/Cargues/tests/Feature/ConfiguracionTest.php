<?php

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Models\Estructura;
use ByLopez\Cargues\Models\TipoVersion;
use ByLopez\Cargues\Services\Configuracion\ActivarTipoService;
use ByLopez\Cargues\Services\Configuracion\ConexionTipo;
use ByLopez\Cargues\Services\Configuracion\IntermediaService;
use ByLopez\Cargues\Services\Configuracion\ProcedimientoService;
use ByLopez\Cargues\Services\Configuracion\TiposService;
use ByLopez\Cargues\Tests\CarguesTestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../helpers.php';

uses(CarguesTestCase::class);

beforeEach(function () {
    cg_limpiar();
    config(['cargues.cola.conexion' => 'sync']);
});

afterEach(fn () => cg_limpiar());

function cg_intenta(callable $f): ?OperacionInvalidaException
{
    try {
        $f();
    } catch (OperacionInvalidaException $e) {
        return $e;
    }

    return null;
}

function cg_checksum(string $tabla): string
{
    return (string) cg_conn()->selectOne("CHECKSUM TABLE `{$tabla}`")->Checksum;
}

function cg_existe(string $tabla): bool
{
    return app(IntermediaService::class)->existe(cg_conn(), $tabla);
}

test('F1-C1 un tipo de 5 campos con primaria, única e índice se activa SIN migración y crea la intermedia con sus llaves', function () {
    $migracionesAntes = DB::table('migrations')->count();
    [$tipo, $v] = cg_tipo_activo();

    expect(DB::table('migrations')->count())->toBe($migracionesAntes)
        ->and($v->estado)->toBe('activa')
        ->and(cg_existe(CG_TABLA))->toBeTrue()
        ->and(app(IntermediaService::class)->verificar(cg_conn(), $v->map))->toBe([]);

    $indices = collect(cg_conn()->getSchemaBuilder()->getIndexes(CG_TABLA))->map(fn ($i) => ['c' => $i['columns'], 'u' => $i['unique'], 'p' => $i['primary']])->all();

    expect($indices)->toContain(['c' => ['cg_carga_id', 'guia'], 'u' => true, 'p' => true])           // PK de negocio por carga
        ->toContain(['c' => ['cg_id'], 'u' => true, 'p' => false])                                      // cg_id UNIQUE
        ->toContain(['c' => ['cg_carga_id', 'cg_fila_origen'], 'u' => true, 'p' => false])
        ->toContain(['c' => ['cg_carga_id', 'telefono'], 'u' => true, 'p' => false])
        ->toContain(['c' => ['cg_carga_id', 'estado'], 'u' => false, 'p' => false])
        ->toContain(['c' => ['cg_carga_id', 'cg_estado'], 'u' => false, 'p' => false]);

    $columnas = collect(cg_conn()->getSchemaBuilder()->getColumns(CG_TABLA))->keyBy('name');

    expect($columnas)->toHaveKeys(['cg_id', 'cg_carga_id', 'cg_carga_dato_id', 'cg_fila_origen', 'cg_duplicado', 'cg_ausentes', 'cg_estado', 'cg_detalle', 'cg_codigo', 'cg_mensaje', 'cg_procesado_en'])
        ->and($columnas['guia']['collation'])->toBe('utf8mb4_nopad_bin')    // identificador: comparación exacta
        ->and($columnas['estado']['collation'])->not->toBe('utf8mb4_nopad_bin');

    $e = Estructura::first();
    expect($e->estado)->toBe('activa')->and($e->ddl_auditoria)->toContain('create table')->and($v->estructura_id)->toBe($e->id);
});

test('F1-C1 dos cargas con la misma guía conviven en la tabla compartida; en la misma carga, no', function () {
    cg_tipo_activo();
    $c = cg_conn();
    $fila = fn ($carga, $fo, $tel) => ['cg_carga_id' => $carga, 'cg_carga_dato_id' => $fo, 'cg_fila_origen' => $fo, 'guia' => 'G1', 'telefono' => $tel, 'estado' => 'X'];

    $c->table(CG_TABLA)->insert($fila(1, 1, '300'));
    $c->table(CG_TABLA)->insert($fila(2, 1, '300')); // misma guía y mismo teléfono en OTRA carga: válido

    expect($c->table(CG_TABLA)->count())->toBe(2);
    expect(fn () => $c->table(CG_TABLA)->insert($fila(1, 2, '301')))->toThrow(QueryException::class); // misma guía, misma carga
});

test('F1-C2 una configuración inválida se rechaza SIN crear nada en la conexión del tipo', function () {
    cg_negocio();
    cg_sp_ok();

    $casos = [
        'nombre de campo inválido' => function ($m) {
            $m['campos'][2]['nombre'] = 'Estado X';

            return $m;
        },
        'primaria nulable' => function ($m) {
            $m['llaves']['primaria'] = ['telefono'];

            return $m;
        },
        'texto en llave única' => function ($m) {
            $m['llaves']['unicas'] = [['estado']];

            return $m;
        },
        'campo reservado' => function ($m) {
            $m['campos'][2]['nombre'] = 'select';

            return $m;
        },
    ];

    foreach ($casos as $nombre => $cambiar) {
        [$tipo, $v, $admin] = cg_tipo(['map' => $cambiar(cg_mapa())]);
        $e = cg_intenta(fn () => app(ActivarTipoService::class)->activar($admin, $v));

        expect($e?->codigo)->toBe('CONFIGURACION_INVALIDA', $nombre);
        expect(cg_existe(CG_TABLA))->toBeFalse($nombre);
        expect($v->fresh()->estado)->toBe('borrador');
    }

    expect(Estructura::count())->toBe(0);
});

test('F1-C2 un UNIQUE largo que MariaDB aceptaría como HASH se rechaza antes de la DDL', function () {
    cg_negocio();
    cg_sp_ok();
    $campos = array_map(fn ($i) => ['nombre' => "c{$i}", 'tipo' => 'identificador', 'longitud' => 191, 'nulo' => false], range(1, 5));
    $mapa = cg_mapa();
    $mapa['campos'] = $campos;
    $mapa['llaves'] = ['primaria' => null, 'unicas' => [['c1', 'c2', 'c3', 'c4', 'c5']], 'indices' => []];

    [, $v, $admin] = cg_tipo(['map' => $mapa]);
    $e = cg_intenta(fn () => app(ActivarTipoService::class)->activar($admin, $v));

    expect($e->codigo)->toBe('CONFIGURACION_INVALIDA')->and(collect($e->detalles)->pluck('codigo'))->toContain('INDICE_SUPERA_LIMITE')->and(cg_existe(CG_TABLA))->toBeFalse();
});

test('F1-C2 map.tabla igual a una tabla existente no registrada se rechaza y no se toca', function () {
    cg_negocio();
    cg_sp_ok();
    cg_conn()->statement('CREATE TABLE '.CG_TABLA.' (x INT)');
    [, $v, $admin] = cg_tipo();
    app(TiposService::class)->confirmarContrato($admin, $v);

    expect(cg_intenta(fn () => app(ActivarTipoService::class)->activar($admin, $v->fresh()))?->codigo)->toBe('MAP_TABLA_EXISTENTE_NO_REGISTRADA');
    expect(cg_conn()->getSchemaBuilder()->hasColumn(CG_TABLA, 'x'))->toBeTrue()->and(Estructura::count())->toBe(0);
});

test('F1-C2 un procedimiento inexistente o con otra firma se rechaza', function () {
    cg_negocio();
    [, $v, $admin] = cg_tipo(['procedimiento' => 'sp_cg_no_existe']);
    expect(cg_intenta(fn () => app(ActivarTipoService::class)->activar($admin, $v))?->codigo)->toBe('PROCEDIMIENTO_INEXISTENTE');

    cg_conn()->unprepared('DROP PROCEDURE IF EXISTS sp_cg_firma_mala');
    cg_conn()->unprepared('CREATE PROCEDURE sp_cg_firma_mala(IN p_carga_id BIGINT, IN p_modo VARCHAR(10)) BEGIN SELECT 1; END');
    [, $v2, $admin2] = cg_tipo(['procedimiento' => 'sp_cg_firma_mala']);
    expect(cg_intenta(fn () => app(ActivarTipoService::class)->activar($admin2, $v2))?->codigo)->toBe('PROCEDIMIENTO_FIRMA');
    expect(cg_existe(CG_TABLA))->toBeFalse();
});

test('F1-C2 un campo obligatorio con regla de vacío distinta de «rechazar» se rechaza', function () {
    cg_negocio();
    cg_sp_ok();
    $m = cg_mapa();
    $m['campos'][3]['obligatorio'] = true;
    $m['campos'][3]['vacio'] = 'nulo';
    [, $v, $admin] = cg_tipo(['map' => $m]);

    expect(collect(cg_intenta(fn () => app(ActivarTipoService::class)->activar($admin, $v))->detalles)->pluck('codigo'))->toContain('OBLIGATORIO_VACIO_REGLA');
});

test('F1-C2b un tipo SIN política de errores se guarda como borrador y no se activa: la política nunca se elige sola', function () {
    cg_negocio();
    cg_sp_ok();
    [$tipo, $v, $admin] = cg_tipo(['politica' => null]);

    expect($v->politica_errores)->toBeNull();

    app(TiposService::class)->confirmarContrato($admin, $v);
    $e = cg_intenta(fn () => app(ActivarTipoService::class)->activar($admin, $v->fresh()));

    expect($e->codigo)->toBe('CONFIGURACION_INVALIDA')
        ->and(collect($e->detalles)->pluck('codigo'))->toContain('POLITICA_ERRORES_OBLIGATORIA')
        ->and($e->getMessage())->toContain('política de errores')
        ->and($v->fresh()->estado)->toBe('borrador')
        ->and($tipo->fresh()->activo)->toBeFalse()
        ->and(cg_existe(CG_TABLA))->toBeFalse();

    // Elegirla (cualquiera de las dos) permite activar: ninguna se prefiere.
    app(TiposService::class)->guardarBorrador($admin, $tipo, ['politica_errores' => 'procesar_validas']);
    $v2 = $tipo->versiones()->first();
    app(TiposService::class)->confirmarContrato($admin, $v2);

    expect(app(ActivarTipoService::class)->activar($admin, $v2->fresh())->politica_errores)->toBe('procesar_validas');
});

test('una política fuera de las dos permitidas se rechaza', function () {
    expect(cg_intenta(fn () => cg_tipo(['politica' => 'la_que_sea']))?->codigo)->toBe('POLITICA_INVALIDA');
});

test('activar exige la confirmación humana de la revisión del contrato, ligada a la huella del procedimiento', function () {
    cg_negocio();
    cg_sp_ok();
    [, $v, $admin] = cg_tipo();

    expect(cg_intenta(fn () => app(ActivarTipoService::class)->activar($admin, $v))?->codigo)->toBe('REVISION_PENDIENTE');

    $revision = app(TiposService::class)->confirmarContrato($admin, $v);
    expect($revision['hallazgos'])->toBeArray()->and($revision['aviso'])->toContain('heurística')->and($v->fresh()->revision_admin_id)->toBe($admin->id);

    // Si el procedimiento cambia después de la confirmación, hay que confirmar de nuevo.
    cg_sp('sp_cg_prueba', 'UPDATE '.CG_TABLA." SET cg_estado = 'rechazada', cg_codigo = 'OTRO' WHERE cg_carga_id = p_carga_id AND telefono IS NULL;");
    expect(cg_intenta(fn () => app(ActivarTipoService::class)->activar($admin, $v->fresh()))?->codigo)->toBe('REVISION_PENDIENTE');
});

test('la revisión del contrato bloquea un procedimiento con DDL, escritura en una tabla no declarada o COMMIT en el modelo «motor»', function () {
    cg_negocio();

    $malos = [
        'DDL_EN_PROCEDIMIENTO' => 'DROP TABLE IF EXISTS cg_negocio_b;',
        'ESCRITURA_NO_DECLARADA' => "INSERT INTO cg_negocio_b (guia, estado) VALUES ('X', 'Y');",
        'TRANSACCION_EN_MODELO_MOTOR' => 'COMMIT;',
    ];

    foreach ($malos as $codigo => $cuerpo) {
        cg_sp('sp_cg_malo', $cuerpo);
        [, $v, $admin] = cg_tipo(['procedimiento' => 'sp_cg_malo', 'nombre' => 'Malo '.$codigo]);
        $revision = app(TiposService::class)->confirmarContrato($admin, $v);

        expect(collect($revision['hallazgos'])->where('nivel', 'error')->pluck('codigo'))->toContain($codigo);
        expect($v->fresh()->revision_huella)->toBeNull();   // con errores no queda confirmada
        expect(cg_intenta(fn () => app(ActivarTipoService::class)->activar($admin, $v->fresh()))?->codigo)->toBe('CONTRATO_PROCEDIMIENTO');
        expect(cg_existe(CG_TABLA))->toBeFalse();

        cg_limpiar();
        cg_negocio();
    }
});

test('la revisión advierte (sin afirmar nada) de SQL dinámico, CALL anidado y de no mencionar VALIDAR', function () {
    cg_negocio();
    cg_conn()->unprepared('DROP PROCEDURE IF EXISTS sp_cg_vago');
    cg_conn()->unprepared("CREATE PROCEDURE sp_cg_vago(IN p_carga_id BIGINT, IN p_accion VARCHAR(40), IN p_modo VARCHAR(10), OUT p_resultado VARCHAR(10), OUT p_mensaje VARCHAR(500))
        BEGIN SET @q = 'SELECT 1'; PREPARE s FROM @q; EXECUTE s; CALL otro_procedimiento(); SET p_resultado = 'OK'; END");
    [, $v, $admin] = cg_tipo(['procedimiento' => 'sp_cg_vago']);

    $codigos = collect(app(TiposService::class)->confirmarContrato($admin, $v)['hallazgos'])->pluck('codigo');

    expect($codigos)->toContain('SQL_DINAMICO')->toContain('CALL_ANIDADO')->toContain('SIN_MODO_VALIDAR')->toContain('NO_USA_CARGA_ID');
});

test('F1-C3 reactivar con la misma huella de estructura reutiliza la tabla y T3 no cambia', function () {
    [$tipo, $v1, $admin] = cg_tipo_activo();
    $e1 = Estructura::first();
    $checksum = cg_checksum(CG_TABLA);

    // Cambia solo una etiqueta y un sinónimo (no forman parte de la huella de estructura).
    $mapa = $v1->map;
    $mapa['campos'][0]['etiqueta'] = 'Número de guía';
    $mapa['campos'][0]['sinonimos'] = ['guia_envio'];
    app(TiposService::class)->guardarBorrador($admin, $tipo, ['map' => $mapa]);
    $v2 = $tipo->versiones()->where('estado', 'borrador')->first();
    app(TiposService::class)->confirmarContrato($admin, $v2);
    $v2 = app(ActivarTipoService::class)->activar($admin, $v2->fresh());

    expect($v2->numero)->toBe(2)->and($v2->estructura_id)->toBe($e1->id)
        ->and(Estructura::count())->toBe(1)->and($e1->fresh()->updated_at->equalTo($e1->updated_at))->toBeTrue()
        ->and(cg_checksum(CG_TABLA))->toBe($checksum)
        ->and($v1->fresh()->estado)->toBe('retirada')->and($tipo->fresh()->version_vigente_id)->toBe($v2->id);
});

/** Cambia la estructura: agrega un campo. */
function cg_cambio_estructural($tipo, $admin): ?TipoVersion
{
    $mapa = cg_version($tipo)->map;
    $mapa['campos'][] = ['nombre' => 'ciudad', 'etiqueta' => 'Ciudad', 'tipo' => 'texto', 'longitud' => 60, 'nulo' => true, 'vacio' => 'nulo'];
    app(TiposService::class)->guardarBorrador($admin, $tipo, ['map' => $mapa]);
    $v = $tipo->versiones()->where('estado', 'borrador')->first();
    app(TiposService::class)->confirmarContrato($admin, $v);

    return app(ActivarTipoService::class)->activar($admin, $v->fresh());
}

test('F1-C4 cambio estructural con una carga abierta: se bloquea y la tabla queda idéntica', function () {
    [$tipo, , $admin] = cg_tipo_activo();
    $carga = cg_carga($tipo, $admin, cg_filas(2)); // recibida = abierta
    $antes = cg_checksum(CG_TABLA);
    $columnas = cg_conn()->getSchemaBuilder()->getColumnListing(CG_TABLA);

    $e = cg_intenta(fn () => cg_cambio_estructural($tipo, $admin));

    expect($e->codigo)->toBe('CARGAS_ABIERTAS')->and($e->detalles['cargas'])->toBe([$carga->id])
        ->and(cg_checksum(CG_TABLA))->toBe($antes)
        ->and(cg_conn()->getSchemaBuilder()->getColumnListing(CG_TABLA))->toBe($columnas)
        ->and(cg_existe(CG_TABLA.'__nueva'))->toBeFalse()
        ->and(Estructura::where('estado', 'en_cambio')->count())->toBe(0);

    // Tras cancelar las cargas abiertas (acción explícita) el cambio procede.
    expect(app(ActivarTipoService::class)->cancelarCargasAbiertas($admin, $tipo))->toBe(1)->and($carga->fresh()->estado)->toBe('cancelada');
    expect(cg_cambio_estructural($tipo, $admin)->estado)->toBe('activa');
});

test('F1-C4 si la creación de «__nueva» falla, nada cambia', function () {
    [$tipo, , $admin] = cg_tipo_activo();
    cg_conn()->table(CG_TABLA)->insert(['cg_carga_id' => 1, 'cg_carga_dato_id' => 1, 'cg_fila_origen' => 1, 'guia' => 'G1', 'estado' => 'X']);
    $antes = cg_checksum(CG_TABLA);

    $this->app->bind(IntermediaService::class, fn ($app) => new class($app->make(ConexionTipo::class)) extends IntermediaService
    {
        public function crear(Connection $c, array $map, ?string $tabla = null): string
        {
            if (str_ends_with((string) $tabla, '__nueva')) {
                throw new RuntimeException('fallo forzado al crear la nueva estructura');
            }

            return parent::crear($c, $map, $tabla);
        }
    });

    $e = cg_intenta(fn () => cg_cambio_estructural($tipo, $admin));

    expect($e->codigo)->toBe('DDL_FALLIDA')->and(cg_checksum(CG_TABLA))->toBe($antes)
        ->and(cg_existe(CG_TABLA.'__nueva'))->toBeFalse()->and(Estructura::count())->toBe(1)->and(Estructura::first()->estado)->toBe('activa')
        ->and($tipo->versiones()->where('estado', 'borrador')->count())->toBe(1)
        ->and(cg_conn()->getSchemaBuilder()->hasColumn(CG_TABLA, 'ciudad'))->toBeFalse();
});

test('F1-C4 con éxito: la anterior queda como «__a1» con sus filas idénticas y la vigente tiene la estructura nueva', function () {
    [$tipo, , $admin] = cg_tipo_activo();
    cg_conn()->table(CG_TABLA)->insert([
        ['cg_carga_id' => 1, 'cg_carga_dato_id' => 1, 'cg_fila_origen' => 1, 'guia' => 'G1', 'telefono' => '300', 'estado' => 'X'],
        ['cg_carga_id' => 1, 'cg_carga_dato_id' => 2, 'cg_fila_origen' => 2, 'guia' => 'G2', 'telefono' => '301', 'estado' => 'Y'],
    ]);
    $antes = cg_checksum(CG_TABLA);

    $v2 = cg_cambio_estructural($tipo, $admin);

    expect(cg_checksum(CG_TABLA.'__a1'))->toBe($antes)                                   // sus filas idénticas
        ->and(cg_conn()->table(CG_TABLA.'__a1')->count())->toBe(2)
        ->and(cg_conn()->table(CG_TABLA)->count())->toBe(0)                                // la vigente es nueva y vacía
        ->and(cg_conn()->getSchemaBuilder()->hasColumn(CG_TABLA, 'ciudad'))->toBeTrue()
        ->and(app(IntermediaService::class)->verificar(cg_conn(), $v2->map))->toBe([])
        ->and(Estructura::where('estado', 'activa')->count())->toBe(1)
        ->and(Estructura::where('estado', 'archivada')->value('archivada_como'))->toBe(CG_TABLA.'__a1')
        ->and(cg_existe(CG_TABLA.'__nueva'))->toBeFalse();
});

/** Deja T3 y las tablas como si el proceso hubiera caído en cada punto del cambio estructural. */
function cg_cambio_a_medias(string $punto): Estructura
{
    [$tipo, $v1] = cg_tipo_activo();
    $vigente = Estructura::first();
    $nueva = CG_TABLA.'__nueva';
    $archivo = CG_TABLA.'__a1';
    $c = cg_conn();
    $c->statement("CREATE TABLE `{$nueva}` (cg_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, marca VARCHAR(10))");

    $fila = Estructura::create([
        'tipo_id' => $tipo->id, 'conexion' => 'cargues_tipo_prueba', 'nombre_tabla' => CG_TABLA, 'estructura_huella' => str_repeat('b', 64),
        'estado' => 'en_cambio', 'nombre_nueva' => $nueva, 'archivada_como' => $archivo,
    ]);

    if ($punto === 'despues_del_rename') {
        $c->statement('RENAME TABLE `'.CG_TABLA."` TO `{$archivo}`, `{$nueva}` TO `".CG_TABLA.'`');
    }

    if ($punto === 'estado_parcial') {
        $c->statement('DROP TABLE `'.CG_TABLA.'`');                       // sin vigente
        $c->statement("CREATE TABLE `{$archivo}` (x INT)");               // y el archivo también
    }

    return $fila;
}

test('F1-C5 cortado DESPUÉS del RENAME, el siguiente intento completa T3', function () {
    $fila = cg_cambio_a_medias('despues_del_rename');

    expect(app(ActivarTipoService::class)->reconciliar(cg_conn(), $fila))->toBe('completada');

    expect($fila->fresh()->estado)->toBe('activa')->and($fila->fresh()->archivada_como)->toBeNull()
        ->and(Estructura::where('estado', 'archivada')->value('archivada_como'))->toBe(CG_TABLA.'__a1');
});

test('F1-C5 cortado ANTES del RENAME, se descarta «__nueva» y la vigente queda intacta', function () {
    $fila = cg_cambio_a_medias('antes_del_rename');
    $antes = cg_checksum(CG_TABLA);

    expect(app(ActivarTipoService::class)->reconciliar(cg_conn(), $fila))->toBe('descartada');

    expect(cg_existe(CG_TABLA.'__nueva'))->toBeFalse()->and(Estructura::where('estado', 'en_cambio')->count())->toBe(0)
        ->and(Estructura::where('estado', 'activa')->count())->toBe(1)->and(cg_checksum(CG_TABLA))->toBe($antes);
});

test('F1-C5 con el estado parcial se bloquean las ejecuciones del tipo y se pide intervención manual', function () {
    $fila = cg_cambio_a_medias('estado_parcial');

    $e = cg_intenta(fn () => app(ActivarTipoService::class)->reconciliar(cg_conn(), $fila));

    expect($e->codigo)->toBe('ESTRUCTURA_INTERVENCION_MANUAL')
        ->and(app(ActivarTipoService::class)->bloqueada('cargues_tipo_prueba', CG_TABLA))->toBeTrue()
        ->and($fila->fresh()->estado)->toBe('en_cambio');
});

test('F1-C6 si cambia el cuerpo del procedimiento sin una versión nueva del tipo, la validación se bloquea con un motivo', function () {
    [$tipo, , $admin] = cg_tipo_activo();
    $carga = cg_carga($tipo, $admin, cg_filas(2));

    cg_sp('sp_cg_prueba', 'UPDATE '.CG_TABLA." SET cg_estado = 'rechazada', cg_codigo = 'CAMBIADO' WHERE cg_carga_id = p_carga_id;");
    $carga = cg_validar($carga, $admin);

    expect($carga->estado)->toBe('fallida_validacion')->and($carga->mensaje)->toContain('PROCEDIMIENTO_CAMBIO');
    expect(Carga::find($carga->id)->resultado['error']['codigo'])->toBe('PROCEDIMIENTO_CAMBIO');
});

test('F1-C6 si cambia solo el sql_mode del procedimiento (mismo cuerpo), también se bloquea', function () {
    [$tipo, , $admin] = cg_tipo_activo();
    $carga = cg_carga($tipo, $admin, cg_filas(2));
    $c = cg_conn();
    $v = cg_version($tipo);
    $original = (string) $c->selectOne('SELECT @@SESSION.sql_mode AS m')->m;

    try {
        $c->unprepared("SET SESSION sql_mode = 'ANSI_QUOTES,NO_ENGINE_SUBSTITUTION'");
        cg_sp_ok();   // mismo cuerpo, otro sql_mode de la rutina
    } finally {
        $c->unprepared('SET SESSION sql_mode = '.$c->getPdo()->quote($original));
    }

    expect(app(ProcedimientoService::class)->congelar($c, 'sp_cg_prueba')['huella'])->not->toBe($v->procedimiento_huella);

    $carga = cg_validar($carga, $admin);

    expect($carga->estado)->toBe('fallida_validacion')->and($carga->mensaje)->toContain('PROCEDIMIENTO_CAMBIO');
});

test('el modo tabla no se puede activar en la Fase 1', function () {
    cg_negocio();
    [, $v, $admin] = cg_tipo();
    $v->update(['modo' => 'tabla', 'tabla_destino' => 'x', 'procedimiento' => null]);

    expect(cg_intenta(fn () => app(ActivarTipoService::class)->activar($admin, $v->fresh()))?->codigo)->toBe('CONFIGURACION_INVALIDA');
});

test('una versión activa no se edita: un cambio crea una versión nueva', function () {
    [$tipo, $v, $admin] = cg_tipo_activo();

    app(TiposService::class)->guardarBorrador($admin, $tipo, ['accion' => 'actualizar_estado']);

    expect($tipo->versiones()->count())->toBe(2)->and($v->fresh()->estado)->toBe('activa')->and($v->fresh()->map_huella)->toBe($v->map_huella);

    // Una versión activa no se puede volver a activar ni reconfirmar.
    expect(cg_intenta(fn () => app(ActivarTipoService::class)->activar($admin, $v->fresh()))?->codigo)->toBe('VERSION_NO_EDITABLE');
});

test('un parámetro desconocido al configurar el tipo se rechaza', function () {
    $admin = cg_admin();
    $e = cg_intenta(fn () => app(TiposService::class)->crear($admin, ['nombre' => 'X', 'tabla_destino' => 'users']));

    expect($e->codigo)->toBe('PARAMETROS_DESCONOCIDOS');
});

test('los límites propios del tipo solo pueden bajar los máximos globales', function () {
    cg_negocio();
    cg_sp_ok();
    [, $v] = cg_tipo(['limites' => ['max_celdas' => PHP_INT_MAX]]);

    expect(collect(app(TiposService::class)->validar($v))->pluck('codigo'))->toContain('LIMITES_INVALIDOS');
    [, $ok] = cg_tipo(['limites' => ['max_celdas' => 1000, 'max_filas' => 50], 'nombre' => 'Con límites']);
    expect(app(TiposService::class)->validar($ok))->toBe([]);
});
