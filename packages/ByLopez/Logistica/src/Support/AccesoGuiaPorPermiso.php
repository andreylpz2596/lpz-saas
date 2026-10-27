<?php

namespace ByLopez\Logistica\Support;

use ByLopez\Logistica\Contracts\AccesoGuiaPedido;
use Webkul\User\Contracts\Admin;

/** Acceso por defecto: solo quien tenga el permiso `logistica.guias.ver` (cualquier pedido). */
class AccesoGuiaPorPermiso implements AccesoGuiaPedido
{
    public function __construct(protected AutorizacionLogistica $autorizacion) {}

    public function puedeVer(?Admin $actor, int $orderId): bool
    {
        return $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_GUIAS_VER);
    }
}
