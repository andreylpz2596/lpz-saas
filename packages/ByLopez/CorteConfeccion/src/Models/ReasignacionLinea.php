<?php

namespace ByLopez\CorteConfeccion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReasignacionLinea extends Model
{
    protected $table = 'bylopez_cc_reasignacion_lineas';

    protected $fillable = [
        'reasignacion_id', 'detalle_origen_id', 'detalle_destino_id', 'product_id',
        'producto_sku', 'producto_nombre', 'talla', 'cantidad', 'cantidad_urgente', 'cantidad_alta', 'cantidad_en_proceso_declarada',
    ];

    protected $casts = [
        'cantidad' => 'integer',
        'cantidad_en_proceso_declarada' => 'integer',
    ];

    public function reasignacion(): BelongsTo
    {
        return $this->belongsTo(Reasignacion::class, 'reasignacion_id');
    }

    public function detalleOrigen(): BelongsTo
    {
        return $this->belongsTo(OrdenConfeccionDetalle::class, 'detalle_origen_id');
    }

    public function detalleDestino(): BelongsTo
    {
        return $this->belongsTo(OrdenConfeccionDetalle::class, 'detalle_destino_id');
    }
}
