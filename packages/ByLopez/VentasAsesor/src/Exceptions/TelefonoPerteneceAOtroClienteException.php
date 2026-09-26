<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * El telefono normalizado ya esta indexado para otro Customer (D-RT04-PHONE-01).
 *
 * No se reasigna ni se crea otro cliente en silencio: se informa quien lo tiene y
 * el flujo comercial decide. El mensaje no incluye el numero.
 */
class TelefonoPerteneceAOtroClienteException extends RuntimeException
{
    public function __construct(public readonly int $customerIdTitular, public readonly int $customerIdSolicitante)
    {
        parent::__construct('El telefono ya pertenece a otro cliente.');
    }
}
