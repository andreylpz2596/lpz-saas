<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Operacion rechazada en configuracion de rentabilidad, comisiones, liquidaciones o abonos (Doc. 23). `codigo` es estable;
 * el mensaje es legible y nunca expone costos al asesor.
 */
class OperacionComisionInvalidaException extends RuntimeException
{
    public const SIN_PERMISO = 'sin_permiso';

    public const DATOS_INVALIDOS = 'datos_invalidos';

    public const CAMPO_INMUTABLE = 'campo_inmutable';

    public const BORRADO_PROHIBIDO = 'borrado_prohibido';

    public const RETROACTIVA = 'retroactiva';

    public const SOLAPE = 'solape';

    public const NO_ENCONTRADO = 'no_encontrado';

    public const ESTADO_INVALIDO = 'estado_invalido';

    public const SIN_LINEAS = 'sin_lineas';

    public const MONTO_EXCEDE_SALDO = 'monto_excede_saldo';

    public const LINEA_COMPROMETIDA = 'linea_comprometida';

    public const IDEMPOTENCIA_CONFLICTO = 'idempotencia_conflicto';

    /** La operacion (comprobante) ya respalda otros abonos o ya fue aplicada por completo. */
    public const OPERACION_DUPLICADA = 'operacion_duplicada';

    public function __construct(public readonly string $codigo, string $mensaje = '')
    {
        parent::__construct($mensaje !== '' ? $mensaje : $codigo);
    }
}
