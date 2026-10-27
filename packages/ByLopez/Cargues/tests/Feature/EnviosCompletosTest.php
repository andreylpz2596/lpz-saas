<?php

use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Models\CargaDato;
use ByLopez\Cargues\Tests\CarguesTestCase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../helpersEnvios.php';

uses(CarguesTestCase::class);

const CE_CUENTA = 'cg_validar';

beforeEach(function () {
    cg_limpiar();
    ce_limpiar();
    // El tipo usa la conexion PRINCIPAL de pruebas (donde vive Logistica) y VALIDAR corre con la cuenta restringida.
    $c = cg_conn();
    $c->unprepared('DROP USER IF EXISTS `'.CE_CUENTA.'`@`127.0.0.1`');
    $c->unprepared('CREATE USER `'.CE_CUENTA."`@`127.0.0.1` IDENTIFIED BY 'cg_validar_pw'");
    config([
        'cargues.cola.conexion' => 'sync',
        'cargues.conexiones_autorizadas' => ['cargues_tipo_prueba', 'mysql'],
        'cargues.validar.exigir_cuenta_restringida' => true,
        'cargues.validar.conceder_al_activar' => true,
        'cargues.validar.cuentas.mysql' => ['usuario' => CE_CUENTA, 'clave' => 'cg_validar_pw'],
    ]);
    ce_mapeo('1', 'INTER_RAPIDISIMO');
});

afterEach(function () {
    cg_limpiar();
    ce_limpiar();
    cg_conn()->unprepared('DROP USER IF EXISTS `'.CE_CUENTA.'`@`127.0.0.1`');
});

function ce_estado(int $guiaId): ?object
{
    return DB::table('bylopez_lg_guia_estados_proveedor')->where('guia_id', $guiaId)->first();
}

test('el tipo se activa con el SP y el map VERSIONADOS: contrato sin errores, INVOKER, protección de VALIDAR y permisos mínimos', function () {
    [, $v, , $revision] = ce_tipo();

    expect($v->estado)->toBe('activa')->and($v->politica_errores)->toBe('procesar_validas')
        ->and(collect($revision['hallazgos'])->where('nivel', 'error')->all())->toBe([])
        ->and($revision['tablas_escritas'])->toEqualCanonicalizing([CE_TABLA, 'bylopez_lg_guia_estado_proveedor_eventos', 'bylopez_lg_guia_estados_proveedor'])
        ->and(DB::selectOne('SELECT SECURITY_TYPE s FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = ?', [CE_PROCEDIMIENTO])->s)->toBe('INVOKER');

    $grants = implode("\n", array_map(fn ($g) => array_values((array) $g)[0], DB::select('SHOW GRANTS FOR `'.CE_CUENTA.'`@`127.0.0.1`')));
    expect($grants)->toContain('GRANT SELECT, UPDATE ON `lpz_saas_testing`.`'.CE_TABLA.'`')
        ->and($grants)->toContain('GRANT SELECT ON `lpz_saas_testing`.`bylopez_lg_guias`')
        ->and($grants)->not->toMatch('/INSERT|DELETE|ALL PRIVILEGES/');
});

