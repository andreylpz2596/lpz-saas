<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Operacion invalida en «Control de costos» (D-COST-05). `codigo` es estable para pruebas y mensajes.
 */
class CostoComisionableInvalidoException extends RuntimeException
{
    public const SIN_PERMISO = 'sin_permiso';

    public const SELECCION_VACIA = 'seleccion_vacia';

    public const SELECCION_EXCESIVA = 'seleccion_excesiva';

    public const PRODUCTO_NO_VALIDO = 'producto_no_valido';

    public const COSTO_INVALIDO = 'costo_invalido';

    public const VISTA_PREVIA_REQUERIDA = 'vista_previa_requerida';

    public const SIN_CAMBIOS = 'sin_cambios';

    public const ATRIBUTO_INEXISTENTE = 'atributo_cost_inexistente';

    public const BITACORA_INMUTABLE = 'bitacora_inmutable';

    public function __construct(public readonly string $codigo, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}
