<?php

namespace ByLopez\Cargues\Services\Lectura;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/** Filtro de filas para la vista previa: PhpSpreadsheet solo construye las celdas de las primeras filas. */
final class FiltroFilas implements IReadFilter
{
    public function __construct(private int $maxFila) {}

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        return $row <= $this->maxFila;
    }
}
