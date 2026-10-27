<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Liquidacion de comisiones de un asesor: borrador → aprobada (inmutable) | anulada (con motivo). No se borra. */
class ComisionLiquidacion extends Model
{
    public const BORRADOR = 'borrador';

    public const APROBADA = 'aprobada';

    public const ANULADA = 'anulada';

    protected $table = 'bylopez_va_comision_liquidaciones';

    protected $fillable = [
        'consecutivo', 'asesor_admin_id', 'asesor_name_snapshot', 'periodo_desde', 'periodo_hasta', 'estado', 'lineas_count',
        'total_lineas', 'total_ajustes', 'total', 'observacion', 'idempotency_key', 'creado_por_admin_id',
        'creado_por_name_snapshot', 'aprobada_por_admin_id', 'aprobada_por_name_snapshot', 'aprobada_en',
        'anulada_por_admin_id', 'anulada_en', 'motivo_anulacion',
    ];

    protected $casts = [
        'consecutivo' => 'integer',
        'asesor_admin_id' => 'integer',
        'periodo_desde' => 'date',
        'periodo_hasta' => 'date',
        'lineas_count' => 'integer',
        'aprobada_en' => 'datetime',
        'anulada_en' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $l) {
            if ($l->getOriginal('estado') === self::ANULADA) {
                throw new Invalida(Invalida::ESTADO_INVALIDO, 'Una liquidacion anulada no cambia.');
            }

            $recalcula = $l->isDirty(['total', 'total_lineas', 'total_ajustes', 'lineas_count', 'periodo_desde', 'periodo_hasta']);

            if ($l->getOriginal('estado') === self::APROBADA && ($recalcula || ($l->isDirty('estado') && $l->estado !== self::ANULADA))) {
                throw new Invalida(Invalida::ESTADO_INVALIDO, 'Una liquidacion aprobada no se recalcula.');
            }
        });

        static::deleting(fn () => throw new Invalida(Invalida::BORRADO_PROHIBIDO, 'Una liquidacion no se borra.'));
    }

    public function numero(): string
    {
        return 'LQC-'.str_pad((string) $this->consecutivo, 6, '0', STR_PAD_LEFT);
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(ComisionLinea::class, 'liquidacion_id');
    }

    public function ajustes(): HasMany
    {
        return $this->hasMany(ComisionAjuste::class, 'liquidacion_id');
    }

    public function abonos(): HasMany
    {
        return $this->hasMany(ComisionAbono::class, 'liquidacion_id')->orderBy('id');
    }
}
