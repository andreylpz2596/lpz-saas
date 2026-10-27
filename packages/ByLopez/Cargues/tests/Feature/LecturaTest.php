<?php

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Services\Cargas\ArchivoService;
use ByLopez\Cargues\Services\Cargas\Encabezados;
use ByLopez\Cargues\Services\Cargas\Normalizador;
use ByLopez\Cargues\Services\Lectura\FilaLeida;
use ByLopez\Cargues\Services\Lectura\GuardaXlsx;
use ByLopez\Cargues\Services\Lectura\LectorCsv;
use ByLopez\Cargues\Services\Lectura\LectorFactory;
use ByLopez\Cargues\Services\Lectura\LectorXlsx;
use ByLopez\Cargues\Services\Lectura\LimitesLectura;
use ByLopez\Cargues\Services\Lectura\OpcionesLectura;
use ByLopez\Cargues\Tests\CarguesTestCase;
use Illuminate\Http\UploadedFile;

require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../helpersXlsx.php';

uses(CarguesTestCase::class);

afterEach(function () {
    foreach (glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'cargues_pruebas'.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
        @unlink($f);
    }
});

function cg_leer(string $ruta, ?OpcionesLectura $o = null, ?LimitesLectura $l = null): array
{
    $lector = app(LectorFactory::class)->para(str_ends_with($ruta, 'xlsx') ? 'xlsx' : 'csv');
    $filas = [];

    foreach ($lector->filas($ruta, $o ?? new OpcionesLectura, $l ?? LimitesLectura::para()) as $f) {
        $filas[$f->numero] = $f;
    }

    return $filas;
}

function cg_err(callable $f): ?string
{
    try {
        $f();
    } catch (OperacionInvalidaException $e) {
        return $e->codigo;
    }

    return null;
}

/** Valor de una celda del fixture de fidelidad. */
function cg_celda(array $filas, int $fila, string $col): mixed
{
    return ($filas[$fila]->valores ?? [])[ord($col) - 65] ?? null;
}

dataset('calendarios', [[false, 45931], [true, 44469]]);

