<?php

namespace ByLopez\VentasAsesor\DataObjects;

use ByLopez\VentasAsesor\Enums\EstadoReconciliacionConfirmacion as Estado;

/**
 * Resultado de ConfirmarVentaService::reconciliar() (03F, Doc. 05 §38.7). Solo lectura:
 * describe la evidencia, no la corrige.
 */
final class ReconciliacionConfirmacion
{
    public const MOTIVO_CONFIRMADO_SIN_PEDIDO = 'confirmado_sin_pedido';

    public const MOTIVO_CONFIRMANDO_CON_PEDIDO = 'confirmando_con_pedido';

    public const MOTIVO_SIN_CARRITO = 'sin_carrito';

    /** Un pedido con orders.cart_id del borrador que no esta enlazado: nunca se enlaza solo. */
    public const MOTIVO_PEDIDO_DEL_CARRITO_SIN_ENLACE = 'pedido_del_carrito_sin_enlace';

    /** Dos o mas pedidos con el mismo cart_id (orders.cart_id no es UNIQUE): grave. */
    public const MOTIVO_PEDIDOS_DUPLICADOS_DEL_CARRITO = 'pedidos_duplicados_del_carrito';

    public function __construct(
        public readonly int $borradorId,
        public readonly Estado $estado,
        public readonly ?string $motivo = null,
        public readonly ?int $orderId = null,
        public readonly int $pedidosDelCarrito = 0,
    ) {}

    public function esGrave(): bool
    {
        return $this->motivo === self::MOTIVO_PEDIDOS_DUPLICADOS_DEL_CARRITO;
    }

    public function toArray(): array
    {
        return [
            'borrador_id' => $this->borradorId,
            'estado' => $this->estado->value,
            'motivo' => $this->motivo,
            'order_id' => $this->orderId,
            'pedidos_del_carrito' => $this->pedidosDelCarrito,
            'grave' => $this->esGrave(),
        ];
    }
}
