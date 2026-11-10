<?php

namespace ByLopez\Cargues\Extensiones\ProductosCamisetas;

use RuntimeException;

/** Un grupo (producto base) que NO se puede aplicar: revierte su transaccion y deja todas sus filas rechazadas con su motivo. */
class RechazoGrupo extends RuntimeException
{
    /** @param  array<int, array{0:string, 1:string}>  $porFila  cg_id => [codigo, mensaje] */
    public function __construct(public readonly array $porFila)
    {
        parent::__construct('Grupo rechazado');
    }
}