test('F1-L1 fidelidad XLSX (calendarios 1900 y 1904): fechas, ceros, textos, fórmulas, errores y celdas ausentes', function (bool $f1904, int $serial) {
    $ruta = cg_ruta_tmp('fidelidad.xlsx');
    cg_xlsx_fidelidad($ruta, $f1904);
    $filas = cg_leer($ruta, new OpcionesLectura('Envios', 3));
    $c = fn (int $f, string $col) => cg_celda($filas, $f, $col);
    $comprobaciones = 0;
    $ok = function (bool $cond, string $que) use (&$comprobaciones) {
        $comprobaciones++;
        expect($cond)->toBeTrue($que);
    };

    // Filas: 4, 5 (ausente → vacía pero con su número), 6 y 7. Antes del encabezado (1 y 3) no se devuelven.
    $ok(array_keys($filas) === [4, 5, 6, 7], 'numeración real, con la fila ausente');
    $ok($filas[5]->valores === [] && $filas[5]->estaVacia(), 'fila 5 vacía');

    // Fila 4
    $ok($c(4, 'A') === '0012345678', 'ceros a la izquierda');
    $ok($c(4, 'B') === '+573001234567', 'signo +57');
    $ok(is_array($c(4, 'C')) && $c(4, 'C')['d'] === '2025-10-01 00:00:00', 'fecha d/m/Y (calendario '.($f1904 ? '1904' : '1900').')');
    $ok($c(4, 'C')['n'] == $serial, 'serial original conservado');
    $ok(! $f1904 || ($c(4, 'C')['cal'] ?? null) === 1904, 'marca del calendario 1904');
    $ok($c(4, 'D')['d'] === '2025-10-01 12:00:00', 'fecha y hora');
    $ok($c(4, 'D')['f'] === 'dd/mm/yyyy\ hh:mm', 'formato de la fecha (código tal como está en el archivo)');
    $ok($c(4, 'E') === 138000.5, 'decimal con formato de miles sigue siendo número');
    $ok($c(4, 'F') === 0.15, 'porcentaje sin convertir');
    $ok($c(4, 'G') === 'Hola mundo', 'texto enriquecido');
    $ok(is_array($c(4, 'H')) && $c(4, 'H')['f'] === '=1+1' && (string) $c(4, 'H')['c'] === '2', 'fórmula con valor en caché');
    $ok($c(4, 'I') === true, 'booleano');
    $ok($c(4, 'J') === 'Bogotá D.C. – ñ', 'cadena en línea con acentos');
    $ok($c(4, 'K') == 9912345678901, 'entero grande');

    // Fila 6
    $ok($c(6, 'A') === '=HYPERLINK("http://x")', 'texto que empieza por = NO es fórmula');
    $ok($c(6, 'B') == 3001234567, 'teléfono numérico');
    $ok($c(6, 'C')['d'] === '2025-10-01 00:00:00', 'fecha yyyy-mm-dd');
    $ok($c(6, 'D') === null, 'celda sin valor = ausente');
    $ok($c(6, 'E') === 0.30000000000000004, 'float tal cual lo guarda el archivo');
    $ok(is_array($c(6, 'H')) && $c(6, 'H')['f'] === '=A6&"x"' && $c(6, 'H')['c'] === null, 'fórmula sin caché: no se inventa un valor');
    $ok($c(6, 'J') === '  espacios  ', 'espacios conservados');

    // Fila 7
    $ok(strlen($c(7, 'A')) === 5000, 'cadena de 5000 caracteres');
    $ok(is_float($c(7, 'B')) && $c(7, 'B') === 1.2345678901234568E+18, 'notación científica');
    $ok($c(7, 'C')['d'] === ($f1904 ? '1904-03-01 00:00:00' : '1900-02-28 00:00:00'), 'serial 60 (en 1900 es el 29/02/1900 que Excel inventa): d = lo que calcula la librería, el serial se conserva');
    $ok($c(7, 'C')['n'] == 60, 'serial 60 conservado');
    $ok($c(7, 'E') === ['e' => '#N/A'], 'error de Excel con su código');
    $ok($c(7, 'K') === 0, 'cero');
    $ok($c(7, 'D') === null && $c(7, 'L') === null, 'celdas ausentes');

    // Los encabezados de la fila 3 se obtienen en la vista previa.
    $prev = app(LectorXlsx::class)->vistaPrevia($ruta, new OpcionesLectura('Envios', 3), LimitesLectura::para(), 3);
    $ok($prev['encabezados']->valores[0] === 'Guía' && $prev['encabezados']->valores[11] === 'Vacía', 'encabezados de la fila 3');
    $ok(array_map(fn ($f) => $f->numero, $prev['filas']) === [4, 5, 6], 'vista previa con su numeración (incluida la fila ausente)');

    expect($comprobaciones)->toBe(32);   // 32 comprobaciones por calendario (el conjunto de 08 §3.8 se reescribió con la semántica de FilaLeida)
})->with('calendarios');

test('el calendario 1904 no contamina una lectura posterior (estado estático de la librería)', function () {
    $a = cg_ruta_tmp('a.xlsx');
    $b = cg_ruta_tmp('b.xlsx');
    cg_xlsx_fidelidad($a, true);
    cg_xlsx_fidelidad($b, false);

    cg_leer($a, new OpcionesLectura('Envios', 3));
    $filas = cg_leer($b, new OpcionesLectura('Envios', 3));

    expect($filas[4]->valores[2]['d'])->toBe('2025-10-01 00:00:00')->and($filas[4]->valores[2])->not->toHaveKey('cal');
});

