<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Se intento modificar, borrar o insertar historial de propiedad comercial fuera
 * del servicio autorizado. El historial es solo de adicion.
 */
class HistorialInmutableException extends RuntimeException {}
