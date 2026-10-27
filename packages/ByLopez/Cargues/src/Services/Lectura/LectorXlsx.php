<?php

namespace ByLopez\Cargues\Services\Lectura;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * XLSX con PhpSpreadsheet (R2 aprobado). `readDataOnly = false`: con `true` se pierden las fechas [V, 08 §3.8]. Antes
 * de cargar nada pasa por GuardaXlsx (limites sobre lo REAL). El calendario 1900/1904 es estado ESTATICO de la libreria:
 * se restablece siempre al terminar para no contaminar otras lecturas.
 */
class LectorXlsx implements LectorArchivo
{
    public function __construct(protected GuardaXlsx $guarda) {}

    public function inspeccionar(string $ruta, OpcionesLectura $o, LimitesLectura $l): InspeccionArchivo
    {
        $g = $this->guarda->inspeccionar($ruta, $o->hoja, $l);

        return new InspeccionArchivo(
            formato: 'xlsx',
            hojas: array_map(fn ($h) => ['nombre' => $h['nombre'], 'estado' => $h['estado']], $g['hojas']),
            hojaSeleccionada: $g['hoja']['nombre'],
            celdas: $g['celdas'],
            filas: max(0, $g['filas'] - $o->filaEncabezado),
            dimensionDeclarada: $g['dimension'],
            advertencias: $this->advertencias($g),
            memoriaEstimada: $g['memoria_estimada'],
        );
    }

    public function vistaPrevia(string $ruta, OpcionesLectura $o, LimitesLectura $l, int $n = 5): array
    {
        $g = $this->guarda->inspeccionar($ruta, $o->hoja, $l);
        $hasta = $o->filaEncabezado + $n;
        $filas = [];
        $encabezado = null;

        $this->conHoja($ruta, $g['hoja']['nombre'], new FiltroFilas($hasta), $l, function (Worksheet $ws) use ($o, $hasta, &$filas, &$encabezado, $l) {
            foreach ($this->recorrer($ws, $o->filaEncabezado, $hasta, $l) as $fila) {
                $fila->numero === $o->filaEncabezado ? $encabezado = $fila : $filas[] = $fila;
            }
        });

        return ['encabezados' => $encabezado, 'filas' => $filas];
    }

    public function filas(string $ruta, OpcionesLectura $o, LimitesLectura $l): iterable
    {
        $g = $this->guarda->inspeccionar($ruta, $o->hoja, $l);
        $filas = [];

        // Se materializa dentro del contexto del calendario; el limite de celdas ya garantiza que cabe en memoria.
        $this->conHoja($ruta, $g['hoja']['nombre'], null, $l, function (Worksheet $ws) use ($o, $l, &$filas) {
            foreach ($this->recorrer($ws, $o->filaEncabezado + 1, PHP_INT_MAX, $l) as $fila) {
                $filas[] = $fila;
            }
        });

        foreach ($filas as $i => $fila) {
            yield $fila;
            unset($filas[$i]);
        }
    }

    /** Carga SOLO la hoja pedida y garantiza el restablecimiento del calendario estatico. */
    protected function conHoja(string $ruta, string $hoja, ?IReadFilter $filtro, LimitesLectura $l, callable $accion): void
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(false);
        $reader->setLoadSheetsOnly([$hoja]);

        if ($filtro) {
            $reader->setReadFilter($filtro);
        }

        try {
            $libro = $reader->load($ruta);
            $accion($libro->getSheetByName($hoja) ?? $libro->getSheet(0));
            $libro->disconnectWorksheets();
            unset($libro);
        } catch (OperacionInvalidaException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new OperacionInvalidaException('XLSX_ILEGIBLE', 'No se pudo leer el XLSX: '.$e->getMessage());
        } finally {
            Date::setExcelCalendar(Date::CALENDAR_WINDOWS_1900);
        }
    }

    /**
     * Recorre las filas [desde, hasta] devolviendo TAMBIEN las vacias (con su numero real).
     *
     * @return iterable<FilaLeida>
     */
    protected function recorrer(Worksheet $ws, int $desde, int $hasta, LimitesLectura $l): iterable
    {
        $porFila = [];
        $maxFila = 0;

        foreach ($ws->getCoordinates() as $coord) {
            if (! preg_match('/^([A-Z]+)(\d+)$/', $coord, $m)) {
                continue;
            }

            $fila = (int) $m[2];

            if ($fila < $desde || $fila > $hasta) {
                continue;
            }

            $col = Coordinate::columnIndexFromString($m[1]) - 1;

            if ($col >= $l->maxColumnas) {
                throw new OperacionInvalidaException('XLSX_EXCEDE_COLUMNAS', "La fila {$fila} usa más de {$l->maxColumnas} columnas.");
            }

            $porFila[$fila][$col] = $coord;
            $maxFila = max($maxFila, $fila);
        }

        for ($fila = $desde; $fila <= min($hasta, $maxFila); $fila++) {
            $valores = [];

            if (isset($porFila[$fila])) {
                $valores = array_fill(0, max(array_keys($porFila[$fila])) + 1, null);

                foreach ($porFila[$fila] as $col => $coord) {
                    $valores[$col] = $this->valorOriginal($ws->getCell($coord), $l);
                }
            }

            yield new FilaLeida($fila, $valores);
        }
    }

    /** Valor ORIGINAL de una celda (ver FilaLeida): sin normalizar y sin perder el tipo. */
    protected function valorOriginal(Cell $celda, LimitesLectura $l): mixed
    {
        $v = $celda->getValue();

        $valor = match ($celda->getDataType()) {
            DataType::TYPE_NULL => null,
            DataType::TYPE_BOOL => (bool) $v,
            DataType::TYPE_ERROR => ['e' => (string) $v],
            DataType::TYPE_FORMULA => ['f' => (string) $v, 'c' => $this->cache($celda)],
            DataType::TYPE_NUMERIC => $this->numero($celda, $v),
            default => $v instanceof RichText ? $v->getPlainText() : (string) $v,
        };

        if (is_string($valor) && mb_strlen($valor) > $l->maxLongitudCelda) {
            throw new OperacionInvalidaException('CELDA_EXCEDE_LONGITUD', "La celda {$celda->getCoordinate()} supera {$l->maxLongitudCelda} caracteres.");
        }

        return $valor;
    }

    protected function numero(Cell $celda, mixed $v): mixed
    {
        if (! Date::isDateTime($celda)) {
            return $v;
        }

        $d = null;

        try {
            $d = Date::excelToDateTimeObject($v)->format('Y-m-d H:i:s');
        } catch (Throwable) {
        }

        $r = ['n' => $v, 'f' => $celda->getStyle()->getNumberFormat()->getFormatCode(), 'd' => $d];

        if (Date::getExcelCalendar() === Date::CALENDAR_MAC_1904) {
            $r['cal'] = 1904;
        }

        return $r;
    }

    protected function cache(Cell $celda): mixed
    {
        try {
            $c = $celda->getOldCalculatedValue();

            return is_scalar($c) || $c === null ? $c : (string) $c;
        } catch (Throwable) {
            return null;
        }
    }

    protected function advertencias(array $g): array
    {
        $a = [];

        if ($g['dimension'] !== null && preg_match('/^[A-Z]+\d+:[A-Z]+(\d+)$/', $g['dimension'], $m) && abs((int) $m[1] - $g['filas']) > 1) {
            $a[] = "El archivo declara la dimensión {$g['dimension']} pero la hoja contiene {$g['filas']} filas; se usa lo real.";
        }

        return $a;
    }
}
