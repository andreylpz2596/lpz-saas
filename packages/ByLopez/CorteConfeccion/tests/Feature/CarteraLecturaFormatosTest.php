<?php

require_once __DIR__.'/../Support/QaCartera.php';

use Barryvdh\DomPDF\Facade\Pdf;
use ByLopez\CorteConfeccion\Comprobantes\BorradorOperacion;
use ByLopez\CorteConfeccion\Comprobantes\ComprobanteLector;
use ByLopez\CorteConfeccion\Comprobantes\ComprobantesService;
use ByLopez\CorteConfeccion\Comprobantes\LectorTesseract;
use ByLopez\CorteConfeccion\Comprobantes\ParserTextoComprobante;
use ByLopez\CorteConfeccion\Services\CarteraFuenteOperaciones;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Formatos de otras entidades, PDF y reutilizacion de lecturas. Todos los datos son inventados; los comprobantes reales se
| evaluan con tests/Demo/evaluar_comprobantes.php (OCR real) y no se guardan en el repositorio.
|--------------------------------------------------------------------------
*/

function cc_fmt_imagen(array $lineas): ?string
{
    $fuente = 'C:\\Windows\\Fonts\\arial.ttf';

    if (! function_exists('imagettftext') || ! is_file($fuente)) {
        return null;
    }

    $img = imagecreatetruecolor(720, 80 + count($lineas) * 70);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));

    foreach ($lineas as $i => $linea) {
        imagettftext($img, 30, 0, 40, 80 + $i * 70, imagecolorallocate($img, 20, 10, 40), $fuente, $linea);
    }

    ob_start();
    imagepng($img);

    return (string) ob_get_clean();
}

function cc_fmt_listo(): bool
{
    return (new LectorTesseract((array) config('corteconfeccion.comprobantes')))->disponible();
}

function cc_fmt_poppler(): bool
{
    $p = new Process(PHP_OS_FAMILY === 'Windows' ? ['where', 'pdftotext'] : ['which', 'pdftotext']);
    $p->run();

    return $p->isSuccessful() || is_file((getenv('LOCALAPPDATA') ?: '').'\\Microsoft\\WinGet\\Links\\pdftotext.exe') || is_file('/usr/bin/pdftotext');
}

function cc_fmt_pdf(string $html): string
{
    return Pdf::loadHTML($html)->output();
}

test('ID de la transaccion y codigo de autorizacion se conservan por separado; el importe repetido se cuenta una vez', function () {
    $b = (new ParserTextoComprobante)->parsear("Punto de pago\nTRANSACCIÓN EXITOSA\n2026-10-30\nHora 10:10:40\nID Transacción 1620000001\nValor \$250,000\nN°. Cuenta 3100000000\nCód. Autorización M06000001\nCosto de esta operación \$0\nNequi no cobra por esta transacción");

    expect($b->valor)->toBe('250000.00')
        ->and($b->referencia)->toBe('1620000001')
        ->and($b->autorizacion)->toBe('M06000001')
        ->and($b->fecha)->toBe('2026-10-30')
        ->and($b->hora)->toBe('10:10:40')
        ->and($b->medio)->toBe('Nequi');
});

test('un comprobante con solo codigo de aprobacion lo guarda como autorizacion y sirve de identificador de la operacion', function () {
    $b = (new ParserTextoComprobante)->parsear("Transacción exitosa\nPasaste Plata a otro DaviPlata\nA Maria Prueba Gomez\nValor\n\$200.000\nDesde\nDaviPlata - 300 000 0000\nFecha y hora de la transacción\nEnero 6 de 2026 - 11:30 a.m.\nNúmero de aprobación\n700001\nCosto de la transacción\n\$0");

    expect($b->valor)->toBe('200000.00')->and($b->fecha)->toBe('2026-01-06')->and($b->hora)->toBe('11:30')
        ->and($b->referencia)->toBeNull()->and($b->autorizacion)->toBe('700001')->and($b->referenciaNormalizada())->toBe('700001')
        ->and($b->bancoOrigen)->toBe('DaviPlata')->and($b->contraparte)->toBe('Maria Prueba Gomez')->and($b->medio)->toBe('DaviPlata');
});

test('origen y destino distintos: importe sin etiqueta, una sola vez, y la entidad que recibe no es el medio', function () {
    $b = (new ParserTextoComprobante)->parsear("Transferencia enviada\n\$80.000,00\n08/01/2026 - 09:00:03\nCosto de la transacción \$0\nSe debitará de la cuenta Bold \$80.000,00\nDescripción Transferencia por llaves\nID de transacción AB12CD34\nOrigen\nDueño Persona Origen Prueba\nBanco Bold\nNúmero de cuenta 170000000000\nDestino\nDueño PERSONA DESTINO PRUEBA\nEntidad Daviplata\nTipo de llave Número de celular\nLlave 3000000000");

    expect($b->valor)->toBe('80000.00')
        ->and($b->advertencias)->not->toContain('el comprobante muestra importes distintos: verifica cuál es el transferido')
        ->and($b->fecha)->toBe('2026-01-08')->and($b->hora)->toBe('09:00:03')
        ->and($b->bancoOrigen)->toBe('Bold')->and($b->bancoDestino)->toBe('Daviplata')->and($b->medio)->toBe('Bold')
        ->and($b->remitente)->toBe('Persona Origen Prueba')->and($b->contraparte)->toBe('PERSONA DESTINO PRUEBA')
        ->and($b->referencia)->toBe('AB12CD34');
});

