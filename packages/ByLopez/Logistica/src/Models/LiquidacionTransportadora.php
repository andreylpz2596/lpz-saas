<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CIERRE INMUTABLE de la cuenta de una transportadora (LIQ-000001). Una liquidacion cerrada no se edita ni se borra:
 * una diferencia posterior se corrige con un AJUSTE, que entra en la cuenta siguiente.
 */
class LiquidacionTransportadora extends Model
{
    public const PREFIJO = 'LIQ-';

    public const SENTIDO_TRANSPORTADOR_ENTREGA = 'transportador_entrega';

    public const SENTIDO_BYLOPEZ_PAGA = 'bylopez_paga';

    public const SENTIDO_AL_DIA = 'al_dia';

    protected $table = 'bylopez_lg_liquidaciones_transportadora';

    protected $fillable = [
        'consecutivo', 'transportadora_id', 'periodo_desde', 'periodo_hasta', 'movimientos_count', 'total_recaudos',
        'total_servicios', 'total_ajustes', 'saldo_esperado', 'sentido', 'valor_liquidado', 'diferencia', 'observacion',
        'referencia', 'resumen', 'estado', 'idempotency_key', 'huella', 'actor_admin_id', 'actor_name_snapshot', 'cerrada_en',
    ];

    protected $casts = [
        'consecutivo' => 'integer',
        'transportadora_id' => 'integer',
        'movimientos_count' => 'integer',
        'actor_admin_id' => 'integer',
        'total_recaudos' => 'decimal:2',
        'total_servicios' => 'decimal:2',
        'total_ajustes' => 'decimal:2',
        'saldo_esperado' => 'decimal:2',
        'valor_liquidado' => 'decimal:2',
        'diferencia' => 'decimal:2',
        'resumen' => 'array',
        'periodo_desde' => 'date',
        'periodo_hasta' => 'date',
        'cerrada_en' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn (self $l) => throw new Invalida(Invalida::LIQUIDACION_CERRADA, ['liquidacion' => $l->getKey()]));
        static::deleting(fn (self $l) => throw new Invalida(Invalida::LIQUIDACION_CERRADA, ['liquidacion' => $l->getKey()]));
    }

    public static function formatear(int $consecutivo): string
    {
        return self::PREFIJO.str_pad((string) $consecutivo, 6, '0', STR_PAD_LEFT);
    }

    public function numero(): string
    {
        return self::formatear((int) $this->consecutivo);
    }

    public function transportadora(): BelongsTo
    {
        return $this->belongsTo(Transportadora::class, 'transportadora_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoCuenta::class, 'liquidacion_id')->orderBy('id');
    }
}
