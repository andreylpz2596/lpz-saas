<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Producto o variante que no puede venderse tal como se pidio (03B). Nunca se adivina
 * un id: ante cualquier duda se rechaza con codigo estable.
 */
class VarianteInvalidaException extends RuntimeException
{
    public const PRODUCTO_INEXISTENTE = 'producto_inexistente';

    public const PRODUCTO_NO_VENDIBLE = 'producto_no_vendible';

    /** La variante existe pero su parent_id no es el configurable indicado. */
    public const VARIANTE_NO_PERTENECE_AL_PADRE = 'variante_no_pertenece_al_padre';

    public const OPCIONES_INCOMPLETAS = 'opciones_incompletas';

    public const VARIANTE_NO_ENCONTRADA = 'variante_no_encontrada';

    public const VARIANTE_AMBIGUA = 'variante_ambigua';

    public const CANTIDAD_INVALIDA = 'cantidad_invalida';

    public function __construct(public readonly string $codigo)
    {
        parent::__construct('Variante no valida: '.$codigo);
    }
}
