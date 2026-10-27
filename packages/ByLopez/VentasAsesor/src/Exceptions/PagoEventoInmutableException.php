<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Se intento modificar o borrar un evento del historial de un pago. El historial es solo de adicion
 * (Doc. 05 §42.2, invariante I-9).
 */
class PagoEventoInmutableException extends RuntimeException {}
