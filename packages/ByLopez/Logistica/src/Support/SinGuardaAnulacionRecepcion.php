<?php

namespace ByLopez\Logistica\Support;

use ByLopez\Logistica\Contracts\GuardaAnulacionRecepcion;

/** Implementacion neutra: sin credito del cliente no hay nada que proteger al anular una recepcion. */
class SinGuardaAnulacionRecepcion implements GuardaAnulacionRecepcion
{
    public function antes(int $orderId): void {}

    public function despues(int $orderId): void {}
}
