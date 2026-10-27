<?php

namespace ByLopez\VentasAsesor\Listeners;

use ByLopez\VentasAsesor\Exceptions\ContextoPrecioComercialInvalidoException as ContextoInvalido;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Models\GrupoEventoBorrador;
use ByLopez\VentasAsesor\Models\SnapshotGrupoPedido;
use ByLopez\VentasAsesor\Support\Venta\ConfirmacionEnCurso;
use Webkul\Customer\Models\CustomerGroupProxy;
use Webkul\Customer\Models\CustomerProxy;

/**
 * GRP-01: snapshot comercial DURABLE del grupo con el que se vende el pedido de Nueva venta (grupo original, grupo
 * efectivo y si hubo override), asociado a `order_id` en estructuras propias, y cierre del historial de grupo de la venta
 * (sus eventos pasan a apuntar al pedido). Corre en la MISMA transaccion del pedido, tras el enlace borrador → pedido: si
 * falla, LANZA y el pedido se revierte (no existe «pedido de Nueva venta sin snapshot de grupo»). Los pedidos de otros
 * canales pasan sin consultas ni fila.
 *
 * Fuente: el borrador ya enlazado (mismo dato que uso ContextoPrecioComercial para preciar). Nada se recalcula aqui.
 */
class RegistrarSnapshotGrupoPedido
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

        $borrador = BorradorVenta::query()->findOrFail($intento['borrador_id']);
        $customerId = (int) $pedido->customer_id;
        $cliente = CustomerProxy::modelClass()::query()->with('group')->find($customerId);

        if (! $cliente) {
            throw new ContextoInvalido(ContextoInvalido::CLIENTE_INEXISTENTE, "El cliente {$customerId} del pedido no existe.");
        }

        $override = $borrador->grupo_override
            && $borrador->grupo_efectivo_id !== null
            && $borrador->grupo_override_customer_id === $customerId;

        $efectivo = $override ? CustomerGroupProxy::modelClass()::query()->find($borrador->grupo_efectivo_id) : $cliente->group;

        if (! $efectivo) {
            throw new ContextoInvalido(ContextoInvalido::GRUPO_OVERRIDE_INEXISTENTE, "El grupo con el que se vendio el pedido {$pedido->id} no existe.");
        }

        $original = $override && $borrador->grupo_original_id !== null
            ? CustomerGroupProxy::modelClass()::query()->find($borrador->grupo_original_id)
            : $cliente->group;

        SnapshotGrupoPedido::query()->create([
            'order_id' => (int) $pedido->id,
            'customer_id' => $customerId,
            'borrador_id' => (int) $borrador->id,
            'grupo_original_id' => $original?->id ?? $borrador->grupo_original_id,
            'grupo_original_nombre' => mb_substr((string) ($original?->name ?? 'Grupo #'.$borrador->grupo_original_id.' (eliminado)'), 0, 191),
            'grupo_original_codigo' => $original?->code,
            'grupo_efectivo_id' => (int) $efectivo->id,
            'grupo_efectivo_nombre' => mb_substr((string) $efectivo->name, 0, 191),
            'grupo_efectivo_codigo' => $efectivo->code,
            'grupo_override' => $override,
        ]);

        // UPDATE condicionado (solo eventos aun sin pedido): el historial se relaciona con el pedido al confirmar.
        GrupoEventoBorrador::query()
            ->where('borrador_id', (int) $borrador->id)
            ->whereNull('order_id')
            ->update(['order_id' => (int) $pedido->id]);
    }
}
