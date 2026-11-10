<?php

namespace ByLopez\CorteConfeccion\Comprobantes;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Lectura LOCAL (Tesseract OCR, Apache 2.0): el comprobante no sale del servidor; sin costo por lectura ni credenciales.
 *
 *  - Imagen: OCR directo. Si la primera pasada deja campos clave vacios se intentan otras (segmentacion distinta y imagen
 *    ampliada); solo se completan los campos vacios, nunca se reemplaza lo ya leido.
 *  - PDF: primero se extrae su texto (pdftotext); si no tiene texto (escaneado) se convierten las paginas a imagen
 *    (pdftoppm) y se aplica OCR. Una operacion por pagina.
 *
 * Limites: tamano (ComprobantesService), paginas (`max_paginas`), tiempo por proceso (`timeout`) y tiempo total por archivo
 * (`tiempo_max`). El original no se toca: se lee una copia temporal.
 *
 * El texto lo interpreta ParserTextoComprobante; la confianza de cada palabra (TSV de Tesseract) sirve para marcar los
 * codigos que el OCR no ley con seguridad.
 */
class LectorTesseract implements ComprobanteLector
{
    /** Por debajo de esta confianza (0-100) un codigo se marca para verificar. */
    public const CONFIANZA_MINIMA = 85;

    public function __construct(protected array $config = [], protected ?ParserTextoComprobante $parser = null)
    {
        $this->parser ??= new ParserTextoComprobante;
    }

    public function nombre(): string
    {
        return 'tesseract:'.ParserTextoComprobante::VERSION;
    }

    public function disponible(): bool
    {
        return $this->binario() !== null;
    }

    public function leer(string $contenido, string $mime, string $nombre): array
    {
        if (! $this->disponible()) {
            throw new LecturaComprobanteException('Tesseract no esta instalado en el servidor.');
        }

        $limite = microtime(true) + (int) ($this->config['tiempo_max'] ?? 120);
        $temporal = tempnam(sys_get_temp_dir(), 'ccrc');

        try {
            file_put_contents($temporal, $contenido);

            return $mime === 'application/pdf' ? $this->leerPdf($temporal, $limite) : $this->leerImagen($temporal, $limite, null);
        } finally {
            @unlink($temporal);
        }
    }

    // ------------------------------------------------------------------ imagen

    /** @return list<BorradorOperacion> */
    protected function leerImagen(string $archivo, float $limite, ?int $pagina): array
    {
        $mejor = null;
        $palabras = [];

        foreach ([[4, 1.0], [6, 1.0], [4, 2.0]] as [$psm, $escala]) {
            if ($mejor !== null && $this->completo($mejor)) {
                break;
            }

            if ($mejor !== null && microtime(true) > $limite) {
                break;
            }

            $entrada = $escala > 1.0 ? $this->ampliar($archivo, $escala) : $archivo;

            try {
                [$texto, $pal] = $this->ocr($entrada, $psm);
            } finally {
                if ($entrada !== $archivo) {
                    @unlink($entrada);
                }
            }

            if (trim($texto) === '') {
                continue;
            }

            $borrador = $this->parser->parsear($texto);
            $palabras = array_merge($palabras, $pal);
            $mejor = $mejor === null ? $borrador : $this->completar($mejor, $borrador);
        }

        return $this->finalizar($mejor, $palabras, $pagina);
    }

    /**
     * @param  list<array{texto: string, conf: float}>  $palabras
     * @return list<BorradorOperacion>
     */
    protected function finalizar(?BorradorOperacion $b, array $palabras, ?int $pagina): array
    {
        // Sin nada reconocible (ni valor, ni fecha, ni codigo) no hay operacion que proponer.
        if ($b === null || ($b->valor === null && $b->fecha === null && $b->referenciaNormalizada() === null)) {
            return [];
        }

        $b->pagina = $pagina;
        $b->advertencias = $this->advertencias($b);

        foreach (['referencia' => 'la referencia', 'autorizacion' => 'la autorización'] as $campo => $nombre) {
            $conf = $this->confianza($b->{$campo}, $palabras);

            if ($conf !== null && $conf < self::CONFIANZA_MINIMA) {
                $b->advertencias[] = "verifica {$nombre}: el lector no la vio con seguridad (caracteres parecidos como 0/O, 1/I, 5/S, 8/B)";
            }
        }

        return [$b];
    }

    /** Recalcula las advertencias de campos vacios segun lo que quedo tras combinar pasadas. */
    protected function advertencias(BorradorOperacion $b): array
    {
        $adv = array_values(array_filter($b->advertencias, fn ($a) => ! in_array($a, ['valor no legible', 'fecha no legible', 'referencia ilegible', 'medio no identificado'], true)));

        $b->valor === null && $adv[] = 'valor no legible';
        $b->fecha === null && $adv[] = 'fecha no legible';
        $b->referenciaNormalizada() === null && $adv[] = 'referencia ilegible';
        $b->medio === null && $adv[] = 'medio no identificado';

        return $adv;
    }

