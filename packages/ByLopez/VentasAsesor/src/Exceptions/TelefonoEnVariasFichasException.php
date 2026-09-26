<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * El mismo numero normalizado aparece en mas de un Customer (datos historicos o una
 * ficha editada fuera del modulo). Fail closed: no se elige titular.
 */
class TelefonoEnVariasFichasException extends RuntimeException
{
    /** @param  list<int>  $customerIds */
    public function __construct(public readonly array $customerIds)
    {
        parent::__construct('El telefono aparece en varios clientes; requiere revision.');
    }
}