test('VALIDAR (cuenta restringida): cruza por transportadora EFECTIVA + guía exacta y rechaza con motivo; no escribe nada de negocio', function () {
    [$tipo, , $admin] = ce_tipo();
    ce_guia('240000000001');
    ce_guia('240000000002');
    ce_guia('240000000005', '99ENVIOS');                     // registrada con el PROVEEDOR como transportadora
    $antes = ce_intocables();

    $carga = ce_validar($tipo, $admin, [
        ['g' => '240000000001'],                               // 2: válida
        ['g' => '240000000099'],                               // 3: no existe
        ['g' => '24000000004', 'tr' => '4'],                   // 4: coordinadora sin equivalencia
        ['g' => '240000000005'],                               // 5: existe, pero con otra transportadora
        ['g' => '240000000002', 'fa' => '22/09/2026'],         // 6: fecha no interpretable (motor)
        ['g' => '240000000007'],                               // 7 y 8: duplicada en el archivo (motor, rechazar_todas)
        ['g' => '240000000007'],
    ]);

    expect($carga->estado)->toBe('validada')->and(ce_codigos($carga))->toBe([
        '3:GUIA_NO_REGISTRADA:error:validacion_procedimiento',
        '4:TRANSPORTADORA_SIN_EQUIVALENCIA:error:validacion_procedimiento',
        '5:GUIA_CON_OTRA_TRANSPORTADORA:error:validacion_procedimiento',
        '6:FECHA_INVALIDA:error:validacion_motor',
        '7:LLAVE_DUPLICADA:error:validacion_motor',
        '8:LLAVE_DUPLICADA:error:validacion_motor',
    ])->and($carga->contadores['filas_validas'])->toBe(1)
        ->and(DB::table('bylopez_lg_guia_estados_proveedor')->count())->toBe(0)
        ->and(ce_intocables())->toBe($antes);

    $msg = DB::table('bylopez_cg_carga_incidencias')->where('carga_id', $carga->id)->where('codigo', 'GUIA_CON_OTRA_TRANSPORTADORA')->value('motivo');
    expect($msg)->toContain('99ENVIOS')->and($msg)->toContain('No se cruza');
});

test('EJECUTAR: aplica el estado reportado, concilia el recaudo EXACTO, registra valor_servicio literal y no toca guía, entregas, pagos ni comisiones', function () {
    [$tipo, , $admin] = ce_tipo();
    $g1 = ce_guia('240000000001', recaudo: 100000);
    $g2 = ce_guia('240000000002', recaudo: 100000);
    $g3 = ce_guia('240000000003', recaudo: 0);
    $g4 = ce_guia('240000000004', recaudo: 90000);
    $antes = ce_intocables();

    $carga = ce_validar($tipo, $admin, [
        ['g' => '240000000001', 'vc' => '100000'],                                        // coincide
        ['g' => '240000000002', 'vc' => '100500', 'vs' => '18213.940000000002'],          // difiere +500; vs con artefacto
        ['g' => '240000000003', 'vc' => '80000', 'cp' => '', 'cpt' => 'No'],             // sin contrapago: reportado 0 = interno 0
        ['g' => '240000000004', 'vc' => '90000', 'cpt' => ''],                             // contrapago SIN dato → sin_dato
        ['g' => '240000000099'],                                                           // rechazada (no existe)
    ]);
    $carga = ce_ejecutar($carga, $admin);

    expect($carga->estado)->toBe('completada')
        ->and($carga->contadores['filas_aplicadas'])->toBe(4)->and($carga->contadores['filas_rechazadas'])->toBe(1)
        ->and($carga->contadores['filas_observadas'])->toBe(2)->and(ce_intocables())->toBe($antes);

    $e1 = ce_estado($g1);
    $e2 = ce_estado($g2);
    $e3 = ce_estado($g3);
    $e4 = ce_estado($g4);
    expect([$e1->estado_proveedor, $e1->fecha_estado_proveedor, $e1->transportadora_codigo, $e1->proveedor, $e1->conciliacion_recaudo, $e1->recaudo_diferencia])
        ->toBe(['Entregada', '2026-09-22 15:30:00', 'INTER_RAPIDISIMO', '99envios', 'coincide', '0.00'])
        ->and([$e2->conciliacion_recaudo, $e2->recaudo_reportado, $e2->recaudo_interno, $e2->recaudo_diferencia, $e2->valor_servicio_reportado])
        ->toBe(['difiere', '100500.00', '100000.00', '500.00', '18213.940000000002'])
        ->and([$e3->conciliacion_recaudo, $e3->recaudo_reportado, $e3->aplica_contrapago_reportado])->toBe(['coincide', '0.00', 0])
        ->and([$e4->conciliacion_recaudo, $e4->recaudo_diferencia])->toBe(['sin_dato', null])
        ->and(DB::table('bylopez_lg_guia_estado_proveedor_eventos')->whereIn('guia_id', [$g1, $g2, $g3, $g4])->count())->toBe(4);

    // Observaciones visibles en el resultado y la guía intacta (precio, flete y recaudo internos sin cambio).
    expect(ce_codigos($carga))->toContain('3:DISCREPANCIA_RECAUDO:advertencia:ejecucion')->and(ce_codigos($carga))->toContain('5:RECAUDO_SIN_DATO:advertencia:ejecucion')
        ->and((string) DB::table('bylopez_lg_guias')->where('id', $g2)->value('recaudo_esperado'))->toBe('100000.00')
        ->and(CargaDato::where('carga_id', $carga->id)->where('fila_origen', 2)->value('detalle'))->toBe('insertada');
});

