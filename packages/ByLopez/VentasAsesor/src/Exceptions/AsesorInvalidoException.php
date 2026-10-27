<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/** No se pudo crear, editar o cambiar el estado de un asesor (ASESOR-01). Nada queda escrito. */
class AsesorInvalidoException extends RuntimeException
{
    public const SIN_PERMISO = 'sin_permiso';

    public const INEXISTENTE = 'asesor_inexistente';

    public const USUARIO_INVALIDO = 'usuario_invalido';

    public const USUARIO_NO_ELEGIBLE = 'usuario_no_elegible';

    public const USUARIO_YA_ASOCIADO = 'usuario_ya_asociado';

    public const DATOS_INVALIDOS = 'datos_invalidos';

    public function __construct(public readonly string $codigo, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}