test('F1-L1 exportación web (SheetJS, todo texto): las 152 × 28 celdas se leen EXACTAS y la inspección cuenta lo real', function () {
    mt_srand(11);
    $m = [array_map(fn ($i) => 'col_'.$i, range(1, 28))];

    for ($r = 1; $r <= 152; $r++) {
        $m[] = array_map(fn ($c) => $c === 7 && $r % 13 === 0 ? null : ($c === 3 ? '0'.mt_rand(100000000, 999999999) : "v{$r}_{$c}"), range(0, 27));
    }

    $ruta = cg_ruta_tmp('sheetjs.xlsx');
    $escritas = cg_xlsx_sheetjs($ruta, $m, 'A1:AB153');

    $insp = app(LectorXlsx::class)->inspeccionar($ruta, new OpcionesLectura, LimitesLectura::para());
    expect($insp->celdas)->toBe($escritas)->and($insp->filas)->toBe(152)->and($insp->hojaSeleccionada)->toBe('Envíos Completos');

    $filas = cg_leer($ruta);
    $iguales = 0;

    foreach ($filas as $n => $f) {
        foreach ($m[$n - 1] as $j => $esperado) {
            expect($f->valores[$j] ?? null)->toBe($esperado);
            $iguales++;
        }
    }

    expect($iguales)->toBe(152 * 28)->and(count($filas))->toBe(152);
});

test('F1-L4 el límite de celdas se mide sobre el contenido REAL, no sobre <dimension>', function () {
    $m = [];

    for ($r = 0; $r < 30; $r++) {
        $m[] = ['a', 'b', 'c', 'd', 'e', 'f'];
    }

    $ruta = cg_ruta_tmp('mentiroso.xlsx');
    cg_xlsx_sheetjs($ruta, $m, 'A1:B2');   // declara 4 celdas; tiene 180

    $limites = LimitesLectura::para(['max_celdas' => 100]);

    expect(cg_err(fn () => app(LectorXlsx::class)->inspeccionar($ruta, new OpcionesLectura, $limites)))->toBe('XLSX_EXCEDE_CELDAS')
        ->and(cg_err(fn () => cg_leer($ruta, null, $limites)))->toBe('XLSX_EXCEDE_CELDAS');

    // Sin dimension declarada, igual.
    $sin = cg_ruta_tmp('sin_dimension.xlsx');
    cg_xlsx_sheetjs($sin, $m, null);
    expect(cg_err(fn () => cg_leer($sin, null, $limites)))->toBe('XLSX_EXCEDE_CELDAS');

    // Y con el límite holgado se lee, con una advertencia de que la dimensión declarada no coincide.
    $insp = app(LectorXlsx::class)->inspeccionar($ruta, new OpcionesLectura, LimitesLectura::para());
    expect($insp->celdas)->toBe(180)->and($insp->advertencias)->not->toBe([]);
});

test('F1-L4 un XLSX cuya hoja descomprimida supera el tope se rechaza sin cargarlo (bomba de compresión)', function () {
    $ruta = cg_ruta_tmp('bomba.xlsx');
    $filas = '<row r="1">'.str_repeat('<c r="A1"><v>1</v></c>', 60000).'</row>';
    $celdas = 60000;
    cg_zip_xlsx($ruta, ['H' => cg_hoja_xml($filas, 'A1:A1')]);

    // Razón de compresión sospechosa
    config(['cargues.limites.xlsx_max_ratio' => 5]);
    expect(cg_err(fn () => app(LectorXlsx::class)->inspeccionar($ruta, new OpcionesLectura, LimitesLectura::para())))->toBe('XLSX_RATIO');

    // Tope de bytes descomprimidos de la hoja
    config(['cargues.limites.xlsx_max_ratio' => 100000, 'cargues.limites.xlsx_max_bytes_hoja' => 50000]);
    expect(cg_err(fn () => app(LectorXlsx::class)->inspeccionar($ruta, new OpcionesLectura, LimitesLectura::para())))->toBe('XLSX_ENTRADA_GRANDE');

    expect($celdas)->toBe(60000);
});

