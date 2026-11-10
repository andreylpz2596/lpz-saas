<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Enums\EstadoReasignacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Operacion auditada que mueve responsabilidad sobre prendas pendientes de un taller a otro, sin editar la asignacion
 * original ni los ENT anteriores. Ver migracion 2026_10_29_000001.
 */
class Reasignacion extends Model
{
    protected $table = 'bylopez_cc_reasignaciones';

    protected $fillable = [
        'codigo', 'orden_corte_id', 'taller_origen_id', 'taller_destino_id',
        'orden_confeccion_origen_id', 'orden_confeccion_destino_id',
        'estado', 'situacion_fisica', 'motivo', 'observacion', 'idempotency_key', 'payload_hash',
        'reasignada_por', 'recibida_en', 'recibida_por', 'cerrada_en', 'cerrada_por', 'motivo_cierre', 'constancia_cierre',
    ];

    protected $casts = [
        'recibida_en' => 'datetime',
        'cerrada_en' => 'datetime',
    ];

    public function estadoEnum(): ?EstadoReasignacion
    {
        return EstadoReasignacion::tryFrom((string) $this->estado);
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(ReasignacionLinea::class, 'reasignacion_id');
    }

    public function ordenCorte(): BelongsTo
    {
        return $this->belongsTo(OrdenCorte::class, 'orden_corte_id');
    }

    public function tallerOrigen(): BelongsTo
    {
        return $this->belongsTo(Taller::class, 'taller_origen_id');
    }

    public function tallerDestino(): BelongsTo
    {
        return $this->belongsTo(Taller::class, 'taller_destino_id');
    }

    public function ordenOrigen(): BelongsTo
    {
        return $this->belongsTo(OrdenConfeccion::class, 'orden_confeccion_origen_id');
    }

    public function ordenDestino(): BelongsTo
    {
        return $this->belongsTo(OrdenConfeccion::class, 'orden_confeccion_destino_id');
    }

    public function totalUnidades(): int
    {
        return (int) ($this->relationLoaded('lineas') ? $this->lineas->sum('cantidad') : $this->lineas()->sum('cantidad'));
    }
}
