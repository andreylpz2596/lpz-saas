<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * El historial de una atribucion no cumple lo que el modulo garantiza: falta el
 * evento de asignacion inicial, hay mas de uno, o el de un fallo tecnico no
 * guarda su codigo.
 *
 * Se lanza en lugar de devolver un exito silencioso: si la auditoria no es
 * coherente, ninguna decision tomada sobre ella es confiable.
 */
class HistorialIncoherenteException extends RuntimeException {}