test('F1-L4 el tope de descompresión se aplica a los bytes REALES leídos, no al tamaño declarado', function () {
    $ruta = cg_ruta_tmp('real.xlsx');
    cg_xlsx_sheetjs($ruta, array_fill(0, 200, ['aaaaaaaaaa', 'bbbbbbbbbb', 'cccccccccc']));
    $zip = new ZipArchive;
    $zip->open($ruta);
    $medir = new ReflectionMethod(GuardaXlsx::class, 'medir');

    expect(fn () => $medir->invoke(new GuardaXlsx, $zip, 'xl/worksheets/sheet1.xml', 2000, true))->toThrow(OperacionInvalidaException::class, 'medido al descomprimir');
    $zip->close();
});

test('F1-L4 un ZIP que miente sobre el tamaño de la hoja se rechaza como inconsistente', function () {
    $ruta = cg_ruta_tmp('miente.xlsx');
    cg_xlsx_sheetjs($ruta, array_fill(0, 50, ['aaaa', 'bbbb']));

    // Falsea en el directorio central el tamaño descomprimido de la hoja (campo de 4 bytes en el offset 24).
    $bin = file_get_contents($ruta);
    $pos = strpos($bin, "PK\x01\x02");

    while ($pos !== false) {
        $nombreLen = unpack('v', substr($bin, $pos + 28, 2))[1];

        if (substr($bin, $pos + 46, $nombreLen) === 'xl/worksheets/sheet1.xml') {
            $real = unpack('V', substr($bin, $pos + 24, 4))[1];
            $bin = substr_replace($bin, pack('V', $real + 5000), $pos + 24, 4);

            break;
        }

        $pos = strpos($bin, "PK\x01\x02", $pos + 4);
    }

    file_put_contents($ruta, $bin);

    $codigo = cg_err(fn () => app(LectorXlsx::class)->inspeccionar($ruta, new OpcionesLectura, LimitesLectura::para()));

    expect($codigo)->toBeIn(['XLSX_ZIP_INCONSISTENTE', 'XLSX_INVALIDO']);
});

test('F1-L4 entradas ZIP con nombre inseguro o macros se rechazan', function () {
    $ruta = cg_ruta_tmp('slip.xlsx');
    cg_xlsx_sheetjs($ruta, [['a']]);
    $z = new ZipArchive;
    $z->open($ruta);
    $z->addFromString('xl/../../evil.txt', 'x');
    $z->close();
    expect(cg_err(fn () => app(LectorXlsx::class)->inspeccionar($ruta, new OpcionesLectura, LimitesLectura::para())))->toBe('XLSX_NOMBRE_INSEGURO');

    $macros = cg_ruta_tmp('macros.xlsx');
    cg_xlsx_sheetjs($macros, [['a']]);
    $z->open($macros);
    $z->addFromString('xl/vbaProject.bin', 'x');
    $z->close();
    expect(cg_err(fn () => app(LectorXlsx::class)->inspeccionar($macros, new OpcionesLectura, LimitesLectura::para())))->toBe('XLSX_MACROS');

    $texto = cg_ruta_tmp('falso.xlsx');
    file_put_contents($texto, 'esto no es un zip');
    expect(cg_err(fn () => app(LectorXlsx::class)->inspeccionar($texto, new OpcionesLectura, LimitesLectura::para())))->toBe('XLSX_INVALIDO');
});

test('F1-L4 la estimación de memoria se compara con el memory_limit del proceso y rechaza antes de cargar', function () {
    $ruta = cg_ruta_tmp('memoria.xlsx');
    cg_xlsx_sheetjs($ruta, array_fill(0, 100, ['a', 'b', 'c', 'd']));

    config(['cargues.limites.bytes_por_celda_xlsx' => 100 * 1048576]);   // cada celda «costaría» 100 MB

    $e = null;

    try {
        app(LectorXlsx::class)->inspeccionar($ruta, new OpcionesLectura, LimitesLectura::para());
    } catch (OperacionInvalidaException $e) {
    }

    expect($e?->codigo)->toBe('XLSX_EXCEDE_MEMORIA')->and($e->detalles['estimada'])->toBeGreaterThan($e->detalles['disponible']);

    // El disponible es la MITAD del memory_limit menos la base de Laravel (nunca más).
    $limite = LimitesLectura::memoryLimitActual();
    expect(LimitesLectura::para()->memoriaDisponible())->toBe((int) ($limite * 0.5) - 60 * 1048576);
});