test('F1-R3 reprocesar el MISMO archivo: todo queda sin_cambio y los destinos no cambian (idempotente)', function () {
    [$tipo, , $admin] = ce_tipo();
    ce_guia('240000000001');
    ce_guia('240000000002');
    $filas = [['g' => '240000000001'], ['g' => '240000000002', 'est' => 'Admitida']];

    ce_ejecutar(ce_validar($tipo, $admin, $filas), $admin);
    $huella = [DB::selectOne('CHECKSUM TABLE bylopez_lg_guia_estados_proveedor')->Checksum, DB::table('bylopez_lg_guia_estado_proveedor_eventos')->count()];
    // updated_at y reportes no deben moverse: el reproceso no escribe nada
    $segunda = ce_ejecutar(ce_validar($tipo, $admin, $filas), $admin);

    expect($segunda->contadores['filas_sin_cambio'])->toBe(2)->and($segunda->contadores['filas_aplicadas'])->toBe(0)
        ->and([DB::selectOne('CHECKSUM TABLE bylopez_lg_guia_estados_proveedor')->Checksum, DB::table('bylopez_lg_guia_estado_proveedor_eventos')->count()])->toBe($huella);
});

test('F1-R4 no retroceso: un reporte ANTERIOR queda omitida_antigua; uno POSTERIOR actualiza y guarda el estado anterior en el historial', function () {
    [$tipo, , $admin] = ce_tipo();
    $g = ce_guia('240000000001');

    ce_ejecutar(ce_validar($tipo, $admin, [['g' => '240000000001', 'est' => 'Admitida', 'fa' => '2026-09-21 08:00:00']]), $admin);
    $vieja = ce_ejecutar(ce_validar($tipo, $admin, [['g' => '240000000001', 'est' => 'Creado', 'fa' => '2026-09-20 09:00:00']]), $admin);

    expect($vieja->contadores['filas_omitidas_antiguas'])->toBe(1)->and(ce_estado($g)->estado_proveedor)->toBe('Admitida');

    $nueva = ce_ejecutar(ce_validar($tipo, $admin, [['g' => '240000000001', 'est' => 'Entregada', 'fa' => '2026-09-25 17:00:00']]), $admin);
    $ultimo = DB::table('bylopez_lg_guia_estado_proveedor_eventos')->where('guia_id', $g)->orderByDesc('id')->first();

    expect($nueva->contadores['filas_aplicadas'])->toBe(1)->and(ce_estado($g)->estado_proveedor)->toBe('Entregada')->and(ce_estado($g)->reportes)->toBe(2)
        ->and([$ultimo->estado_anterior, $ultimo->fecha_estado_anterior, $ultimo->carga_id])->toBe(['Admitida', '2026-09-21 08:00:00', $nueva->id])
        ->and(CargaDato::where('carga_id', $nueva->id)->value('detalle'))->toBe('actualizada');
});