test('los ceros iniciales de una referencia se conservan como texto', function () {
    $b = (new ParserTextoComprobante)->parsear("Comprobante No. 0000123456\nValor: \$ 10.000\nFecha: 01/11/2026");

    expect($b->referencia)->toBe('0000123456');
});

test('sin etiqueta de importe ni simbolo de moneda no se inventa un valor (telefonos, cuentas y saldos no son el valor)', function () {
    $b = (new ParserTextoComprobante)->parsear("Comprobante\nNúmero Nequi\n311 000 0000\nSaldo disponible \$ 9.999.999\nCuenta 1234567890\nFecha: 02/11/2026");

    expect($b->valor)->toBeNull()->and($b->advertencias)->toContain('valor no legible');
});

test('PDF con texto: se lee su texto sin OCR, una operacion por pagina y con el numero de pagina', function () {
    if (! cc_fmt_listo() || ! cc_fmt_poppler()) {
        $this->markTestSkipped('Falta Tesseract o poppler en este equipo.');
    }

    $pdf = cc_fmt_pdf('<p>Transaccion exitosa Nequi</p><p>Para</p><p>Persona Uno</p><p>Valor</p><p>$ 10.000,00</p><p>Fecha</p><p>05/11/2026</p><p>Referencia</p><p>M0000001</p><div style="page-break-after:always"></div><p>Transaccion exitosa Nequi</p><p>Valor</p><p>$ 20.000,00</p><p>Fecha</p><p>06/11/2026</p><p>Referencia</p><p>M0000002</p>');
    $r = app(ComprobanteLector::class)->leer($pdf, 'application/pdf', 'dos.pdf');

    expect($r)->toHaveCount(2)
        ->and([$r[0]->valor, $r[0]->referencia, $r[0]->pagina])->toBe(['10000.00', 'M0000001', 1])
        ->and([$r[1]->valor, $r[1]->referencia, $r[1]->pagina])->toBe(['20000.00', 'M0000002', 2]);
});

test('PDF escaneado (solo imagen): se convierte la pagina a imagen y se lee con OCR local', function () {
    $png = cc_fmt_imagen(['Para', 'Maria Gomez', 'Cuanto?', '$ 61.000,00', 'Fecha', '12 de octubre de 2026 a las 09:41 a. m.', 'Referencia', 'M07721456', 'Nequi']);

    if ($png === null || ! cc_fmt_listo() || ! cc_fmt_poppler()) {
        $this->markTestSkipped('Falta Tesseract, poppler o la fuente de pruebas.');
    }

    $pdf = cc_fmt_pdf('<img src="data:image/png;base64,'.base64_encode($png).'" style="width:100%">');
    $r = app(ComprobanteLector::class)->leer($pdf, 'application/pdf', 'escaneado.pdf');

    // El OCR puede confundir 0/O en el codigo (limitacion documentada): se comprueba que lo leyo y que lo marco para verificar.
    expect($r)->toHaveCount(1)->and([$r[0]->valor, $r[0]->fecha, $r[0]->pagina])->toBe(['61000.00', '2026-10-12', 1])
        ->and($r[0]->referencia)->toContain('7721456');
});

test('una lectura utilizable del mismo archivo se reutiliza sin repetir el OCR; la relectura es explicita', function () {
    Storage::fake('local');
    $e = cc_escenario();

    $contador = new class implements ComprobanteLector
    {
        public int $lecturas = 0;

        public function nombre(): string
        {
            return 'prueba:1';
        }

        public function disponible(): bool
        {
            return true;
        }

        public function leer(string $contenido, string $mime, string $nombre): array
        {
            $this->lecturas++;

            return [new BorradorOperacion(valor: '5000.00', fecha: '2026-11-03', referencia: 'LECT'.$this->lecturas, medio: 'Nequi')];
        }
    };

    app()->instance(ComprobanteLector::class, $contador);
    $servicio = app(ComprobantesService::class);
    $adj = $servicio->adjuntar(UploadedFile::fake()->createWithContent('a.png', base64_decode(CC_PNG)), $e['jairo'], new CarteraFuenteOperaciones);

    $uno = $servicio->leer($adj['soporte']);
    $dos = $servicio->leer($adj['soporte']->fresh());
    $tres = $servicio->leer($adj['soporte']->fresh(), true);

    expect($contador->lecturas)->toBe(2)
        ->and($uno['reutilizada'])->toBeFalse()->and($dos['reutilizada'])->toBeTrue()->and($tres['reutilizada'])->toBeFalse()
        ->and($dos['borradores'][0]->referencia)->toBe('LECT1')->and($tres['borradores'][0]->referencia)->toBe('LECT2');
});
