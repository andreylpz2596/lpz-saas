<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use ByLopez\VentasAsesor\Enums\TipoOperacionPedido;
use ByLopez\VentasAsesor\Models\ClasificacionPedido;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Webkul\User\Contracts\Admin;

/**
 * Lectura de la clasificacion comercial (G-11) preparada para COM-04 (bloque 25), y regla de quien puede elegir cada
 * tipo (bloque 23). No causa comisiones.
 *
 * - Comisionable SOLO si el pedido tiene clasificacion `venta_normal`. Sin clasificacion (historicos, otros canales)
 *   = NO comisionable por esta regla; muestra / reposicion / garantia = NO, sin importar el precio.
 * - Elegir un tipo distinto de `venta_normal` exige `ventas_asesor.pedidos.tipo_operacion` (un rol `all` lo tiene; el
 *   asesor no). Otros roles (p. ej. Bodega creando reposiciones) quedan como evolucion futura.
 */
class TipoOperacionPedidoService
{
    public function __construct(protected AutorizacionVentasAsesor $autorizacion) {}

    /**
     * @param  list<int>  $orderIds
     * @return array<int, ?TipoOperacionPedido> order_id => tipo (null = sin clasificar)
     */
    public function dePedidos(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        $tipos = ClasificacionPedido::query()->whereIn('order_id', $orderIds)->pluck('tipo_operacion', 'order_id');

        return array_combine($orderIds, array_map(fn (int $id) => $tipos[$id] ?? null, $orderIds)) ?: [];
    }

    public function dePedido(int $orderId): ?TipoOperacionPedido
    {
        return $this->dePedidos([$orderId])[$orderId];
    }

    public function esComisionable(int $orderId): bool
    {
        return $this->dePedido($orderId)?->comisionable() === true;
    }

    public function puedeElegir(?Admin $actor, TipoOperacionPedido $tipo): bool
    {
        return $tipo === TipoOperacionPedido::VENTA_NORMAL
            || $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_PEDIDOS_TIPO_OPERACION);
    }

    public function puedeClasificar(?Admin $actor): bool
    {
        return $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_PEDIDOS_TIPO_OPERACION);
    }
}
