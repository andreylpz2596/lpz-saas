<?php

namespace ByLopez\CorteConfeccion\Exceptions;

use RuntimeException;

/** La llave de idempotencia ya existe con un contenido distinto: la operacion original no se modifica. */
class CarteraConflictoException extends RuntimeException {}
