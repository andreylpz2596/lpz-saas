<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Operacion invalida sobre una regla de comision (COM-03). `codigo` es estable para pruebas y mensajes.
 */
class ReglaComisionInvalidaException extends RuntimeException
{
    public const CAMPO_INMUTABLE = 'campo_inmutable';

    public const BORRADO_PROHIBIDO = 'borrado_prohibido';

    public const RETROACTIVA = 'retroactiva';

    public const SOLAPE = 'solape';

    public const PORCENTAJE_INVALIDO = 'porcentaje_invalido';

    public const CODIGO_DUPLICADO = 'codigo_duplicado';

    public const CANCELACION_INVALIDA = 'cancelacion_invalida';

    public function __construct(public readonly string $codigo, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}