test('misma fecha con otro estado (CONFLICTO_MISMA_FECHA) y otra fecha de envío (GUIA_FECHA_ENVIO_DISTINTA) se rechazan sin elegir ganador', function () {
    [$tipo, , $admin] = ce_tipo();
    $g = ce_guia('240000000001');
    ce_guia('240000000002');
    ce_ejecutar(ce_validar($tipo, $admin, [['g' => '240000000001', 'est' => 'Admitida'], ['g' => '240000000002']]), $admin);

    $carga = ce_validar($tipo, $admin, [
        ['g' => '240000000001', 'est' => 'Entregada'],                         // misma fecha_actualizacion, otro estado
        ['g' => '240000000002', 'fe' => '2026-10-01 10:00:00', 'fa' => '2026-10-02 10:00:00'],   // otra fecha de envío
    ]);

    expect(ce_codigos($carga))->toBe(['2:CONFLICTO_MISMA_FECHA:error:validacion_procedimiento', '3:GUIA_FECHA_ENVIO_DISTINTA:error:validacion_procedimiento'])
        ->and(ce_estado($g)->estado_proveedor)->toBe('Admitida');
});

test('EJECUTAR vuelve a comprobar: si la equivalencia se desactiva entre validar y ejecutar, la fila se rechaza EN LA EJECUCIÓN', function () {
    [$tipo, , $admin] = ce_tipo();
    ce_guia('240000000001');
    $carga = ce_validar($tipo, $admin, [['g' => '240000000001']]);
    expect($carga->contadores['filas_validas'])->toBe(1);

    ce_mapeo('1', 'INTER_RAPIDISIMO', false);
    $carga = ce_ejecutar($carga, $admin);

    expect($carga->estado)->toBe('completada')->and($carga->contadores['filas_rechazadas'])->toBe(1)->and($carga->contadores['rechazadas_ejecucion'])->toBe(1)
        ->and(ce_codigos($carga))->toBe(['2:TRANSPORTADORA_SIN_EQUIVALENCIA:error:ejecucion'])
        ->and(DB::table('bylopez_lg_guia_estados_proveedor')->count())->toBe(0);
});

test('«bloquear todo» con rechazadas no ejecuta nada (409); el output de resultado completo lista cada fila con su estado y motivo', function () {
    [$tipo, , $admin] = ce_tipo('bloquear_todo');
    ce_guia('240000000001');
    $carga = ce_validar($tipo, $admin, [['g' => '240000000001'], ['g' => '240000000099']]);
    $this->actingAs($admin, 'admin');

    $r = $this->postJson(route('admin.cargues.cargas.ejecutar', $carga->id), ['confirmar_rechazadas' => 1]);
    expect($r->status())->toBe(409)->and(DB::table('bylopez_lg_guia_estados_proveedor')->count())->toBe(0);

    // Con procesar_validas en otra carga: resultado completo descargable
    [$tipo2] = (function () {
        cg_limpiar();
        ce_limpiar();
        ce_mapeo();

        return ce_tipo();
    })();
    ce_guia('240000000001', recaudo: 100000);
    $c2 = ce_ejecutar(ce_validar($tipo2, $admin, [['g' => '240000000001', 'vc' => '99000'], ['g' => '240000000099']]), $admin);
    $csv = $this->get(route('admin.cargues.cargas.resultado', [$c2->id, 'csv']))->streamedContent();

    expect($csv)->toContain('aplicada;insertada;ejecucion;advertencia;DISCREPANCIA_RECAUDO')
        ->and($csv)->toContain('rechazada;RECHAZADA_POR_PROCEDIMIENTO;validacion_procedimiento;error;GUIA_NO_REGISTRADA')
        ->and($csv)->toContain('numero_de_guia');
});

