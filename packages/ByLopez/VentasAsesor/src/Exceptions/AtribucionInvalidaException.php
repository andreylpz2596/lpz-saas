<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Contexto comercial incoherente o no autorizado. Falla cerrado: el dominio
 * prefiere rechazar una atribucion antes que inventarla.
 */
class AtribucionInvalidaException extends RuntimeException {}
