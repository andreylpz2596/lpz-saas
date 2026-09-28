<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Rechazo del cerco de creacion de pedidos (03F, D-54 y D-55) dentro de la transaccion de
 * OrderRepository::createOrderIfNotThenRetry(), ANTES de insertar el pedido.
 *
 * Aplica a TODO pedido que pase por OrderRepository (tienda, panel y VentasAsesor), por eso
 * no es una excepcion de la confirmacion: ConfirmarVentaService la traduce a
 * ConfirmacionVentaInvalidaException con el mismo codigo.
 *
 * Extiende RuntimeException (\Exception): el repositorio la atrapa, hace rollback y
 * reintenta; cada reintento vuelve a pasar por el cerco y falla igual.
 */
class PedidoRechazadoPorCercoException extends RuntimeException
{
    /** Un producto con gestion de stock no alcanza bajo bloqueo (detalles.variantes). */
    public const STOCK_INSUFICIENTE = 'stock_insuficiente';

    /** El intento de confirmacion ByLopez no tiene el token vigente del borrador. */
    public const CONFIRMACION_OBSOLETA = 'confirmacion_obsoleta';

    /**
     * Pedido SIN contexto de confirmacion ByLopez sobre el carrito de un borrador activo o
     * confirmando (p. ej. la ruta nativa del panel): saltaria la idempotencia del borrador.
     */
    public const CARRITO_DE_BORRADOR = 'carrito_de_borrador_fuera_de_confirmacion';

    /** @param  array<string, mixed>  $detalles */
    public function __construct(
        public readonly string $codigo,
        public readonly array $detalles = [],
    ) {
        parent::__construct('Pedido rechazado por el cerco: '.$codigo);
    }
}