test('la transportadora puede llegar por NOMBRE (exportación sin código): se cruza con la equivalencia, sin truncar y sin debilitar la longitud', function () {
    [$tipo, , $admin] = ce_tipo();
    DB::table('bylopez_lg_proveedor_transportadoras')->where('codigo_externo', '1')->update(['nombre_externo' => 'interrapidisimo']);
    $g = ce_guia('240000000001');
    $antes = ce_intocables();

    // «transportadora_nombre» (AA, índice 26) relacionada al campo: 15 caracteres, más que los 10 de la versión 1.
    $carga = ce_validar($tipo, $admin, [
        ['g' => '240000000001', 'nom' => 'interrapidisimo'],   // 2: por nombre exacto → válida
        ['g' => '240000000002', 'nom' => 'coordinadora'],      // 3: nombre sin equivalencia
        ['g' => '240000000003', 'nom' => 'Interrapidisimo'],   // 4: mayúsculas distintas: igualdad EXACTA → sin equivalencia
        ['g' => '240000000004', 'nom' => str_repeat('x', 121)], // 5: más de 120: el motor la rechaza (no se trunca)
    ], 27, 26);

    expect(ce_codigos($carga))->toBe([
        '3:TRANSPORTADORA_SIN_EQUIVALENCIA:error:validacion_procedimiento',
        '4:TRANSPORTADORA_SIN_EQUIVALENCIA:error:validacion_procedimiento',
        '5:LONGITUD_EXCEDIDA:error:validacion_motor',
    ])->and($carga->contadores['filas_validas'])->toBe(1)->and(ce_intocables())->toBe($antes);

    $carga = ce_ejecutar($carga, $admin);
    expect($carga->estado)->toBe('completada')->and($carga->contadores['filas_aplicadas'])->toBe(1)
        ->and(ce_estado($g)->transportadora_codigo)->toBe('INTER_RAPIDISIMO')->and(ce_estado($g)->estado_proveedor)->toBe('Entregada');
});

