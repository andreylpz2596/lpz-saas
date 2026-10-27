<?php

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Models\CargaDato;
use ByLopez\Cargues\Models\CargaIncidencia;
use ByLopez\Cargues\Services\Cargas\CargasService;
use ByLopez\Cargues\Services\Cargas\DuplicadosService;
use ByLopez\Cargues\Services\Cargas\LlenadoIntermediaService;
use ByLopez\Cargues\Services\Cargas\OutputRechazadasService;
use ByLopez\Cargues\Services\Cargas\ValidarProcedimientoService;
use ByLopez\Cargues\Services\Configuracion\ConexionTipo;
use ByLopez\Cargues\Tests\CarguesTestCase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../helpersXlsx.php';

uses(CarguesTestCase::class);

beforeEach(function () {
    cg_limpiar();
    config(['cargues.cola.conexion' => 'sync']);
});

afterEach(fn () => cg_limpiar());

function cg_t6(Carga $c): array
{
    return CargaDato::where('carga_id', $c->id)->orderBy('fila_origen')->get()->keyBy('fila_origen')->all();
}

function cg_negocio_estado(): array
{
    return ['a' => (string) cg_conn()->selectOne('CHECKSUM TABLE cg_negocio_a')->Checksum, 'b' => (string) cg_conn()->selectOne('CHECKSUM TABLE cg_negocio_b')->Checksum];
}

test('F1-L3 T6 conserva el original SIN normalizar: ceros, +57, «=», espacios, texto vacío y celdas ausentes; lo normalizado va aparte', function () {
    [$tipo, , $admin] = cg_tipo_activo();
    $h = fopen('php://temp', 'r+');
    fwrite($h, "\xEF\xBB\xBF");

    foreach ([CG_ENCABEZADOS, ['0012345678', '+573001234567', 'ENTREGADO', '1.234,50', '01/10/2026 10:30'], ['=1+1', '', '  espacios  ', '', ''], ['G3'], []] as $f) {
        $f === [] ? fwrite($h, "\n") : fputcsv($h, $f, ';', '"', '');
    }

    rewind($h);
    $carga = app(CargasService::class)->crear($admin, ['tipo_id' => $tipo->id, 'archivo' => true], cg_csv(stream_get_contents($h)));
    $carga = cg_validar($carga, $admin);
    $t6 = cg_t6($carga);

    expect($carga->estado)->toBe('validada')->and(array_keys($t6))->toBe([2, 3, 4, 5])
        ->and(json_decode($t6[2]->datos_originales, true))->toBe(['0012345678', '+573001234567', 'ENTREGADO', '1.234,50', '01/10/2026 10:30'])
        ->and(json_decode($t6[3]->datos_originales, true))->toBe(['=1+1', '', '  espacios  ', '', ''])   // "" se conserva como ""
        ->and(json_decode($t6[4]->datos_originales, true))->toBe(['G3'])                                   // lo que falta, ausente
        ->and(json_decode($t6[5]->datos_originales, true))->toBe([])
        ->and($t6[5]->estado_final)->toBe('vacia')->and($t6[4]->estado_final)->toBe('rechazada');

    // Lo normalizado es otro campo; el original no cambia.
    $n3 = json_decode($t6[3]->datos_normalizados, true);
    expect($n3['valores']['estado'])->toBe('espacios')->and($n3['valores']['telefono'])->toBeNull()->and($n3['valores']['guia'])->toBe('=1+1')
        ->and(json_decode($t6[2]->datos_normalizados, true)['valores'])->toBe([
            'guia' => '0012345678', 'telefono' => '+573001234567', 'estado' => 'ENTREGADO', 'valor' => '1234.50', 'fecha_estado' => '2026-10-01 10:30:00',
        ]);

    // La intermedia recibe lo normalizado, y «=1+1» sigue siendo texto.
    $fila = cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->where('cg_fila_origen', 3)->first();
    expect($fila->guia)->toBe('=1+1')->and($fila->telefono)->toBeNull()->and($fila->cg_ausentes)->toBeNull();

    $inc = CargaIncidencia::where('carga_id', $carga->id)->where('fila_origen', 4)->pluck('codigo');
    expect($inc)->toContain('OBLIGATORIO_VACIO');
});