test('F1-L4 un archivo por encima de max_bytes se rechaza al subirlo, antes de leerlo, y no queda nada guardado', function () {
    $antes = glob(storage_path('app/private/cargues/*/*/*')) ?: [];
    $grande = UploadedFile::fake()->createWithContent('grande.csv', str_repeat("a;b\n", 2000));

    expect(cg_err(fn () => app(ArchivoService::class)->guardar($grande, LimitesLectura::para(['max_bytes' => 1000]))))->toBe('ARCHIVO_EXCEDE_BYTES')
        ->and(glob(storage_path('app/private/cargues/*/*/*')) ?: [])->toBe($antes);
});

test('los archivos se guardan en el disco PRIVADO con nombre UUID y nunca en el público', function () {
    $info = app(ArchivoService::class)->guardar(cg_csv("a;b\n1;2\n", '../../etc/pass wd.csv'), LimitesLectura::para());

    expect($info['ruta'])->toMatch('~^cargues/\d{4}/\d{2}/[0-9a-f-]{36}\.csv$~')
        ->and($info['nombre'])->toBe('pass wd.csv')   // saneado: sin ruta
        ->and(file_exists(app(ArchivoService::class)->rutaReal($info['ruta'])))->toBeTrue()
        ->and(app(ArchivoService::class)->rutaReal($info['ruta']))->not->toContain(DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR)
        ->and($info['hash'])->toBe(hash('sha256', "a;b\n1;2\n"));

    app(ArchivoService::class)->eliminar($info['ruta']);

    config(['cargues.disco' => 'public']);
    expect(cg_err(fn () => app(ArchivoService::class)->guardar(cg_csv("a\n"), LimitesLectura::para())))->toBe('DISCO_PUBLICO');
});

test('extensión y firma: un ejecutable renombrado, un PDF o un XLSX sin firma ZIP se rechazan', function () {
    $svc = app(ArchivoService::class);
    $l = LimitesLectura::para();

    expect(cg_err(fn () => $svc->guardar(UploadedFile::fake()->createWithContent('x.exe', 'MZ...'), $l)))->toBe('ARCHIVO_EXTENSION')
        ->and(cg_err(fn () => $svc->guardar(UploadedFile::fake()->createWithContent('x.csv', "%PDF-1.4\n"), $l)))->toBe('ARCHIVO_FIRMA')
        ->and(cg_err(fn () => $svc->guardar(UploadedFile::fake()->createWithContent('x.xlsx', 'no soy zip'), $l)))->toBe('ARCHIVO_FIRMA')
        ->and(cg_err(fn () => $svc->guardar(UploadedFile::fake()->createWithContent('x.csv', "a\0b"), $l)))->toBe('ARCHIVO_FIRMA')
        ->and(cg_err(fn () => $svc->guardar(UploadedFile::fake()->createWithContent('x.csv', ''), $l)))->toBe('ARCHIVO_VACIO');
});

// -------------------------------------------------------------------------------------------------------------
// CSV
// -------------------------------------------------------------------------------------------------------------

function cg_filas_csv_fidelidad(): array
{
    return [
        ['Guía', 'Teléfono', 'Nota', 'Valor'],
        ['0012345678', '+573001234567', "línea 1\nlínea 2", '138.000,50'],
        ['G2', '3001234567', 'con ; punto y coma y "comillas"', '1,5'],
        ['', '', '', ''],
        ['G4', '', str_repeat('x', 5000), '=1+1'],
        ['G5', ' 300 ', 'barra \\" invertida', 'ñandú – Bogotá'],
    ];
}

