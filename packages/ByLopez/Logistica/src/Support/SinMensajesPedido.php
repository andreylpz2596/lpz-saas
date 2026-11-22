<?php

namespace ByLopez\Logistica\Support;

use ByLopez\Logistica\Contracts\MensajesPedidoCliente;
use ByLopez\Logistica\Exceptions\MensajePedidoNoDisponibleException as NoDisponible;
use Webkul\User\Contracts\Admin;

/** Implementacion por defecto del contrato: sin VentasAsesor no hay mensajes (la lista sale vacia). VentasAsesor la reemplaza. */
class SinMensajesPedido implements MensajesPedidoCliente
{
    public function opciones(int $orderId, Admin $actor): array
    {
        return [];
    }

    public function generar(int $orderId, string $clave, Admin $actor): array
    {
        throw new NoDisponible(NoDisponible::MENSAJE_NO_DISPONIBLE);
    }
}
