<?php

namespace ByLopez\CorteConfeccion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aviso del modulo para UN usuario, mostrado en la campana y el listado de notificaciones de Bagisto.
 * `datos` es la foto historica del cambio; leerla no significa que el trabajo haya comenzado.
 */
class Notificacion extends Model
{
    public const TIPO_PRIORIDAD = 'prioridad_confeccion';

    protected $table = 'bylopez_cc_notificaciones';

    protected $fillable = [
        'admin_id', 'tipo', 'prioridad_operacion_id', 'datos', 'leida_en',
    ];

    protected $casts = [
        'datos' => 'array',
        'leida_en' => 'datetime',
    ];

    public function operacion(): BelongsTo
    {
        return $this->belongsTo(PrioridadOperacion::class, 'prioridad_operacion_id');
    }
}
