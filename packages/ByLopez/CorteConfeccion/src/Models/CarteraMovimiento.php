<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Enums\TipoMovimientoCartera;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Registro unico del dinero de la cartera (abono, prestamo, devolucion o saldo inicial). Nunca se edita ni se borra:
 * se reversa con motivo (estado = reversado). Ver migracion 2026_11_01_000001.
 */
class CarteraMovimiento extends Model
{
    protected $table = 'bylopez_cc_cartera_movimientos';

    protected $fillable = [
        'codigo', 'tipo', 'taller_id', 'cortador_id', 'valor', 'fecha_efectiva', 'medio', 'referencia', 'observacion',
        'modo_detalle', 'num_transferencias', 'prestamo_id', 'estado', 'registrado_por', 'reversado_en', 'reversado_por',
        'motivo_reverso', 'idempotency_key', 'payload_hash',
    ];

    protected $casts = [
        'fecha_efectiva' => 'date',
        'reversado_en' => 'datetime',
        'num_transferencias' => 'integer',
    ];

    public function tipoEnum(): ?TipoMovimientoCartera
    {
        return TipoMovimientoCartera::tryFrom((string) $this->tipo);
    }

    public function estaVigente(): bool
    {
        return $this->estado === 'registrado';
    }

    public function operaciones(): HasMany
    {
        return $this->hasMany(CarteraOperacion::class, 'movimiento_id');
    }

    public function aplicaciones(): HasMany
    {
        return $this->hasMany(CarteraAplicacion::class, 'movimiento_id');
    }

    public function soportes(): HasMany
    {
        return $this->hasMany(CarteraSoporte::class, 'movimiento_id');
    }

    public function taller(): BelongsTo
    {
        return $this->belongsTo(Taller::class, 'taller_id');
    }

    public function cortador(): BelongsTo
    {
        return $this->belongsTo(Cortador::class, 'cortador_id');
    }
}