test('las columnas sin relacionar quedan en cg_ausentes (la intermedia no inventa NULL)', function () {
    [$tipo, , $admin] = cg_tipo_activo();
    $carga = cg_carga($tipo, $admin, cg_filas(2));
    $rel = $carga->encabezados['sugerencias'];
    $rel['valor'] = null;            // sin relacionar
    $rel['fecha_estado'] = null;
    $carga = cg_validar($carga, $admin, $rel);

    expect($carga->estado)->toBe('validada');
    $fila = cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->first();
    expect(json_decode($fila->cg_ausentes, true))->toBe(['valor', 'fecha_estado'])->and($fila->valor)->toBeNull();
    expect(json_decode(cg_t6($carga)[2]->datos_normalizados, true)['ausentes'])->toBe(['valor' => 'sin_relacionar', 'fecha_estado' => 'sin_relacionar']);
});

test('F1-D1 con las 12 filas de 08 §3.2 las tres políticas rechazan exactamente las filas indicadas, T6 conserva las 12 y T6 + intermedia cuadra', function () {
    $dataset = [
        2 => ['G100', '3001000001'], 3 => ['G101', '3001000002'], 4 => ['G102', ''], 5 => ['G101', '3001000003'], 6 => ['G103', ''], 7 => ['G104', '3001000004'],
        8 => ['G105', '3001000004'], 9 => ['G106', '3001000006'], 10 => ['G101', '3001000010'], 11 => ['G107', '3001000007'], 12 => ['0012345', '3001000008'], 13 => ['12345', '3001000009'],
    ];
    $filas = [CG_ENCABEZADOS];

    foreach ($dataset as [$g, $t]) {
        $filas[] = [$g, $t, 'ENTREGADO', '1.000', '02/10/2026 11:00'];
    }

    foreach (['rechazar_todas' => [3, 5, 7, 8, 10], 'primera' => [5, 8, 10], 'ultima' => [3, 5, 7]] as $politica => $esperadas) {
        $tabla = 'bylopez_cgi_dup_'.$politica;
        cg_negocio();
        cg_sp_nulo('sp_cg_'.$politica);
        [$tipo, , $admin] = cg_tipo_activo(['sin_sp' => true, 'procedimiento' => 'sp_cg_'.$politica, 'nombre' => 'Dup '.$politica, 'map' => cg_mapa(['tabla' => $tabla, 'duplicados' => $politica])]);

        $carga = cg_validar(cg_carga($tipo, $admin, $filas), $admin);
        $t6 = cg_t6($carga);
        $rech = collect($t6)->where('estado_final', 'rechazada')->keys()->all();

        expect($carga->estado)->toBe('validada', $politica)
            ->and($rech)->toEqualCanonicalizing($esperadas)
            ->and(count($t6))->toBe(12)                                                          // T6 conserva las 12
            ->and(cg_conn()->table($tabla)->where('cg_carga_id', $carga->id)->count() + count($rech))->toBe(12)   // T6 = intermedia + rechazadas
            ->and(array_keys($t6))->toBe(range(2, 13));

        // Cada rechazada deja su motivo con las filas en conflicto.
        foreach ($esperadas as $n) {
            $i = CargaIncidencia::where('carga_id', $carga->id)->where('fila_origen', $n)->where('codigo', 'LLAVE_DUPLICADA')->first();
            expect($i)->not->toBeNull()->and($i->motivo)->toContain('llave duplicada en el archivo');
        }

        // «0012345» y «12345» son identificadores distintos; los teléfonos vacíos (NULL) no colisionan.
        expect($t6[12]->estado_final)->toBe('pendiente')->and($t6[13]->estado_final)->toBe('pendiente')->and($t6[4]->estado_final)->toBe('pendiente');
    }
});

