<?php

namespace ByLopez\Logistica\Models\Concerns;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;

/** Las unidades de una entrega/retorno se escriben una vez con su evento: nunca se editan ni se borran. */
trait ItemSoloAdicion
{
    protected static function bootItemSoloAdicion(): void
    {
        static::updating(fn (self $item) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['item' => $item->getKey()]));
        static::deleting(fn (self $item) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['item' => $item->getKey()]));
    }
}
