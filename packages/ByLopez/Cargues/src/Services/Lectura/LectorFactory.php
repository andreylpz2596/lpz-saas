<?php

namespace ByLopez\Cargues\Services\Lectura;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;

/** Elige el lector por formato. Cambiar la implementacion de un formato no toca el resto del motor. */
class LectorFactory
{
    public function __construct(protected LectorXlsx $xlsx, protected LectorCsv $csv) {}

    public function para(string $formato): LectorArchivo
    {
        return match ($formato) {
            'xlsx' => $this->xlsx,
            'csv', 'txt' => $this->csv,
            default => throw new OperacionInvalidaException('FORMATO_NO_SOPORTADO', 'Formatos admitidos: XLSX, CSV y TXT.'),
        };
    }
}
