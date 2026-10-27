<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Enums\TipoDisposicionCobro;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;

/**
 * F6: decision de cobro de un pedido para su despacho (historial: solo inserciones). La vigente es la ultima del pedido y solo
 * mientras el pago siga como estaba al decidir (`saldo_referencia`, `sin_revisar_referencia`). La escribe DisposicionCobroService.
 */
class DisposicionCobro extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'bylopez_lg_disposiciones_cobro';

    protected $fillable = ['order_id', 'tipo', 'motivo', 'saldo_referencia', 'sin_revisar_referencia', 'actor_admin_id', 'actor_name_snapshot'];

    protected $casts = [
        'order_id' => 'integer',
        'tipo' => TipoDisposicionCobro::class,
        'saldo_referencia' => 'decimal:2',
        'sin_revisar_referencia' => 'decimal:2',
        'actor_admin_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn (self $d) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['disposicion' => $d->getKey()]));
        static::deleting(fn (self $d) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['disposicion' => $d->getKey()]));
    }
}
