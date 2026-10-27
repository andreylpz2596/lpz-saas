<?php

namespace ByLopez\Cargues\Services\Lectura;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;

/**
 * CSV/TXT con fgetcsv (longitud 0, `escape` vacio): memoria constante y fidelidad completa [V, 08 §3.9].
 *
 * Numeracion: la «fila» de un CSV es el NUMERO DE REGISTRO (un campo entrecomillado con saltos de linea cuenta como
 * una sola fila), no la linea fisica. La codificacion se DETECTA (UTF-8 con o sin BOM, o Windows-1252) y quien carga la
 * confirma; T6 guarda el texto ya convertido a UTF-8. Un byte invalido en un archivo declarado UTF-8 se rechaza: nunca
 * se sustituye en silencio. El CSV no distingue celda ausente de texto vacio dentro de un registro; solo los campos que
 * faltan al final de un registro corto quedan ausentes.
 */
class LectorCsv implements LectorArchivo
{
    public function inspeccionar(string $ruta, OpcionesLectura $o, LimitesLectura $l): InspeccionArchivo
    {
        $this->revisarTamano($ruta, $l);
        $sniff = $this->detectar($ruta);

        $registros = 0;
        $anchoMax = 0;

        foreach ($this->registros($ruta, $o, $l) as [, $campos]) {
            $registros++;
            $anchoMax = max($anchoMax, count($campos));

            if ($registros > $l->maxFilas + $o->filaEncabezado) {
                throw new OperacionInvalidaException('CSV_EXCEDE_FILAS', "El archivo supera {$l->maxFilas} filas.");
            }
        }

        $adv = [];

        if ($sniff['codificacion'] !== $o->codificacion) {
            $adv[] = "Se detectó {$sniff['codificacion']} pero se leerá como {$o->codificacion}.";
        }

        return new InspeccionArchivo(
            formato: 'csv', celdas: $registros * max(1, $anchoMax), filas: max(0, $registros - $o->filaEncabezado),
            delimitador: $sniff['delimitador'], codificacion: $sniff['codificacion'], advertencias: $adv,
        );
    }

    public function vistaPrevia(string $ruta, OpcionesLectura $o, LimitesLectura $l, int $n = 5): array
    {
        $this->revisarTamano($ruta, $l);
        $enc = null;
        $filas = [];

        foreach ($this->registros($ruta, $o, $l) as [$numero, $campos]) {
            if ($numero < $o->filaEncabezado) {
                continue;
            }

            if ($numero > $o->filaEncabezado + $n) {
                break;
            }

            $fila = new FilaLeida($numero, $campos);
            $numero === $o->filaEncabezado ? $enc = $fila : $filas[] = $fila;
        }

        return ['encabezados' => $enc, 'filas' => $filas];
    }

    public function filas(string $ruta, OpcionesLectura $o, LimitesLectura $l): iterable
    {
        $this->revisarTamano($ruta, $l);

        foreach ($this->registros($ruta, $o, $l) as [$numero, $campos]) {
            if ($numero > $o->filaEncabezado) {
                yield new FilaLeida($numero, $campos);
            }
        }
    }

    /**
     * Sugerencia de delimitador y codificacion para la pantalla de lectura.
     *
     * @return array{delimitador: string, codificacion: string, bom: bool}
     */
    public function detectar(string $ruta): array
    {
        $h = fopen($ruta, 'rb');
        $muestra = (string) fread($h, 65536);
        fclose($h);

        if (str_contains($muestra, "\0")) {
            throw new OperacionInvalidaException('CSV_BINARIO', 'El archivo parece binario (contiene bytes nulos), no un texto delimitado.');
        }

        $bom = str_starts_with($muestra, "\xEF\xBB\xBF");
        $cuerpo = $bom ? substr($muestra, 3) : $muestra;
        $linea = strtok($cuerpo, "\n") ?: '';

        $cuenta = [];

        foreach (OpcionesLectura::DELIMITADORES as $d) {
            $cuenta[$d] = substr_count($linea, $d);
        }

        arsort($cuenta);
        $delim = array_key_first($cuenta);
        $delim = $cuenta[$delim] === 0 ? ';' : $delim;

        // El corte de la muestra puede partir un caracter multibyte: se descartan hasta 3 bytes finales.
        $utf8 = false;

        for ($i = 0; $i <= 3 && ! $utf8; $i++) {
            $utf8 = mb_check_encoding($i ? substr($cuerpo, 0, -$i) : $cuerpo, 'UTF-8');
        }

        return ['delimitador' => $delim, 'codificacion' => $bom || $utf8 ? 'UTF-8' : 'Windows-1252', 'bom' => $bom];
    }

    /**
     * Registros del archivo: [numero, campos[]]. Un registro vacio da `[]`.
     *
     * @return iterable<array{0:int, 1:array}>
     */
    protected function registros(string $ruta, OpcionesLectura $o, LimitesLectura $l): iterable
    {
        if (strlen($o->delimitador) !== 1 || strlen($o->entrecomillado) !== 1) {
            throw new OperacionInvalidaException('CSV_FORMATO', 'El delimitador y el entrecomillado deben ser un carácter.');
        }

        if (! in_array($o->codificacion, OpcionesLectura::CODIFICACIONES, true)) {
            throw new OperacionInvalidaException('CSV_CODIFICACION', 'Codificación no permitida.');
        }

        $h = fopen($ruta, 'rb');
        $bom = fread($h, 3) === "\xEF\xBB\xBF";

        if (! $bom) {
            rewind($h);
        }

        $numero = 0;

        try {
            while (($campos = fgetcsv($h, 0, $o->delimitador, $o->entrecomillado, '')) !== false) {
                $numero++;

                if ($campos === [null]) {
                    yield [$numero, []];

                    continue;
                }

                if (count($campos) > $l->maxColumnas) {
                    throw new OperacionInvalidaException('CSV_EXCEDE_COLUMNAS', "El registro {$numero} tiene más de {$l->maxColumnas} columnas.");
                }

                foreach ($campos as $i => $valor) {
                    $campos[$i] = $this->aUtf8((string) $valor, $o->codificacion, $numero);

                    if (mb_strlen($campos[$i]) > $l->maxLongitudCelda) {
                        throw new OperacionInvalidaException('CELDA_EXCEDE_LONGITUD', "Un campo del registro {$numero} supera {$l->maxLongitudCelda} caracteres.");
                    }
                }

                yield [$numero, array_values($campos)];
            }
        } finally {
            fclose($h);
        }
    }

    protected function aUtf8(string $v, string $codificacion, int $numero): string
    {
        if ($v === '') {
            return '';
        }

        if ($codificacion === 'UTF-8') {
            if (! mb_check_encoding($v, 'UTF-8')) {
                throw new OperacionInvalidaException('CSV_CODIFICACION', "El registro {$numero} contiene bytes que no son UTF-8 válido. Elija la codificación Windows-1252 si el archivo viene de Excel.");
            }

            return $v;
        }

        return mb_convert_encoding($v, 'UTF-8', $codificacion);
    }

    protected function revisarTamano(string $ruta, LimitesLectura $l): void
    {
        $b = filesize($ruta);

        if ($b === false || $b > $l->maxBytes) {
            throw new OperacionInvalidaException('ARCHIVO_EXCEDE_BYTES', 'El archivo supera el máximo permitido de '.number_format($l->maxBytes / 1048576, 1).' MB.');
        }
    }
}