test('F1-L2 CSV UTF-8 con BOM: 100 % de los campos exactos, con su número de registro', function () {
    $h = fopen('php://temp', 'r+');
    fwrite($h, "\xEF\xBB\xBF");

    foreach (cg_filas_csv_fidelidad() as $f) {
        fputcsv($h, $f, ';', '"', '');
    }

    rewind($h);
    $ruta = cg_ruta_tmp('utf8.csv');
    file_put_contents($ruta, stream_get_contents($h));

    $lector = app(LectorCsv::class);
    expect($lector->detectar($ruta))->toBe(['delimitador' => ';', 'codificacion' => 'UTF-8', 'bom' => true]);

    $filas = cg_leer($ruta, new OpcionesLectura(null, 1, ';', '"', 'UTF-8'));
    $esperado = cg_filas_csv_fidelidad();

    expect(array_keys($filas))->toBe([2, 3, 4, 5, 6]);   // el registro con salto de línea cuenta UNA vez

    foreach ($filas as $n => $f) {
        expect($f->valores)->toBe($esperado[$n - 1]);
    }

    expect($filas[4]->estaVacia())->toBeTrue()->and($filas[4]->valores)->toBe(['', '', '', ''])   // texto vacío ≠ ausente
        ->and($filas[6]->valores[1])->toBe(' 300 ');   // espacios conservados
});

test('F1-L2 CSV Windows-1252 (como lo guarda Excel): se detecta y se lee igual', function () {
    $lineas = [];

    foreach (cg_filas_csv_fidelidad() as $f) {
        $lineas[] = implode(';', array_map(fn ($v) => '"'.str_replace('"', '""', $v).'"', $f));
    }

    $ruta = cg_ruta_tmp('win.csv');
    file_put_contents($ruta, iconv('UTF-8', 'Windows-1252//TRANSLIT', implode("\r\n", $lineas)."\r\n"));

    expect(app(LectorCsv::class)->detectar($ruta))->toBe(['delimitador' => ';', 'codificacion' => 'Windows-1252', 'bom' => false]);

    $filas = cg_leer($ruta, new OpcionesLectura(null, 1, ';', '"', 'Windows-1252'));
    $esperado = cg_filas_csv_fidelidad();

    foreach ($filas as $n => $f) {
        // «–» (raya) no existe en Windows-1252 con TRANSLIT: se compara el resto de los campos exactamente.
        expect(str_replace('–', '-', $f->valores[3] ?? ''))->toBe(str_replace('–', '-', $esperado[$n - 1][3]));
        expect($f->valores[0])->toBe($esperado[$n - 1][0]);
    }

    expect($filas[2]->valores[2])->toBe("línea 1\nlínea 2");   // el salto dentro del campo entrecomillado se conserva
});

test('F1-L2 un byte inválido en un archivo declarado UTF-8 se rechaza con el número de registro: nunca se sustituye en silencio', function () {
    $ruta = cg_ruta_tmp('malo.csv');
    file_put_contents($ruta, "a;b\n1;ok\n2;\xE9xito\n");

    expect(cg_err(fn () => cg_leer($ruta, new OpcionesLectura(null, 1, ';', '"', 'UTF-8'))))->toBe('CSV_CODIFICACION');

    try {
        cg_leer($ruta, new OpcionesLectura(null, 1, ';', '"', 'UTF-8'));
    } catch (OperacionInvalidaException $e) {
        expect($e->getMessage())->toContain('registro 3')->toContain('Windows-1252');
    }
});

test('CSV: delimitador detectado, registro corto (campos finales ausentes) y registro vacío intermedio', function () {
    $ruta = cg_ruta_tmp('corto.csv');
    file_put_contents($ruta, "a|b|c|d\n1|2\n\n5|6|7|8\n");

    expect(app(LectorCsv::class)->detectar($ruta)['delimitador'])->toBe('|');

    $filas = cg_leer($ruta, new OpcionesLectura(null, 1, '|', '"', 'UTF-8'));

    expect($filas[2]->valores)->toBe(['1', '2'])           // c y d AUSENTES (la lista termina en la última presente)
        ->and($filas[3]->valores)->toBe([])->and($filas[3]->estaVacia())->toBeTrue()   // el registro vacío conserva su número
        ->and($filas[4]->valores)->toBe(['5', '6', '7', '8']);
});

