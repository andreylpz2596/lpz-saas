<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/** El usuario de autoservicio no puede operar (sin vinculo vigente, cliente no apto). Mensajes neutros: nunca datos internos. */
class AutoservicioInvalidoException extends RuntimeException
{
    public const SIN_VINCULO = 'sin_vinculo';

    public const CLIENTE_NO_APTO = 'cliente_no_apto';

    public function __construct(public readonly string $codigo)
    {
        parent::__construct('Tu acceso no está disponible. Comunícate con nuestro equipo.');
    }
}
