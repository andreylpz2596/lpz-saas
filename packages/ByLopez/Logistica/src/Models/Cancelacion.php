<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cancelacion de lo pendiente de despacho de un pedido (historial inmutable). La escribe CancelarPendienteService. */
class Cancelacion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'bylopez_lg_cancelaciones';

    protected $fillable = ['order_id', 'motivo', 'unidades', 'valor_cancelado', 'actor_admin_id', 'actor_name_snapshot'];

    protected $casts = ['order_id' => 'integer', 'unidades' => 'integer', 'valor_cancelado' => 'decimal:2', 'actor_admin_id' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn (self $c) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['cancelacion' => $c->getKey()]));
        static::deleting(fn (self $c) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['cancelacion' => $c->getKey()]));
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(CancelacionLinea::class, 'cancelacion_id');
    }
}
