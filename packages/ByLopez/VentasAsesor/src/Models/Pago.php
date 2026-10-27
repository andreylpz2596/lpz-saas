<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Enums\EstadoPago;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Customer\Models\CustomerProxy;

/**
 * Ingreso comercial registrado (D-59 / P-01, Doc. 05 §42.2). NO es una `order_transaction` de Bagisto ni la
 * crea: la Transaction nacera solo al confirmar (bloques posteriores). Aqui no vive saldo ni deuda (P-03).
 *
 * PAG-B solo define el modelo. Las transiciones de estado (registrar, confirmar, rechazar, reversar) las
 * ejecutaran servicios posteriores; este modelo no las implementa.
 */
class Pago extends Model
{
    protected $table = 'bylopez_va_pagos';

    protected $fillable = [
        'customer_id',
        'monto',
        'medio_cobro',
        'plataforma',
        'grupo_pago',
        'guia_id',
        'fecha_pago',
        'referencia',
        'referencia_normalizada',
        'observacion',
        'estado',
        'mecanismo',
        'idempotency_key',
        'huella_solicitud',
        'registrado_por_admin_id',
        'revisado_por_admin_id',
        'revisado_en',
        'motivo_revision',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'monto' => 'decimal:4',
        'fecha_pago' => 'date',
        'estado' => EstadoPago::class,
        'registrado_por_admin_id' => 'integer',
        'revisado_por_admin_id' => 'integer',
        'revisado_en' => 'datetime',
    ];

    protected $attributes = [
        'estado' => 'sin_revisar',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(CustomerProxy::modelClass(), 'customer_id');
    }

    public function aplicaciones(): HasMany
    {
        return $this->hasMany(AplicacionPago::class, 'pago_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(PagoEvento::class, 'pago_id');
    }

    public function scopeEnEstado(Builder $consulta, EstadoPago ...$estados): Builder
    {
        return $consulta->whereIn('estado', array_map(fn (EstadoPago $e) => $e->value, $estados));
    }

    public function puedeTransicionarA(EstadoPago $destino): bool
    {
        return $this->estado->puedeTransicionarA($destino);
    }
}
