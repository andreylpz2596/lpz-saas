<?php

namespace ByLopez\Logistica\Enums;

/** Un movimiento nace `abierto` y pasa UNA sola vez a `liquidado` (dentro de una liquidacion cerrada). Nunca vuelve. */
enum EstadoMovimientoCuenta: string
{
    case ABIERTO = 'abierto';
    case LIQUIDADO = 'liquidado';
}