test('F1-D2 «G100» y «g100» se tratan igual en la detección y en la restricción: nunca una «aceptada» que la BD rechace', function () {
    cg_negocio();
    cg_sp_nulo();
    [$tipo, , $admin] = cg_tipo_activo(['sin_sp' => true]);
    $filas = [CG_ENCABEZADOS,
        ['G100', '3001', 'X', '', ''], ['g100', '3002', 'X', '', ''],       // distintas por mayúsculas (comparación exacta)
        ['G200', '3003', 'X', '', ''], ['G200 ', '3004', 'X', '', ''],      // iguales tras recortar: duplicadas
    ];

    $carga = cg_validar(cg_carga($tipo, $admin, $filas), $admin);
    $t6 = cg_t6($carga);

    expect($carga->estado)->toBe('validada')
        ->and($t6[2]->estado_final)->toBe('pendiente')->and($t6[3]->estado_final)->toBe('pendiente')
        ->and($t6[4]->estado_final)->toBe('rechazada')->and($t6[5]->estado_final)->toBe('rechazada')
        ->and(CargaIncidencia::where('carga_id', $carga->id)->where('codigo', 'LLAVE_DUPLICADA_BD')->count())->toBe(0)
        ->and(cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->pluck('guia')->all())->toEqualCanonicalizing(['G100', 'g100']);
});

test('la restricción real es la garantía final: lo que escapa a la detección se rechaza con motivo, no con INSERT IGNORE', function () {
    cg_negocio();
    cg_sp_nulo();
    [$tipo, , $admin] = cg_tipo_activo(['sin_sp' => true]);
    $this->app->bind(DuplicadosService::class, fn () => new class extends DuplicadosService
    {
        public function detectar(array $filas, array $llaves, string $politica): array
        {
            return [array_keys($filas), []];   // «se le escapan» todos los duplicados
        }
    });

    $carga = cg_validar(cg_carga($tipo, $admin, [CG_ENCABEZADOS, ['G1', '3001', 'A', '', ''], ['G1', '3002', 'B', '', ''], ['G2', '3003', 'C', '', '']]), $admin);
    $t6 = cg_t6($carga);

    expect($carga->estado)->toBe('validada')
        ->and($t6[2]->estado_final)->toBe('pendiente')->and($t6[3]->estado_final)->toBe('rechazada')->and($t6[3]->detalle)->toBe('LLAVE_DUPLICADA_BD')
        ->and($t6[4]->estado_final)->toBe('pendiente')
        ->and(json_decode($t6[3]->datos_originales, true))->toBe(['G1', '3002', 'B', '', ''])      // el original intacto
        ->and(cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->count())->toBe(2)
        ->and($carga->contadores['filas_rechazadas'])->toBe(1);
});

test('F1-V1 VALIDAR con un procedimiento correcto no cambia ninguna tabla de negocio y marca las rechazadas en la intermedia', function () {
    [$tipo, , $admin] = cg_tipo_activo();
    $antes = cg_negocio_estado();

    $carga = cg_validar(cg_carga($tipo, $admin, [CG_ENCABEZADOS, ['G1', '3001', 'A', '', ''], ['G2', '', 'B', '', ''], ['G3', '3003', 'C', '', '']]), $admin);

    expect($carga->estado)->toBe('validada')->and(cg_negocio_estado())->toBe($antes)
        ->and($carga->resultado['procedimiento']['resultado'])->toBe('PARCIAL')
        ->and(cg_t6($carga)[3]->estado_final)->toBe('rechazada')
        ->and(CargaIncidencia::where('carga_id', $carga->id)->where('etapa', 'validacion_procedimiento')->first()->codigo)->toBe('SIN_TELEFONO')
        ->and($carga->contadores['rechazadas_procedimiento'])->toBe(1)->and($carga->contadores['rechazadas_motor'])->toBe(0)
        ->and(cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->where('cg_estado', 'rechazada')->count())->toBe(1);

    // Terminó: el bloqueo con nombre quedó libre.
    $nombre = 'cg:'.app(ConexionTipo::class)->esquema(cg_conn()).':'.$carga->id;
    expect((int) cg_conn()->selectOne('SELECT IS_FREE_LOCK(?) AS l', [$nombre])->l)->toBe(1);
});

