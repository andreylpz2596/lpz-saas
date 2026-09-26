<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Datos de cliente o de direccion comercial rechazados antes de escribir. `errores`
 * es campo => codigo estable, sin valores (no se filtran datos personales).
 */
class DatosClienteComercialInvalidosException extends RuntimeException
{
    public const REQUERIDO = 'requerido';

    public const DEMASIADO_LARGO = 'demasiado_largo';

    public const EMAIL_INVALIDO = 'email_invalido';

    public const TELEFONO_NO_NORMALIZABLE = 'telefono_no_normalizable';

    public const FORMATO_INVALIDO = 'formato_invalido';

    /** @param  array<string, string>  $errores */
    public function __construct(public readonly array $errores)
    {
        parent::__construct('Datos comerciales no validos: '.implode(', ', array_keys($errores)));
    }
}
