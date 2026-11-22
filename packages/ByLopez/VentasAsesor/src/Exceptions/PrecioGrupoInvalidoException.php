<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Operacion invalida en «Costos y precios › Precios por grupo» (Doc. 32). `codigo` es estable para pruebas y mensajes.
 */
class PrecioGrupoInvalidoException extends RuntimeException
{
    public const SIN_PERMISO = 'sin_permiso';

    public const GRUPO_INVALIDO = 'grupo_invalido';

    public const OPERACION_INVALIDA = 'operacion_invalida';

    public const VALOR_INVALIDO = 'valor_invalido';

    public const CANTIDAD_INVALIDA = 'cantidad_invalida';

    public const SELECCION_VACIA = 'seleccion_vacia';

    public const SELECCION_EXCESIVA = 'seleccion_excesiva';

    public const PRODUCTO_NO_VALIDO = 'producto_no_valido';

    public const MOTIVO_REQUERIDO = 'motivo_requerido';

    /** La vista previa tiene filas con error: no se confirma nada. */
    public const FILAS_CON_ERROR = 'filas_con_error';

    /** Nada que crear, actualizar ni quitar. */
    public const SIN_CAMBIOS = 'sin_cambios';

    /** Los datos cambiaron desde la vista previa (otro usuario, otra pestana, la ficha de Bagisto). */
    public const CONFLICTO = 'conflicto';

    public const BITACORA_INMUTABLE = 'bitacora_inmutable';

    public function __construct(public readonly string $codigo, string $mensaje, public readonly array $detalles = [])
    {
        parent::__construct($mensaje);
    }
}