/** Instala un procedimiento defectuoso en VALIDAR y devuelve la carga ya procesada. */
function cg_validar_con(string $cuerpo, string $resultado = "'OK'", array $mapa = []): array
{
    cg_negocio();
    cg_sp('sp_cg_prueba', $cuerpo, $resultado);
    [$tipo, , $admin] = cg_tipo_activo(['sin_sp' => true, 'map' => cg_mapa($mapa)]);
    $antes = cg_negocio_estado();
    $carga = cg_validar(cg_carga($tipo, $admin, cg_filas(3)), $admin);

    return [$carga, $antes];
}

test('VALIDAR que modifica una tabla de negocio DECLARADA: se deshace todo y la carga falla con el motivo', function () {
    [$carga, $antes] = cg_validar_con("INSERT INTO cg_negocio_a (guia, estado) VALUES ('X', 'Y'); UPDATE ".CG_TABLA." SET cg_estado = 'rechazada' WHERE cg_carga_id = p_carga_id;");

    expect($carga->estado)->toBe('fallida_validacion')->and($carga->resultado['error']['codigo'])->toBe('VALIDAR_MODIFICO_NEGOCIO')
        ->and($carga->mensaje)->toContain('cg_negocio_a')
        ->and(cg_negocio_estado())->toBe($antes)                                                               // revertido
        ->and(cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->whereNotNull('cg_estado')->count())->toBe(0);   // las marcas también
});

test('VALIDAR que modifica los datos de entrada de la intermedia: se deshace y la carga falla', function () {
    [$carga] = cg_validar_con('UPDATE '.CG_TABLA." SET estado = 'CAMBIADO' WHERE cg_carga_id = p_carga_id;");

    expect($carga->estado)->toBe('fallida_validacion')->and($carga->resultado['error']['codigo'])->toBe('VALIDAR_MODIFICO_ENTRADA')
        ->and(cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->where('estado', 'CAMBIADO')->count())->toBe(0);
});

test('VALIDAR solo puede marcar «rechazada»: cualquier otro estado (p. ej. «aplicada») falla', function () {
    [$carga] = cg_validar_con('UPDATE '.CG_TABLA." SET cg_estado = 'aplicada' WHERE cg_carga_id = p_carga_id;");

    expect($carga->estado)->toBe('fallida_validacion')->and($carga->resultado['error']['codigo'])->toBe('VALIDAR_ESTADO_INVALIDO')
        ->and(cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->whereNotNull('cg_estado')->count())->toBe(0);
});

test('el procedimiento puede responder ERROR, un resultado fuera del contrato o lanzar SIGNAL: la carga falla con el motivo', function () {
    [$carga] = cg_validar_con('SET p_mensaje = \'catálogo no disponible\';', "'ERROR'");
    expect($carga->estado)->toBe('fallida_validacion')->and($carga->resultado['error']['codigo'])->toBe('PROCEDIMIENTO_ERROR')->and($carga->mensaje)->toContain('catálogo no disponible');

    cg_limpiar();
    [$carga] = cg_validar_con('SET p_mensaje = NULL;', "'QUIZAS'");
    expect($carga->resultado['error']['codigo'])->toBe('PROCEDIMIENTO_RESULTADO_INVALIDO');

    cg_limpiar();
    [$carga] = cg_validar_con("SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'regla rota';");
    expect($carga->resultado['error']['codigo'])->toBe('PROCEDIMIENTO_FALLO')->and($carga->mensaje)->toContain('regla rota');
});

