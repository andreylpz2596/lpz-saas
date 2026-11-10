<?php

namespace ByLopez\CorteConfeccion\Exceptions;

use RuntimeException;

/** La llave de idempotencia ya existe con un contenido distinto: no se modifica la operacion original. */
class ReasignacionConflictoException extends RuntimeException {}
