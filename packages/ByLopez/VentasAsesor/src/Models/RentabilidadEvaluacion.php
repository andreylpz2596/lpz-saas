<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Snapshot INMUTABLE de la evaluacion de rentabilidad de un pedido, escrito al crearlo (R2). */
class RentabilidadEvaluacion extends Model
{
    protected $table = 'bylopez_va_rentabilidad_evaluaciones';

    protected $fillable = [
        'order_id', 'resultado', 'bloqueo_activo', 'canal', 'propiedad', 'asesor_admin_id', 'acuerdo_id', 'porcentaje',
        'costo_pedido_estimado', 'holgura_pedido', 'costos_directos', 'motivos', 'evaluado_en',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'bloqueo_activo' => 'boolean',
        'asesor_admin_id' => 'integer',
        'acuerdo_id' => 'integer',
        'porcentaje' => 'decimal:4',
        'costo_pedido_estimado' => 'decimal:2',
        'holgura_pedido' => 'decimal:2',
        'costos_directos' => 'array',
        'motivos' => 'array',
        'evaluado_en' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new Invalida(Invalida::CAMPO_INMUTABLE, 'La evaluacion de un pedido no se modifica.'));
        static::deleting(fn () => throw new Invalida(Invalida::BORRADO_PROHIBIDO, 'La evaluacion de un pedido no se borra.'));
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(RentabilidadEvaluacionLinea::class, 'evaluacion_id');
    }
}
