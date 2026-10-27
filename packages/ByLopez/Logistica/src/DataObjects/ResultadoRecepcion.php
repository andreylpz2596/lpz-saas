<?php

namespace ByLopez\Logistica\DataObjects;

use ByLopez\Logistica\Models\RecepcionDevolucion;

/** `yaExistia` = la clave ya estaba registrada con la MISMA solicitud: no se duplico nada (ni stock). */
final class ResultadoRecepcion
{
    public function __construct(
        public readonly RecepcionDevolucion $recepcion,
        public readonly bool $yaExistia,
    ) {}
}
