<?php

namespace ByLopez\Cargues\Services\Lectura;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use DOMDocument;
use ZipArchive;

/**
 * Revision de un XLSX ANTES de cargarlo con PhpSpreadsheet (que construye todas las celdas en memoria).
 *
 * No se confia en lo que el archivo DECLARA (`<dimension>`, tamanos del indice ZIP): se mide lo REAL.
 *  1. Estructura del ZIP: numero de entradas, nombres seguros, sin cifrar, sin macros, tamanos declarados y ratio.
 *  2. La hoja elegida y las cadenas compartidas se DESCOMPRIMEN en streaming con un tope sobre los bytes realmente
 *     leidos (si el ZIP miente sobre su tamano, se corta o se rechaza como inconsistente).
 *  3. Sobre esos bytes reales se cuentan las celdas (`<c`) y las filas (`<row`), sin construir ninguna.
 *  4. Se estima la memoria y se compara con el memory_limit del proceso.
 */
class GuardaXlsx
{
    protected const CHUNK = 1048576;

    /**
     * @return array{hojas: array<int, array{nombre:string, estado:string, archivo:string}>, hoja: array{nombre:string, archivo:string}, celdas:int, filas:int, bytes_hoja:int, bytes_cadenas:int, dimension:?string, memoria_estimada:int}
     */
    public function inspeccionar(string $ruta, ?string $hoja, LimitesLectura $l): array
    {
        $bytes = filesize($ruta);

        if ($bytes === false || $bytes > $l->maxBytes) {
            throw new OperacionInvalidaException('ARCHIVO_EXCEDE_BYTES', 'El archivo supera el máximo permitido de '.$this->mb($l->maxBytes).' MB.');
        }

        $zip = new ZipArchive;

        if ($zip->open($ruta, ZipArchive::RDONLY) !== true) {
            throw new OperacionInvalidaException('XLSX_INVALIDO', 'El archivo no es un XLSX válido (no se pudo abrir como ZIP).');
        }

        try {
            $entradas = $this->revisarZip($zip, $l);
            $hojas = $this->hojas($zip, $entradas);
            $elegida = $this->elegirHoja($hojas, $hoja);

            // Cadenas compartidas y hoja: lo REAL descomprimido.
            $bytesCadenas = isset($entradas['xl/sharedStrings.xml']) ? $this->medir($zip, 'xl/sharedStrings.xml', $l->xlsxMaxBytesHoja, false)['bytes'] : 0;
            $conteo = $this->medir($zip, $elegida['archivo'], $l->xlsxMaxBytesHoja, true);

            if ($conteo['celdas'] > $l->maxCeldas) {
                throw new OperacionInvalidaException('XLSX_EXCEDE_CELDAS', "La hoja contiene {$conteo['celdas']} celdas (máximo {$l->maxCeldas}). El límite se comprueba sobre el contenido real, no sobre lo que declara el archivo.", ['celdas' => $conteo['celdas']]);
            }

            if ($conteo['filas'] > $l->maxFilas + 1) {
                throw new OperacionInvalidaException('XLSX_EXCEDE_FILAS', "La hoja contiene {$conteo['filas']} filas (máximo {$l->maxFilas}).");
            }

            $estimada = $conteo['celdas'] * $l->bytesPorCelda + $bytesCadenas * 3;
            $disponible = $l->memoriaDisponible();

            if ($disponible !== null && $estimada > $disponible) {
                throw new OperacionInvalidaException('XLSX_EXCEDE_MEMORIA', 'La lectura necesitaría ~'.$this->mb($estimada).' MB y este proceso solo puede dedicar '.$this->mb($disponible).' MB (la mitad del memory_limit menos la base de Laravel). Se procesa en el worker de la cola «cargues»; si aun así no cabe, divida el archivo.', ['estimada' => $estimada, 'disponible' => $disponible]);
            }

            return [
                'hojas' => $hojas, 'hoja' => $elegida, 'celdas' => $conteo['celdas'], 'filas' => $conteo['filas'],
                'bytes_hoja' => $conteo['bytes'], 'bytes_cadenas' => $bytesCadenas, 'dimension' => $conteo['dimension'], 'memoria_estimada' => $estimada,
            ];
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, array{size:int, comp:int}> */
    protected function revisarZip(ZipArchive $zip, LimitesLectura $l): array
    {
        if ($zip->numFiles < 1 || $zip->numFiles > $l->xlsxMaxEntradas) {
            throw new OperacionInvalidaException('XLSX_ENTRADAS', 'El XLSX tiene un número de entradas ZIP fuera de lo permitido ('.$zip->numFiles.').');
        }

        $entradas = [];
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $s = $zip->statIndex($i);
            $nombre = (string) $s['name'];

            if ($nombre === '' || str_contains($nombre, '..') || str_starts_with($nombre, '/') || str_starts_with($nombre, '\\') || preg_match('/^[A-Za-z]:/', $nombre) || str_contains($nombre, "\0")) {
                throw new OperacionInvalidaException('XLSX_NOMBRE_INSEGURO', 'El XLSX contiene una entrada con nombre no permitido.');
            }

            if (stripos($nombre, 'vbaProject') !== false) {
                throw new OperacionInvalidaException('XLSX_MACROS', 'El archivo contiene macros; no se admite.');
            }

            if (($s['encryption_method'] ?? 0) !== 0) {
                throw new OperacionInvalidaException('XLSX_CIFRADO', 'El archivo está cifrado; no se admite.');
            }

            $size = (int) $s['size'];
            $comp = (int) $s['comp_size'];
            $total += $size;

            if ($size > $l->xlsxMaxBytesHoja) {
                throw new OperacionInvalidaException('XLSX_ENTRADA_GRANDE', "La entrada {$nombre} descomprimida pesa ".$this->mb($size).' MB (máximo '.$this->mb($l->xlsxMaxBytesHoja).').');
            }

            if ($comp > 0 && $size / $comp > $l->xlsxMaxRatio) {
                throw new OperacionInvalidaException('XLSX_RATIO', "La entrada {$nombre} tiene una razón de compresión sospechosa (> {$l->xlsxMaxRatio}:1).");
            }

            $entradas[$nombre] = ['size' => $size, 'comp' => $comp];
        }

        if ($total > $l->xlsxMaxBytesTotal) {
            throw new OperacionInvalidaException('XLSX_TOTAL_GRANDE', 'El contenido descomprimido total declarado supera '.$this->mb($l->xlsxMaxBytesTotal).' MB.');
        }

        foreach (['[Content_Types].xml', 'xl/workbook.xml'] as $req) {
            if (! isset($entradas[$req])) {
                throw new OperacionInvalidaException('XLSX_INVALIDO', "Al archivo le falta {$req}: no es un XLSX válido.");
            }
        }

        return $entradas;
    }

    /** @return array<int, array{nombre:string, estado:string, archivo:string}> */
    protected function hojas(ZipArchive $zip, array $entradas): array
    {
        $wb = $this->dom($zip->getFromName('xl/workbook.xml'));
        $rels = isset($entradas['xl/_rels/workbook.xml.rels']) ? $this->dom($zip->getFromName('xl/_rels/workbook.xml.rels')) : null;

        $destinos = [];

        foreach ($rels?->getElementsByTagName('Relationship') ?? [] as $r) {
            $destinos[$r->getAttribute('Id')] = ltrim(str_starts_with($r->getAttribute('Target'), '/') ? $r->getAttribute('Target') : 'xl/'.$r->getAttribute('Target'), '/');
        }

        $hojas = [];

        foreach ($wb->getElementsByTagName('sheet') as $s) {
            $rid = $s->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $archivo = $destinos[$rid] ?? null;

            if ($archivo && isset($entradas[$archivo])) {
                $hojas[] = ['nombre' => $s->getAttribute('name'), 'estado' => $s->getAttribute('state') ?: 'visible', 'archivo' => $archivo];
            }
        }

        if ($hojas === [] || count($hojas) > 50) {
            throw new OperacionInvalidaException('XLSX_SIN_HOJAS', 'No se encontraron hojas legibles (o hay más de 50).');
        }

        return $hojas;
    }

    protected function elegirHoja(array $hojas, ?string $hoja): array
    {
        if ($hoja === null || $hoja === '') {
            foreach ($hojas as $h) {
                if ($h['estado'] === 'visible') {
                    return $h;
                }
            }

            return $hojas[0];
        }

        foreach ($hojas as $h) {
            if ($h['nombre'] === $hoja) {
                return $h;
            }
        }

        throw new OperacionInvalidaException('XLSX_HOJA_INEXISTENTE', "La hoja «{$hoja}» no existe en el archivo.");
    }

    /**
     * Descomprime una entrada en streaming contando lo REAL. Con `$celdas` cuenta `<c` y `<row`.
     *
     * @return array{bytes:int, celdas:int, filas:int, dimension:?string}
     */
    protected function medir(ZipArchive $zip, string $nombre, int $tope, bool $celdas): array
    {
        $declarado = (int) $zip->statName($nombre)['size'];
        $h = $zip->getStream($nombre);

        if (! is_resource($h)) {
            throw new OperacionInvalidaException('XLSX_INVALIDO', "No se pudo leer {$nombre}.");
        }

        $bytes = 0;
        $c = 0;
        $f = 0;
        $dim = null;
        $cola = '';

        while (! feof($h)) {
            $trozo = fread($h, self::CHUNK);

            if ($trozo === false || $trozo === '') {
                break;
            }

            $bytes += strlen($trozo);

            if ($bytes > $tope) {
                fclose($h);

                throw new OperacionInvalidaException('XLSX_HOJA_EXCEDE_BYTES', 'La hoja descomprimida supera '.$this->mb($tope).' MB (medido al descomprimir, no el valor declarado).');
            }

            if ($celdas) {
                $texto = $cola.$trozo;
                $c += preg_match_all('/<c[\s>\/]/', $texto);
                $f += preg_match_all('/<row[\s>\/]/', $texto);

                if ($dim === null && preg_match('/<dimension\s+ref="([^"]*)"/', $texto, $m)) {
                    $dim = $m[1];
                }

                // Solapa el final del trozo para no perder una etiqueta partida por el limite. Se quita del conteo
                // lo ya contado en la cola para no duplicar.
                $cola = substr($texto, -8);
                $c -= preg_match_all('/<c[\s>\/]/', $cola);
                $f -= preg_match_all('/<row[\s>\/]/', $cola);
            }
        }

        fclose($h);

        if ($celdas) {
            // Lo que quedo en la cola del ultimo trozo se resto para recontarlo; al terminar ya no habra otro trozo.
            $c += preg_match_all('/<c[\s>\/]/', $cola);
            $f += preg_match_all('/<row[\s>\/]/', $cola);
        }

        if ($bytes !== $declarado) {
            throw new OperacionInvalidaException('XLSX_ZIP_INCONSISTENTE', "El tamaño real de {$nombre} ({$bytes}) no coincide con el declarado ({$declarado}): ZIP manipulado o dañado.");
        }

        return ['bytes' => $bytes, 'celdas' => $c, 'filas' => $f, 'dimension' => $dim];
    }

    protected function dom(string|false $xml): DOMDocument
    {
        if ($xml === false || strlen($xml) > 2 * 1048576) {
            throw new OperacionInvalidaException('XLSX_INVALIDO', 'Metadatos del XLSX ausentes o demasiado grandes.');
        }

        $d = new DOMDocument;

        if (! @$d->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new OperacionInvalidaException('XLSX_INVALIDO', 'Metadatos del XLSX mal formados.');
        }

        return $d;
    }

    protected function mb(int $bytes): string
    {
        return number_format($bytes / 1048576, 1);
    }
}
