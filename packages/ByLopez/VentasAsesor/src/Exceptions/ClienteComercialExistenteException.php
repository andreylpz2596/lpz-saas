<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * No se crea un duplicado: el telefono o el email ya identifican a un Customer. El
 * llamador decide (usar ese cliente, agregarle el telefono...). Nunca se fusiona.
 */
class ClienteComercialExistenteException extends RuntimeException
{
    public const POR_TELEFONO = 'telefono';

    public const POR_EMAIL = 'email';

    public function __construct(public readonly int $customerId, public readonly string $por)
    {
        parent::__construct('Ya existe un cliente con ese '.$por.'.');
    }
}
