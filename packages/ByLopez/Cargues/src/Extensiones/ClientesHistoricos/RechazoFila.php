<?php

namespace ByLopez\Cargues\Extensiones\ClientesHistoricos;

use RuntimeException;

/** Una fila que NO se puede aplicar (identidad ambigua, conflicto de guia...): revierte la transaccion de la fila y la deja rechazada con motivo. */
class RechazoFila extends RuntimeException
{
    public function __construct(public readonly string $codigo, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}
