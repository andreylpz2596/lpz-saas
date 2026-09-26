<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * El grupo indicado no sirve para un cliente comercial (D-RT04-04). Fail closed:
 * nunca se sustituye por un grupo por defecto ni por el invitado.
 */
class GrupoComercialInvalidoException extends RuntimeException
{
    public const GRUPO_REQUERIDO = 'grupo_requerido';

    public const GRUPO_INEXISTENTE = 'grupo_inexistente';

    public const GRUPO_INVITADO_NO_PERMITIDO = 'grupo_invitado_no_permitido';

    /** El core no encuentra su grupo invitado (code 'guest'): no se puede excluir con certeza. */
    public const INVITADO_NO_IDENTIFICABLE = 'invitado_no_identificable';

    public function __construct(public readonly string $codigo)
    {
        parent::__construct('Grupo comercial no valido: '.$codigo);
    }
}
