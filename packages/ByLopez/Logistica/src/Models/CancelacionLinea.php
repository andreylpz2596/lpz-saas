<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;

/** Linea (padre) del pedido afectada por una cancelacion del pendiente. Historial inmutable. */
class CancelacionLinea extends Model
{
    public $timestamps = false;

    protected $table = 'bylopez_lg_cancelacion_lineas';

    protected $fillable = ['cancelacion_id', 'order_item_id', 'cantidad', 'valor', 'libero_reserva'];

    protected $casts = ['cancelacion_id' => 'integer', 'order_item_id' => 'integer', 'cantidad' => 'integer', 'valor' => 'decimal:2', 'libero_reserva' => 'boolean'];

    protected static function booted(): void
    {
        static::updating(fn (self $c) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['cancelacion_linea' => $c->getKey()]));
        static::deleting(fn (self $c) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['cancelacion_linea' => $c->getKey()]));
    }
}