test('LÍMITE DOCUMENTADO: un COMMIT dentro del procedimiento (modelo «procedimiento») no se puede deshacer, pero sí se detecta', function () {
    [$carga, $antes] = cg_validar_con("INSERT INTO cg_negocio_a (guia, estado) VALUES ('X', 'Y'); COMMIT;", "'OK'", ['transaccion' => 'procedimiento']);

    expect($carga->estado)->toBe('fallida_validacion')->and($carga->resultado['error']['codigo'])->toBe('VALIDAR_MODIFICO_NEGOCIO')
        ->and(cg_negocio_estado())->not->toBe($antes);   // el COMMIT interno ya confirmó la fila: el rollback ya no puede quitarla
});

test('VALIDAR no se solapa con otra sesión que tenga el bloqueo con nombre de la carga', function () {
    [$tipo, , $admin] = cg_tipo_activo();
    $carga = cg_carga($tipo, $admin, cg_filas(2));
    $nombre = 'cg:'.app(ConexionTipo::class)->esquema(cg_conn()).':'.$carga->id;
    $otra = app(ConexionTipo::class)->pdoDedicada('cargues_tipo_prueba');
    expect((int) $otra->query('SELECT GET_LOCK('.$otra->quote($nombre).', 0)')->fetchColumn())->toBe(1);

    $carga = cg_validar($carga, $admin);

    expect($carga->estado)->toBe('fallida_validacion')->and($carga->resultado['error']['codigo'])->toBe('CARGA_OCUPADA');

    $otra = null;   // al cerrar la sesión el servidor libera el bloqueo
});

test('antes de VALIDAR se comprueba que los datos de entrada sean los preparados', function () {
    [$tipo, , $admin] = cg_tipo_activo();
    $carga = cg_validar(cg_carga($tipo, $admin, cg_filas(3)), $admin);
    expect($carga->estado)->toBe('validada')->and($carga->huella_entrada)->toHaveLength(64);

    cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->where('cg_fila_origen', 2)->update(['estado' => 'MANIPULADO']);

    $e = null;

    try {
        app(ValidarProcedimientoService::class)->validar($carga, (string) $carga->job_uuid);
    } catch (OperacionInvalidaException $e) {
    }

    expect($e?->codigo)->toBe('ENTRADA_ALTERADA');
});

test('la intermedia se reconstruye al revalidar (solo antes de iniciar la ejecución) y los datos de entrada son inmutables después', function () {
    [$tipo, , $admin] = cg_tipo_activo();
    $carga = cg_validar(cg_carga($tipo, $admin, cg_filas(3)), $admin);

    $carga = cg_validar($carga, $admin);          // revalidar: reconstruye (T6 y la intermedia se rehacen)
    expect($carga->estado)->toBe('validada')->and(cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->count())->toBe(3)
        ->and($carga->huella_entrada)->toHaveLength(64);

    // Con un ejecucion_token (E2) ya no se toca nada.
    Carga::where('id', $carga->id)->update(['ejecucion_token' => str_repeat('a', 64)]);
    $e = null;

    try {
        app(LlenadoIntermediaService::class)->llenar($carga->fresh(), 'x');
    } catch (OperacionInvalidaException $e) {
    }

    expect($e?->codigo)->toBe('ENTRADA_INMUTABLE')->and(cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->count())->toBe(3);
    expect(fn () => cg_validar($carga->fresh(), $admin))->toThrow(OperacionInvalidaException::class);
});

test('el índice NO único marca cg_duplicado en las filas repetidas y las conserva todas', function () {
    cg_negocio();
    cg_sp_nulo();
    [$tipo, , $admin] = cg_tipo_activo(['sin_sp' => true]);
    $carga = cg_validar(cg_carga($tipo, $admin, [CG_ENCABEZADOS, ['G1', '3001', 'ENTREGADO', '', ''], ['G2', '3002', 'ENTREGADO', '', ''], ['G3', '3003', 'DEVUELTO', '', '']]), $admin);

    $r = cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->orderBy('cg_fila_origen')->pluck('cg_duplicado', 'guia')->all();

    expect($r)->toBe(['G1' => 1, 'G2' => 1, 'G3' => 0]);   // `estado` es un índice no único: las repetidas se marcan, no se rechazan
});

