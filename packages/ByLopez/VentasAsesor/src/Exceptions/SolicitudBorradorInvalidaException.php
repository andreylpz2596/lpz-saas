<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Operacion sobre una solicitud comercial del borrador rechazada (03C, D-39). Producto,
 * variante y cantidad usan VarianteInvalidaException; estado, propiedad y permiso,
 * BorradorVentaInvalidoException.
 */
class SolicitudBorradorInvalidaException extends RuntimeException
{
    /** No existe o pertenece a otro borrador. */
    public const INEXISTENTE = 'solicitud_inexistente';

    /** Ya hay una solicitud de esa variante en el borrador: se cambia su cantidad. */
    public const YA_EXISTE = 'solicitud_ya_existe';

    /** El carrito tiene unidades de esa variante: primero se retiran del carrito. */
    public const EN_CARRITO = 'solicitud_en_carrito';

    /** La cantidad solicitada no puede quedar por debajo de lo que ya esta en el carrito. */
    public const MENOR_QUE_CARRITO = 'solicitud_menor_que_carrito';

    public function __construct(public readonly string $codigo)
    {
        parent::__construct('Solicitud de borrador no valida: '.$codigo);
    }
}
