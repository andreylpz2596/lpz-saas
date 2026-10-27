<?php

namespace ByLopez\Logistica\DataObjects;

use ByLopez\Logistica\Models\Entrega;
use ByLopez\Logistica\Models\Guia;

/** `yaExistia` = la clave ya estaba registrada con la MISMA solicitud (no se duplico nada). */
final class ResultadoDespacho
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $shipmentId,
        public readonly ?Guia $guia,
        public readonly ?Entrega $entrega,
        public readonly bool $yaExistia,
    ) {}
}
