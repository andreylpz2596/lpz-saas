<?php

namespace ByLopez\CorteConfeccion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\User\Models\Admin;

/** Un cambio de prioridad confirmado (una o varias cantidades del mismo taller). Inmutable: es historial. */
class PrioridadOperacion extends Model
{
    public const NOTIFICADO = 'notificado';

    public const SIN_DESTINATARIO = 'sin_destinatario';

    protected $table = 'bylopez_cc_prioridad_operaciones';

    protected $fillable = [
        'idempotency_key', 'payload_hash', 'taller_id', 'usuario_id', 'nota', 'notificacion_estado', 'destinatario_id',
    ];

    public function cambios(): HasMany
    {
        return $this->hasMany(PrioridadCambio::class, 'operacion_id');
    }

    public function taller(): BelongsTo
    {
        return $this->belongsTo(Taller::class, 'taller_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'usuario_id');
    }

    public function destinatario(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'destinatario_id');
    }
}
