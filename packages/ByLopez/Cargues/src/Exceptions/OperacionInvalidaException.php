<?php

namespace ByLopez\Cargues\Exceptions;

use RuntimeException;

/** Error de negocio del motor: lleva un codigo estable (no se traduce) y detalles opcionales para la UI. */
class OperacionInvalidaException extends RuntimeException
{
    public function __construct(public readonly string $codigo, string $mensaje, public readonly array $detalles = [])
    {
        parent::__construct($mensaje);
    }
}
