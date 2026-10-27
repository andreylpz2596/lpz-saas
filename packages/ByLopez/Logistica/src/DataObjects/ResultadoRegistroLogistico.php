<?php

namespace ByLopez\Logistica\DataObjects;

use ByLopez\Logistica\Models\Entrega;
use ByLopez\Logistica\Models\Retorno;

/** Resultado idempotente: `yaExistia` = la clave ya estaba registrada con la MISMA solicitud (no se duplico nada). */
final class ResultadoRegistroLogistico
{
    public function __construct(
        public readonly Entrega|Retorno $registro,
        public readonly bool $yaExistia,
    ) {}
}
