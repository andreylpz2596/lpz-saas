<?php

require_once __DIR__.'/../Support/QaCartera.php';

use ByLopez\CorteConfeccion\Comprobantes\ComprobanteLector;
use ByLopez\CorteConfeccion\Comprobantes\LectorNoDisponible;
use ByLopez\CorteConfeccion\Comprobantes\LectorTesseract;
use ByLopez\CorteConfeccion\Comprobantes\LecturaComprobanteException;
use ByLopez\CorteConfeccion\Comprobantes\ParserTextoComprobante;
use ByLopez\CorteConfeccion\Models\CarteraSoporte;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Lectura LOCAL de comprobantes (Tesseract + parser de texto). Los datos esperados salen de los comprobantes que se generan
| aqui (imagenes sinteticas); ningun valor esta escrito en el codigo de lectura.
|--------------------------------------------------------------------------
*/

function cc_texto_nequi(string $extra = ''): string
{
    return "Comprobante de pago\nEnvío Realizado\n¡Escanea este QR con Nequi para verificar tu envío al instante!\nPara\nMaria Prueba\n¿Cuánto?\n\$ 50.000,00\nNúmero Nequi\n300 000 0001\nFecha\n05 de enero de 2026 a las 09:15 a. m.\nReferencia\nM00000001\n".$extra;
}

test('el parser toma valor, medio, fecha, hora, referencia y destinatario del texto sin usar la fecha de hoy', function () {
    $b = (new ParserTextoComprobante)->parsear(cc_texto_nequi());

    expect($b->valor)->toBe('50000.00')
        ->and($b->medio)->toBe('Nequi')
        ->and($b->fecha)->toBe('2026-01-05')
        ->and($b->hora)->toBe('09:15')
        ->and($b->referencia)->toBe('M00000001')
        ->and($b->contraparte)->toBe('Maria Prueba')
        ->and($b->bancoOrigen)->toBeNull()
        ->and($b->advertencias)->toBe([]);
});

test('el telefono no se confunde con la referencia ni el saldo o la comision con el valor', function () {
    $texto = "Saldo disponible\n\$ 9.999.999,00\nComisión\n\$ 500,00\nValor\n\$ 120.000\nNúmero Nequi\n3000000001\nReferencia\n3000000001\nFecha\n02/11/2026 3:05 p. m.";
    $b = (new ParserTextoComprobante)->parsear($texto);

    expect($b->valor)->toBe('120000.00')
        ->and($b->referencia)->toBeNull()
        ->and($b->advertencias)->toContain('referencia ilegible')
        ->and($b->fecha)->toBe('2026-11-02')
        ->and($b->hora)->toBe('15:05');
});

test('lo ilegible queda vacio y marcado; no se inventa fecha ni valor', function () {
    $b = (new ParserTextoComprobante)->parsear("Comprobante\nReferencia\nxx\nBancolombia");

    expect($b->valor)->toBeNull()->and($b->fecha)->toBeNull()->and($b->referencia)->toBeNull()
        ->and($b->advertencias)->toContain('valor no legible', 'fecha no legible', 'referencia ilegible');
});

test('formato de otra entidad: etiquetas distintas y fecha numerica', function () {
    $b = (new ParserTextoComprobante)->parsear("Bancolombia\nTransferencia exitosa\nComprobante No. 0000123456\nValor transferido: \$ 1.250.000,50\nFecha: 15/10/2026 04:20 p. m.\nBeneficiario: Taller Andrea");

    expect($b->valor)->toBe('1250000.50')->and($b->medio)->toBe('Bancolombia')->and($b->referencia)->toBe('0000123456')
        ->and($b->fecha)->toBe('2026-10-15')->and($b->hora)->toBe('16:20')->and($b->contraparte)->toBe('Taller Andrea');
});

/** Dibuja un comprobante (imagen real, no texto) para leerlo con el OCR. */
function cc_imagen_comprobante(array $lineas, string $formato = 'png'): ?string
{
    $fuente = 'C:\\Windows\\Fonts\\arial.ttf';

    if (! function_exists('imagettftext') || ! is_file($fuente)) {
        return null;
    }

    $img = imagecreatetruecolor(720, 80 + count($lineas) * 70);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    $negro = imagecolorallocate($img, 20, 10, 40);

    foreach ($lineas as $i => $linea) {
        imagettftext($img, 30, 0, 40, 80 + $i * 70, $negro, $fuente, $linea);
    }

    ob_start();
    $formato === 'jpg' ? imagejpeg($img, null, 90) : imagepng($img);

    return (string) ob_get_clean();
}

function cc_tesseract_listo(): bool
{
    return (new LectorTesseract((array) config('corteconfeccion.comprobantes')))->disponible();
}