test('si el valor coincide con más de una equivalencia activa (código de una, nombre de otra) se rechaza como TRANSPORTADORA_AMBIGUA', function () {
    [$tipo, , $admin] = ce_tipo();
    ce_guia('240000000001');
    DB::table('bylopez_lg_proveedor_transportadoras')->insert([
        'proveedor' => '99envios', 'codigo_externo' => '7', 'nombre_externo' => '1', 'transportadora_codigo' => 'OTRA', 'activo' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $carga = ce_validar($tipo, $admin, [['g' => '240000000001', 'tr' => '1']]); // «1» = código de una y nombre de otra

    expect(ce_codigos($carga))->toBe(['2:TRANSPORTADORA_AMBIGUA:error:validacion_procedimiento'])
        ->and(DB::table('bylopez_lg_guia_estados_proveedor')->count())->toBe(0);
});
test('una fila de totales del archivo (todo obligatorio vacío) es UNA fila afectada con 5 incidencias; la pantalla las separa', function () {
    [$tipo, , $admin] = ce_tipo();
    ce_guia('240000000001');
    $h = fopen('php://temp', 'r+');
    fputcsv($h, CE_ENCABEZADOS, ';', '"', '');
    fputcsv($h, ce_fila(['g' => '240000000001']), ';', '"', '');
    fwrite($h, str_repeat(';', 16)."=SUM(Q2:Q2)".str_repeat(';', 11)."\n");   // fila de totales: solo la columna Q trae una fórmula
    rewind($h);
    $archivo = \Illuminate\Http\UploadedFile::fake()->createWithContent('con_totales.csv', stream_get_contents($h));

    $servicio = app(\ByLopez\Cargues\Services\Cargas\CargasService::class);
    $carga = $servicio->crear($admin, ['tipo_id' => $tipo->id, 'archivo' => true], $archivo);
    $rel = (array) $carga->encabezados['sugerencias'];
    $rel['aplica_contrapago'] = 27;
    $carga = $servicio->validar($admin, $carga, ['relaciones' => $rel])->fresh();
    $r = app(\ByLopez\Cargues\Services\Cargas\ResumenService::class)->calcular($carga);

    $obl = collect($r['por_codigo'])->firstWhere('codigo', 'OBLIGATORIO_VACIO');
    expect($r['filas_total'])->toBe(2)->and($r['filas_validas'])->toBe(1)->and($r['filas_rechazadas'])->toBe(1)->and($r['rechazadas_motor'])->toBe(1)
        ->and($obl['filas'])->toBe(1)->and($obl['incidencias'])->toBe(5)                        // 1 fila afectada, 5 incidencias
        ->and($r['incidencias_total'])->toBe(array_sum(array_column($r['por_codigo'], 'incidencias')));

    $detalle = $servicio->detalle($carga);
    expect($detalle['version'])->toBe(['numero' => 1, 'procedimiento' => CE_PROCEDIMIENTO])
        ->and($servicio->tiposActivos()[0]['version'])->toBe(1);
});

test('Resultado por fila: tras validar muestra «pendiente» (nada aplicado); tras ejecutar muestra lo persistido y es igual al recargar', function () {
    [$tipo, , $admin] = ce_tipo();
    ce_guia('240000000001', recaudo: 100000);
    ce_guia('240000000002', recaudo: 100000);
    $this->actingAs($admin, 'admin');
    $carga = ce_validar($tipo, $admin, [
        ['g' => '240000000001', 'vc' => '99000'],     // 2: se aplicará con discrepancia de recaudo
        ['g' => '240000000002'],                      // 3: se aplicará sin observación
        ['g' => '240000000099'],                      // 4: guía inexistente
    ]);

    $ruta = fn ($q = []) => $this->getJson(route('admin.cargues.cargas.filas', $carga->id).($q ? '?'.http_build_query($q) : ''));
    $antes = $ruta()->assertOk()->json();
    expect(collect($antes['filas'])->pluck('estado')->all())->toBe(['pendiente', 'pendiente', 'rechazada'])
        ->and(DB::table('bylopez_lg_guia_estados_proveedor')->count())->toBe(0)
        ->and(collect($antes['filas'][0]['complemento'])->pluck('etiqueta')->all())->toContain('Estado del archivo');

    $carga = ce_ejecutar($carga, $admin);
    expect($carga->estado)->toBe('completada');

    $todas = $ruta()->assertOk()->json();
    $f2 = collect($todas['filas'])->firstWhere('fila_origen', 2);
    $pares = collect($f2['complemento'])->pluck('valor', 'etiqueta');
    expect($f2['estado'])->toBe('aplicada')->and($f2['identificacion'][1]['valor'])->toBe('240000000001')
        ->and($pares['Transportadora efectiva'])->toContain('INTER_RAPIDISIMO')->and($pares['Estado reportado anterior'])->toBe('ninguno')
        ->and($pares['Estado reportado nuevo'])->toContain('Entregada')->and($pares['Recaudo comparado'])->toContain('difiere')
        ->and(collect($f2['incidencias'])->pluck('codigo')->all())->toBe(['DISCREPANCIA_RECAUDO']);

    // Filtros: «con observación» es un SUBCONJUNTO de las aplicadas; búsqueda por guía; el resultado recargado es idéntico.
    expect($ruta(['estado' => 'observadas'])->json('total'))->toBe(1)->and($ruta(['estado' => 'aplicada'])->json('total'))->toBe(2)
        ->and($ruta(['estado' => 'rechazada'])->json('total'))->toBe(1)->and($ruta(['q' => '240000000002'])->json('filas.0.fila_origen'))->toBe(3)
        ->and($ruta()->json())->toBe($todas);

    $r = app(\ByLopez\Cargues\Services\Cargas\ResumenService::class)->calcular($carga);
    expect($r['filas_aplicadas'] + $r['filas_sin_cambio'] + $r['filas_omitidas_antiguas'] + $r['filas_rechazadas'] + $r['filas_no_ejecutadas'] + $r['filas_vacias'])->toBe($r['filas_total'])
        ->and($r['filas_observadas'])->toBe(1);
});

test('Resultado por fila: una carga que no está validada ni ejecutada no lo expone', function () {
    [$tipo, , $admin] = ce_tipo();
    $this->actingAs($admin, 'admin');
    $carga = app(\ByLopez\Cargues\Services\Cargas\CargasService::class)->crear($admin, ['tipo_id' => $tipo->id, 'archivo' => true], ce_csv([['g' => '240000000001']]));

    $this->getJson(route('admin.cargues.cargas.filas', $carga->id))->assertStatus(409);
});