test('el output de rechazadas trae todas las columnas originales, en su orden, y neutraliza las fórmulas', function () {
    cg_negocio();
    cg_sp_nulo();
    [$tipo, , $admin] = cg_tipo_activo(['sin_sp' => true]);
    $carga = cg_validar(cg_carga($tipo, $admin, [CG_ENCABEZADOS, ['=1+1', '3001', '', '5', '01/10/2026 10:00'], ['G2', '3002', 'OK', '', '']]), $admin);

    ob_start();
    app(OutputRechazadasService::class)->csv($carga)->sendContent();
    $csv = ob_get_clean();
    $lineas = array_values(array_filter(explode("\n", str_replace("\r", '', ltrim($csv, "\xEF\xBB\xBF")))));

    expect($lineas[0])->toBe('fila_origen;estado_final;etapa;campo;codigo;motivo;"Número de guía";Teléfono;Estado;Valor;"Fecha del estado"')
        ->and($lineas)->toHaveCount(2)
        ->and($lineas[1])->toContain('2;rechazada;validacion_motor;estado;OBLIGATORIO_VACIO;')
        ->and($lineas[1])->toContain("'=1+1;3001;;5;\"01/10/2026 10:00\"")   // fórmula neutralizada con «'»; original en su orden
        ->and($lineas[1])->not->toContain(';=1+1;');
});

test('el output XLSX de rechazadas escribe todo como texto explícito (la fórmula no se evalúa)', function () {
    cg_negocio();
    cg_sp_nulo();
    [$tipo, , $admin] = cg_tipo_activo(['sin_sp' => true]);
    $carga = cg_validar(cg_carga($tipo, $admin, [CG_ENCABEZADOS, ['=1+1', '3001', '', '5', '01/10/2026 10:00']]), $admin);

    ob_start();
    app(OutputRechazadasService::class)->xlsx($carga)->sendContent();
    $bin = ob_get_clean();
    $ruta = cg_ruta_tmp('salida.xlsx');
    file_put_contents($ruta, $bin);
    $hoja = IOFactory::load($ruta)->getActiveSheet();

    expect($hoja->getCell('G2')->getValue())->toBe('=1+1')
        ->and($hoja->getCell('G2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($hoja->getStyle('G2')->getQuotePrefix())->toBeTrue()
        ->and($hoja->getCell('A1')->getValue())->toBe('fila_origen')->and($hoja->getCell('K1')->getValue())->toBe('Fecha del estado');
    @unlink($ruta);
});

test('un identificador numérico llega con una advertencia, que NO rechaza la fila', function () {
    cg_negocio();
    cg_sp_nulo();
    [$tipo, , $admin] = cg_tipo_activo(['sin_sp' => true]);
    $ruta = cg_ruta_tmp('numerico.xlsx');
    $h = '<row r="1"><c r="A1" t="str"><v>Guía</v></c><c r="B1" t="str"><v>Teléfono</v></c><c r="C1" t="str"><v>Estado</v></c></row>'
        .'<row r="2"><c r="A2"><v>12345678</v></c><c r="B2"><v>3001234567</v></c><c r="C2" t="str"><v>X</v></c></row>';
    cg_zip_xlsx($ruta, ['Hoja1' => cg_hoja_xml($h)]);

    $carga = app(CargasService::class)->crear($admin, ['tipo_id' => $tipo->id, 'archivo' => true], new UploadedFile($ruta, 'numerico.xlsx', null, null, true));
    $carga = cg_validar($carga, $admin, ['guia' => 0, 'telefono' => 1, 'estado' => 2]);

    expect($carga->estado)->toBe('validada')->and($carga->contadores['filas_validas'])->toBe(1)
        ->and(CargaIncidencia::where('carga_id', $carga->id)->where('severidad', 'advertencia')->pluck('codigo')->unique()->all())->toBe(['IDENTIFICADOR_NUMERICO'])
        ->and(cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->value('guia'))->toBe('12345678');
    @unlink($ruta);
});