test('lectura real de una imagen con OCR local: los datos salen de la imagen', function () {
    $png = cc_imagen_comprobante(['Para', 'Maria Gomez', 'Cuanto?', '$ 88.500,00', 'Fecha', '12 de octubre de 2026 a las 09:41 a. m.', 'Referencia', 'M07721456', 'Nequi']);

    if ($png === null || ! cc_tesseract_listo()) {
        $this->markTestSkipped('Tesseract o la fuente de pruebas no estan disponibles en este equipo.');
    }

    $lector = app(ComprobanteLector::class);
    expect($lector)->toBeInstanceOf(LectorTesseract::class);

    $b = $lector->leer($png, 'image/png', 'a.png')[0];

    expect($b->valor)->toBe('88500.00')->and($b->fecha)->toBe('2026-10-12')->and($b->hora)->toBe('09:41')
        ->and($b->referencia)->toBe('M07721456')->and($b->medio)->toBe('Nequi');
});

test('JPG y PNG del mismo comprobante dan la misma huella: duplicado aunque el archivo sea distinto', function () {
    $lineas = ['Para', 'Maria Gomez', 'Cuanto?', '$ 88.500,00', 'Fecha', '12 de octubre de 2026 a las 09:41 a. m.', 'Referencia', 'M07721456', 'Nequi'];
    $png = cc_imagen_comprobante($lineas);
    $jpg = cc_imagen_comprobante($lineas, 'jpg');

    if ($png === null || ! cc_tesseract_listo()) {
        $this->markTestSkipped('Tesseract o la fuente de pruebas no estan disponibles en este equipo.');
    }

    $lector = app(ComprobanteLector::class);

    expect($lector->leer($png, 'image/png', 'a.png')[0]->huella())->not->toBeNull()
        ->toBe($lector->leer($jpg, 'image/jpeg', 'a.jpg')[0]->huella());
});

test('un PDF sin pdftotext no se lee, pero el archivo se conserva y se completa a mano', function () {
    config(['corteconfeccion.comprobantes.pdftotext' => null]);
    $lector = new LectorTesseract(['tesseract' => null, 'pdftotext' => 'C:\\no\\existe\\pdftotext.exe']);

    if (! $lector->disponible()) {
        $this->markTestSkipped('Tesseract no esta instalado.');
    }

    // Si pdftotext no existe en el sistema la lectura falla de forma controlada.
    $hayPdftotext = (new Process(PHP_OS_FAMILY === 'Windows' ? ['where', 'pdftotext'] : ['which', 'pdftotext']));
    $hayPdftotext->run();

    if ($hayPdftotext->isSuccessful()) {
        $this->markTestSkipped('Este equipo si tiene pdftotext.');
    }

    expect(fn () => $lector->leer("%PDF-1.4\n%%EOF", 'application/pdf', 'x.pdf'))->toThrow(LecturaComprobanteException::class);
});

test('con el lector configurado en «ninguno» la lectura responde no disponible y el registro manual sigue', function () {
    config(['corteconfeccion.comprobantes.lector' => 'ninguno']);

    expect(app(ComprobanteLector::class))->toBeInstanceOf(LectorNoDisponible::class);
});

test('reintentar la lectura de un soporte ya guardado funciona sin volver a subirlo y no admite soportes de otra cuenta', function () {
    if (! cc_tesseract_listo()) {
        $this->markTestSkipped('Tesseract no esta instalado.');
    }

    Storage::fake('local');
    $e = cc_escenario();
    $e['admin'] = qa_admin('Contabilidad Lectura', 'custom', ['corte_confeccion', 'corte_confeccion.cartera', 'corte_confeccion.cartera.registrar']);
    $e['otro'] = qa_taller(['nombre' => 'Taller Ajeno '.uniqid()]);
    $png = cc_imagen_comprobante(['Cuanto?', '$ 50.000,00', 'Fecha', '03 de noviembre de 2026 a las 10:00 a. m.', 'Referencia', 'M0009911', 'Nequi']);

    if ($png === null) {
        $this->markTestSkipped('Fuente de pruebas no disponible.');
    }

    $url = route('admin.corte_confeccion.cartera.comprobantes.leer', ['tipo' => 'taller', 'id' => $e['jairo']->id]);
    $cab = ['Accept' => 'application/json'];

    $primera = $this->actingAs($e['admin'], 'admin')->post($url, ['comprobantes' => [UploadedFile::fake()->createWithContent('c.png', $png)]], $cab)->assertOk();
    $sid = $primera->json('soportes.0.id');
    expect($primera->json('lector_disponible'))->toBeTrue()
        ->and($primera->json('borradores.0.referencia'))->toBe('M0009911')
        ->and($primera->json('soportes.0.url'))->toContain('/soportes/'.$sid);

    $this->actingAs($e['admin'], 'admin')->post($url, ['reintentar' => [$sid]], $cab)->assertOk()->assertJsonPath('borradores.0.valor', '50000.00');

    // Un soporte de OTRO prestador no se puede releer desde esta cuenta.
    $ajeno = CarteraSoporte::query()->create(['taller_id' => $e['otro']->id, 'ruta' => 'x', 'nombre_original' => 'x.png', 'mime' => 'image/png', 'tamano' => 1, 'sha256' => hash('sha256', 'ajeno')]);
    expect($this->actingAs($e['admin'], 'admin')->post($url, ['reintentar' => [$ajeno->id]], $cab)->status())->toBe(403);

    // Nada de esto contabiliza dinero.
    expect(DB::table('bylopez_cc_cartera_movimientos')->count())->toBe(0);
});
