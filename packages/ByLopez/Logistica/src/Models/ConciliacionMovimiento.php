<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Como UNA liquidacion satisfizo UN movimiento de la cuenta: `efectivo` (dinero que entro o salio de caja) y `compensado` (satisfecho
 * contra el servicio/recaudo opuesto, sin mover caja). Conciliado = efectivo + compensado. Solo atribuye: no es un libro paralelo.
 * Inmutable: corregir = ajuste o nueva operacion. Un movimiento puede tener filas en varias liquidaciones (conciliacion parcial);
 * la suma historica nunca supera |importe| (lo garantiza ConciliarMovimientos bajo bloqueo).
 */
class ConciliacionMovimiento extends Model
{
    public const ORIGEN_PROPUESTA = 'propuesta';

    public const ORIGEN_MANUAL = 'manual';

    protected $table = 'bylopez_lg_conciliaciones_movimiento';

    protected $fillable = ['liquidacion_id', 'movimiento_id', 'efectivo', 'compensado', 'origen', 'motivo', 'actor_admin_id'];

    protected $casts = [
        'liquidacion_id' => 'integer',
        'movimiento_id' => 'integer',
        'efectivo' => 'decimal:2',
        'compensado' => 'decimal:2',
        'actor_admin_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn (self $c) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['conciliacion' => $c->getKey()]));
        static::deleting(fn (self $c) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['conciliacion' => $c->getKey()]));
    }

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(MovimientoCuenta::class, 'movimiento_id');
    }

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(LiquidacionTransportadora::class, 'liquidacion_id');
    }
}
