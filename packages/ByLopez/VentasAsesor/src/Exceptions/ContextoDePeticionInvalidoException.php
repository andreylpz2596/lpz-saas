<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Uso incorrecto del contexto comercial acotado a la peticion: clave que no
 * corresponde al carrito del contexto, o intento de reemplazar en silencio un
 * contexto ya registrado por otro incompatible.
 *
 * Es un error de programacion del modulo, no un dato del usuario: falla ruidoso
 * en vez de atribuir una venta a quien no corresponde.
 */
class ContextoDePeticionInvalidoException extends RuntimeException {}
