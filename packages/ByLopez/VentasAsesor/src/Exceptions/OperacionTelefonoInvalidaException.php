<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/** Operacion de telefonos rechazada por una regla del dominio (RT-04B1b). Sin datos personales. */
class OperacionTelefonoInvalidaException extends RuntimeException
{
    public const TELEFONO_NO_NORMALIZABLE = 'telefono_no_normalizable';

    public const CLIENTE_INEXISTENTE = 'cliente_inexistente';

    public const TELEFONO_NO_ES_DEL_CLIENTE = 'telefono_no_es_del_cliente';

    /** El principal no se elimina: primero se establece otro principal. */
    public const PRINCIPAL_NO_ELIMINABLE = 'principal_no_eliminable';

    public function __construct(public readonly string $codigo)
    {
        parent::__construct('Operacion de telefono no valida: '.$codigo);
    }
}