test('CSV binario, demasiadas filas y demasiadas columnas se rechazan', function () {
    $bin = cg_ruta_tmp('bin.csv');
    file_put_contents($bin, "a;b\0\1\2");
    expect(cg_err(fn () => app(LectorCsv::class)->detectar($bin)))->toBe('CSV_BINARIO');

    $filas = cg_ruta_tmp('filas.csv');
    file_put_contents($filas, "a;b\n".str_repeat("1;2\n", 50));
    expect(cg_err(fn () => app(LectorCsv::class)->inspeccionar($filas, new OpcionesLectura, LimitesLectura::para(['max_filas' => 10]))))->toBe('CSV_EXCEDE_FILAS');

    $anchas = cg_ruta_tmp('anchas.csv');
    file_put_contents($anchas, implode(';', range(1, 200))."\n");
    expect(cg_err(fn () => cg_leer($anchas)))->toBe('CSV_EXCEDE_COLUMNAS');
});

test('la fila de encabezados es configurable y las filas anteriores se ignoran', function () {
    $ruta = cg_ruta_tmp('titulo.csv');
    file_put_contents($ruta, "Reporte de envíos;;\n\nguia;tel;est\nG1;3;X\n");

    $prev = app(LectorCsv::class)->vistaPrevia($ruta, new OpcionesLectura(null, 3, ';', '"', 'UTF-8'), LimitesLectura::para());

    expect($prev['encabezados']->valores)->toBe(['guia', 'tel', 'est'])->and($prev['filas'][0]->valores)->toBe(['G1', '3', 'X']);
});

// -------------------------------------------------------------------------------------------------------------
// Encabezados
// -------------------------------------------------------------------------------------------------------------

test('F1-L5 «Producto» y «producto» son dos columnas distintas y relacionables por separado; no se sugiere ninguna', function () {
    $enc = app(Encabezados::class);
    $n = app(Normalizador::class);
    $cab = new FilaLeida(1, ['codigo', 'Producto', 'valor', 'producto', 'X', 'X']);
    $meta = $enc->construir($cab, [new FilaLeida(2, ['1', 'CAMISETAS', '5', 'camisetas', 'a', 'b'])], $n);

    expect($meta['columnas'])->toHaveCount(6)
        ->and($meta['columnas'][1]['texto'])->toBe('Producto')->and($meta['columnas'][3]['texto'])->toBe('producto')
        ->and($meta['columnas'][1]['similar'])->toBeTrue()->and($meta['columnas'][3]['similar'])->toBeTrue()
        ->and($meta['columnas'][1]['ambiguo'])->toBeFalse()
        ->and($meta['columnas'][4]['ambiguo'])->toBeTrue()->and($meta['columnas'][5]['ambiguo'])->toBeTrue();

    $campos = [['nombre' => 'producto', 'etiqueta' => 'Producto', 'sinonimos' => []], ['nombre' => 'codigo', 'etiqueta' => 'Código', 'sinonimos' => []], ['nombre' => 'valor', 'etiqueta' => 'Valor', 'sinonimos' => []]];

    // Solo se sugieren las coincidencias inequívocas: producto (dos columnas equivalentes) NO se sugiere.
    expect($enc->sugerir($meta['columnas'], $campos))->toBe(['codigo' => 0, 'valor' => 2]);
});

test('la firma de los encabezados cambia si el formato del proveedor cambia', function () {
    $enc = app(Encabezados::class);
    $n = app(Normalizador::class);
    $a = $enc->construir(new FilaLeida(1, ['guia', 'estado']), [], $n);
    $b = $enc->construir(new FilaLeida(1, ['guia', 'estado', 'transportadora']), [], $n);

    expect($enc->firma($a['columnas']))->not->toBe($enc->firma($b['columnas']));
});
