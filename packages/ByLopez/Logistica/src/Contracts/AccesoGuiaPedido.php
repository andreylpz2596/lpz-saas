<?php

namespace ByLopez\Logistica\Contracts;

use Webkul\User\Contracts\Admin;

/**
 * Quien puede ver / descargar las guias de un pedido. Logistica solo conoce el permiso `logistica.guias.ver`;
 * VentasAsesor lo amplia para que el ASESOR vea las de SUS pedidos (propiedad comercial). Decide el backend: ocultar
 * un boton no autoriza nada.
 */
interface AccesoGuiaPedido
{
    public function puedeVer(?Admin $actor, int $orderId): bool;
}
