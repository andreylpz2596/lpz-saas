<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Direccion o metodo de entrega del borrador rechazados (03D). Datos de direccion
 * invalidos usan DatosClienteComercialInvalidosException (mismas reglas que RT-04);
 * estado, propiedad y permiso, BorradorVentaInvalidoException.
 */
class EntregaBorradorInvalidaException extends RuntimeException
{
    /** La direccion guardada no es del cliente del carrito. */
    public const DIRECCION_NO_PERTENECE_AL_CLIENTE = 'direccion_no_pertenece_al_cliente';

    /** Ese origen de direccion no aplica a este rol (p. ej. punto de recogida como facturacion). */
    public const ORIGEN_NO_VALIDO = 'origen_direccion_no_valido';

    /** Bagisto exige primero la direccion de facturacion. */
    public const FACTURACION_REQUERIDA = 'facturacion_requerida';

    /** Para elegir metodo de entrega hace falta la direccion de envio. */
    public const DIRECCION_ENVIO_REQUERIDA = 'direccion_envio_requerida';

    /** Sin productos fisicos Bagisto no usa direccion de envio ni metodo de entrega. */
    public const SIN_PRODUCTOS_FISICOS = 'carrito_sin_productos_fisicos';

    /** El metodo no esta entre las tarifas que Bagisto ofrece para este carrito y destino. */
    public const METODO_NO_DISPONIBLE = 'metodo_entrega_no_disponible';

    /**
     * Una linea supera el stock vendible actual (Cart::isItemsHaveSufficientQuantity,
     * la misma comprobacion del panel). Primero se ajustan las lineas; nada se recorta.
     */
    public const STOCK_INSUFICIENTE = 'carrito_con_stock_insuficiente';

    public const PUNTO_RECOGIDA_INEXISTENTE = 'punto_recogida_inexistente';

    /** Hay varias bodegas activas: hay que indicar cual. */
    public const PUNTO_RECOGIDA_AMBIGUO = 'punto_recogida_ambiguo';

    /**
     * @param  list<array{cart_item_id: int, variante_id: int, sku: string, cantidad: int, stock_vendible: int}>  $lineasAfectadas
     */
    public function __construct(
        public readonly string $codigo,
        public readonly array $lineasAfectadas = [],
    ) {
        parent::__construct('Entrega de borrador no valida: '.$codigo);
    }
}
