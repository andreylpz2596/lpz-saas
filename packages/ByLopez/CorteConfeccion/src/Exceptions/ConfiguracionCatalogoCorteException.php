<?php

namespace ByLopez\CorteConfeccion\Exceptions;

use RuntimeException;

/**
 * El catalogo Bagisto no tiene la estructura minima que necesita Orden de
 * Corte (por ejemplo falta un atributo estructural). No es un error de la
 * seleccion del usuario: el resolvedor falla cerrado en vez de continuar.
 */
class ConfiguracionCatalogoCorteException extends RuntimeException {}
