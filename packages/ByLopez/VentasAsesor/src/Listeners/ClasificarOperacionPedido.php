<?php

namespace ByLopez\VentasAsesor\Listeners;

use ByLopez\VentasAsesor\Enums\TipoOperacionPedido;
use ByLopez\VentasAsesor\Models\ClasificacionPedido;
use ByLopez\VentasAsesor\Support\Venta\ConfirmacionEnCurso;
use Webkul\User\Models\AdminProxy;

/**
 * G-11 (COM-D-15): escribe la clasificacion comercial del pedido creado por Nueva venta, dentro de la transaccion del
 * pedido, justo despues del enlace borrador → pedido. Si falla, LANZA y el pedido se revierte (como el enlace): no
 * existe «pedido de Nueva venta sin clasificar». Los pedidos de otros canales pasan sin consultas ni fila.
 */
class ClasificarOperacionPedido
{
    public const EVENTO = 'checkout.order.save.after';

    public function __construct(protected ConfirmacionEnCurso $confirmaciones) {}

    public function handle(mixed $pedido): void
    {
        $cartId = (int) ($pedido->cart_id ?? 0);
        $intento = $cartId > 0 ? $this->confirmaciones->para($cartId) : null;

        if ($intento === null) {
            return;
        }

        $actor = isset($intento['actor_id']) ? AdminProxy::modelClass()::query()->find($intento['actor_id']) : null;

        ClasificacionPedido::query()->create([
            'order_id' => (int) $pedido->id,
            'tipo_operacion' => $intento['tipo_operacion'] ?? TipoOperacionPedido::VENTA_NORMAL,
            'origen' => ClasificacionPedido::ORIGEN_NUEVA_VENTA,
            'borrador_id' => $intento['borrador_id'],
            'actor_admin_id' => $actor?->id,
            'actor_nombre_snapshot' => $actor ? mb_substr((string) $actor->name, 0, 191) : null,
        ]);
    }
}
