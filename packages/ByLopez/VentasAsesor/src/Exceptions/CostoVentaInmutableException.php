<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Se intento modificar o borrar un snapshot de costo de venta. El snapshot es inmutable (COM-01, Doc. 17 §7.1):
 * un cambio posterior del costo del catalogo nunca reescribe una venta ya hecha.
 */
class CostoVentaInmutableException extends RuntimeException {}
