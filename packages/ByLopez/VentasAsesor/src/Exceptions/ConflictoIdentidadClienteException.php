<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * El telefono identifica a un Customer y el email a otro. Fail closed: ni se crea ni
 * se elige ni se fusiona; lo resuelve una persona.
 */
class ConflictoIdentidadClienteException extends RuntimeException
{
    public function __construct(public readonly int $customerIdPorTelefono, public readonly int $customerIdPorEmail)
    {
        parent::__construct('El telefono y el email pertenecen a clientes distintos.');
    }
}
