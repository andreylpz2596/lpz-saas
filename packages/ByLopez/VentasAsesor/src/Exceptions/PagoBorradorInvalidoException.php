<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Metodo de pago del borrador rechazado (03E). Estado, propiedad y permiso usan
 * BorradorVentaInvalidoException; el desglose de stock lo aporta EntregaBorradorInvalidaException.
 *
 * NUNCA representa dinero recibido: eso es D-53 (RegistrarPagoPedidoService), fuera de 03E.
 */
class PagoBorradorInvalidoException extends RuntimeException
{
    /** No es un codigo de metodo conocido por Bagisto. */
    public const METODO_INEXISTENTE = 'metodo_pago_inexistente';

    /**
     * Existe en Bagisto pero no en la allowlist de VentasAsesor (D-49), o no esta
     * `isAvailable()` ahora mismo (p. ej. gateway sin credenciales).
     */
    public const METODO_NO_DISPONIBLE = 'metodo_pago_no_disponible';

    public function __construct(public readonly string $codigo)
    {
        parent::__construct('Metodo de pago de borrador no valido: '.$codigo);
    }
}