    protected function completo(BorradorOperacion $b): bool
    {
        return $b->valor !== null && $b->fecha !== null && $b->referenciaNormalizada() !== null;
    }

    /** Completa SOLO los campos vacios de $base con lo que aporta $otro. */
    protected function completar(BorradorOperacion $base, BorradorOperacion $otro): BorradorOperacion
    {
        foreach (['valor', 'fecha', 'hora', 'bancoOrigen', 'bancoDestino', 'medio', 'referencia', 'autorizacion', 'contraparte', 'remitente'] as $campo) {
            $base->{$campo} ??= $otro->{$campo};
        }

        $base->advertencias = array_values(array_unique(array_merge($base->advertencias, $otro->advertencias)));

        return $base;
    }

    /**
     * Confianza minima de las palabras que forman el valor (null si no se puede asociar).
     *
     * @param  list<array{texto: string, conf: float}>  $palabras
     */
    protected function confianza(?string $valor, array $palabras): ?float
    {
        if ($valor === null) {
            return null;
        }

        $objetivo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $valor) ?? '');
        $min = null;

        foreach ($palabras as $p) {
            $t = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $p['texto']) ?? '');

            if (strlen($t) >= 3 && str_contains($objetivo, $t) && ($min === null || $p['conf'] < $min)) {
                $min = $p['conf'];
            }
        }

        return $min;
    }

    /**
     * @return array{0: string, 1: list<array{texto: string, conf: float}>} texto por lineas y palabras con su confianza
     */
    protected function ocr(string $archivo, int $psm): array
    {
        $tessdata = $this->tessdata();
        $idioma = $tessdata && is_file($tessdata.DIRECTORY_SEPARATOR.'spa.traineddata') ? 'spa' : 'eng';
        $comando = [$this->binario(), $archivo, 'stdout', '-l', $idioma, '--psm', (string) $psm];

        if ($tessdata) {
            array_push($comando, '--tessdata-dir', $tessdata);
        }

        // «tsv» trae la confianza por palabra; si la instalacion no tiene esa configuracion se usa texto plano.
        $salida = $this->ejecutar([...$comando, 'tsv'], false) ?? $this->ejecutar($comando);

        return $this->desdeTsv((string) $salida);
    }

    /** @return array{0: string, 1: list<array{texto: string, conf: float}>} */
    protected function desdeTsv(string $salida): array
    {
        $primera = strtok($salida, "\n");

        if (! is_string($primera) || ! str_starts_with($primera, 'level')) {
            return [$salida, []];
        }

        $lineas = [];
        $palabras = [];

        foreach (explode("\n", $salida) as $i => $fila) {
            $c = explode("\t", rtrim($fila, "\r"));

            if ($i === 0 || count($c) < 12 || (int) $c[0] !== 5 || trim($c[11]) === '') {
                continue;
            }

            $clave = $c[1].'.'.$c[2].'.'.$c[3].'.'.$c[4];
            $lineas[$clave][] = $c[11];
            $palabras[] = ['texto' => $c[11], 'conf' => (float) $c[10]];
        }

        return [implode("\n", array_map(fn ($p) => implode(' ', $p), $lineas)), $palabras];
    }

    /** Amplia la imagen (fotos de pantalla con letra pequena). Devuelve la ruta de un temporal PNG, o el mismo archivo si no se puede. */
    protected function ampliar(string $archivo, float $escala): string
    {
        $tam = function_exists('imagecreatefromstring') ? @getimagesize($archivo) : false;

        // Limite de memoria: no se amplian imagenes que quedarian por encima de ~12 megapixeles.
        if (! $tam || $tam[0] * $tam[1] * $escala * $escala > 12_000_000) {
            return $archivo;
        }

        $crudo = @file_get_contents($archivo);
        $img = $crudo === false ? false : @imagecreatefromstring($crudo);

        if (! $img) {
            return $archivo;
        }

        $grande = imagescale($img, (int) (imagesx($img) * $escala), (int) (imagesy($img) * $escala), IMG_BICUBIC);

        if (! $grande) {
            return $archivo;
        }

        imagefilter($grande, IMG_FILTER_GRAYSCALE);
        $destino = tempnam(sys_get_temp_dir(), 'ccra');
        imagepng($grande, $destino);

        return $destino;
    }

    // ------------------------------------------------------------------ PDF

    /** @return list<BorradorOperacion> */
    protected function leerPdf(string $archivo, float $limite): array
    {
        $pdftotext = $this->poppler('pdftotext');

        if ($pdftotext === null) {
            throw new LecturaComprobanteException('Para leer PDF se necesita poppler (pdftotext y pdftoppm) en el servidor; el archivo quedó guardado.');
        }

        $maxPaginas = max(1, (int) ($this->config['max_paginas'] ?? 10));

        // 1) Capa de texto: no hace falta OCR.
        $texto = (string) $this->ejecutar([$pdftotext, '-layout', '-f', '1', '-l', (string) $maxPaginas, $archivo, '-']);

        if (preg_match_all('/[A-Za-z0-9]/', $texto) >= 30) {
            $resultado = [];

            foreach (explode("\f", $texto) as $i => $pagina) {
                if (trim($pagina) === '') {
                    continue;
                }

                $b = $this->parser->parsear($pagina);
                $resultado = array_merge($resultado, $this->finalizar($b, [], $i + 1));
            }

            return $resultado;
        }

        // 2) Escaneado: paginas a imagen y OCR local.
        $pdftoppm = $this->poppler('pdftoppm');

        if ($pdftoppm === null) {
            throw new LecturaComprobanteException('El PDF no tiene texto y falta pdftoppm (poppler) para leerlo con OCR; el archivo quedó guardado.');
        }

        $base = tempnam(sys_get_temp_dir(), 'ccpd');
        @unlink($base);
        $this->ejecutar([$pdftoppm, '-r', '200', '-png', '-f', '1', '-l', (string) $maxPaginas, $archivo, $base]);
        $paginas = glob($base.'-*.png') ?: [];
        sort($paginas);
        $resultado = [];

        try {
            foreach ($paginas as $i => $png) {
                if ($i > 0 && microtime(true) > $limite) {
                    break;
                }

                $resultado = array_merge($resultado, $this->leerImagen($png, $limite, $i + 1));
            }
        } finally {
            foreach ($paginas as $png) {
                @unlink($png);
            }
        }

        return $resultado;
    }

    // ------------------------------------------------------------------ procesos y binarios

    /** @param  list<string>  $comando */
    protected function ejecutar(array $comando, bool $lanzar = true): ?string
    {
        try {
            $proceso = new Process($comando);
            $proceso->setTimeout((int) ($this->config['timeout'] ?? 60));
            $proceso->run();
        } catch (Throwable $e) {
            if ($lanzar) {
                throw new LecturaComprobanteException('No se pudo ejecutar el lector local.', 0, $e);
            }

            return null;
        }

        if (! $proceso->isSuccessful()) {
            if ($lanzar) {
                throw new LecturaComprobanteException('El lector local no pudo leer el archivo.');
            }

            return null;
        }

        return $proceso->getOutput();
    }

    public function binario(): ?string
    {
        return $this->buscar($this->config['tesseract'] ?? null, 'tesseract', [
            'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
            '/usr/bin/tesseract', '/usr/local/bin/tesseract', '/opt/homebrew/bin/tesseract',
        ]);
    }

    protected function poppler(string $herramienta): ?string
    {
        $dir = $this->config['poppler'] ?? null;
        $exe = PHP_OS_FAMILY === 'Windows' ? $herramienta.'.exe' : $herramienta;
        $extras = [];

        if ($dir) {
            $extras[] = rtrim($dir, '\\/').DIRECTORY_SEPARATOR.$exe;
        }

        if (PHP_OS_FAMILY === 'Windows' && ($local = getenv('LOCALAPPDATA'))) {
            $extras[] = $local.'\\Microsoft\\WinGet\\Links\\'.$exe;

            foreach (glob($local.'\\Microsoft\\WinGet\\Packages\\*Poppler*\\*\\Library\\bin\\'.$exe) ?: [] as $ruta) {
                $extras[] = $ruta;
            }
        }

        array_push($extras, '/usr/bin/'.$herramienta, '/usr/local/bin/'.$herramienta, '/opt/homebrew/bin/'.$herramienta);

        return $this->buscar(null, $herramienta, $extras);
    }

    /** Carpeta con spa.traineddata (los datos de idioma de la instalacion del sistema suelen traer solo ingles). */
    protected function tessdata(): ?string
    {
        $dir = $this->config['tessdata'] ?? null;

        return $dir && is_dir($dir) ? $dir : null;
    }

    /** @param  list<string>  $extras */
    protected function buscar(?string $configurado, string $nombre, array $extras = []): ?string
    {
        foreach (array_filter([$configurado, ...$extras]) as $ruta) {
            if (is_file($ruta)) {
                return $ruta;
            }
        }

        $buscador = PHP_OS_FAMILY === 'Windows' ? ['where', $nombre] : ['which', $nombre];

        try {
            $p = new Process($buscador);
            $p->run();

            if ($p->isSuccessful()) {
                $primera = trim(strtok($p->getOutput(), "\r\n") ?: '');

                return $primera !== '' && is_file($primera) ? $primera : null;
            }
        } catch (Throwable) {
        }

        return null;
    }
}
