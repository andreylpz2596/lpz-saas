<?php

namespace ByLopez\CorteConfeccion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una cantidad de una variante en una orden que paso de una prioridad a otra. */
class PrioridadCambio extends Model
{
    protected $table = 'bylopez_cc_prioridad_cambios';

    protected $fillable = [
        'operacion_id', 'orden_confeccion_id', 'orden_confeccion_detalle_id', 'product_id', 'cantidad', 'prioridad_anterior', 'prioridad_nueva',
    ];

    protected $casts = [
        'cantidad' => 'integer',
    ];

    public function operacion(): BelongsTo
    {
        return $this->belongsTo(PrioridadOperacion::class, 'operacion_id');
    }

    public function ordenConfeccion(): BelongsTo
    {
        return $this->belongsTo(OrdenConfeccion::class, 'orden_confeccion_id');
    }

    public function detalle(): BelongsTo
    {
        return $this->belongsTo(OrdenConfeccionDetalle::class, 'orden_confeccion_detalle_id');
    }
}
