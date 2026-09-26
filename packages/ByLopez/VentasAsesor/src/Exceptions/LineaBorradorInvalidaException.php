<?php

namespace ByLopez\VentasAsesor\Exceptions;

use ByLopez\VentasAsesor\DataObjects\DesgloseAbastecimiento;
use RuntimeException;
use Throwable;

/**
 * Operacion sobre una linea del borrador rechazada (03C). Producto, variante y cantidad
 * usan VarianteInvalidaException; estado, propiedad y permiso, BorradorVentaInvalidoException.
 */
class LineaBorradorInvalidaException extends RuntimeException
{
    /** El cart_item no existe, no es de este carrito o es la fila interna de una variante. */
    public const LINEA_NO_PERTENECE_AL_BORRADOR = 'linea_no_pertenece_al_borrador';

    /**
     * Bagisto no admite en el carrito mas unidades que el stock vendible (sin backorders).
     * `abastecimiento` trae el desglose de la cantidad pedida (I-12) para ofrecer lo
     * disponible; nada se escribio.
     */
    public const STOCK_INSUFICIENTE = 'stock_insuficiente';

    /** I-13 (D-39): no hay solicitud comercial de esa variante en el borrador. */
    public const SIN_SOLICITUD = 'linea_sin_solicitud';

    /** I-13 (D-39): el carrito tendria mas unidades de la variante que las solicitadas. */
    public const EXCEDE_SOLICITUD = 'linea_excede_solicitud';

    /** El carrito de Bagisto rechazo la operacion por otra razon (p. ej. opciones requeridas). */
    public const RECHAZADA_POR_CARRITO = 'rechazada_por_carrito';

    public function __construct(
        public readonly string $codigo,
        public readonly ?DesgloseAbastecimiento $abastecimiento = null,
        ?Throwable $previa = null,
    ) {
        parent::__construct('Linea de borrador no valida: '.$codigo, 0, $previa);
    }
}
