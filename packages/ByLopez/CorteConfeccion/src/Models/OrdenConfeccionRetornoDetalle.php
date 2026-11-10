<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Contracts\OrdenConfeccionRetornoDetalle as OrdenConfeccionRetornoDetalleContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrdenConfeccionRetornoDetalle extends Model implements OrdenConfeccionRetornoDetalleContract
{
    protected $table = 'bylopez_cc_ordenes_confeccion_retorno_detalles';

    protected $fillable = [
        'retorno_id',
        'orden_confeccion_detalle_id',
        'product_id',
        'producto_sku',
        'producto_nombre',
        'talla',
        'cantidad_reportada',
        'cantidad_urgente',
        'cantidad_alta',
        'cantidad_recibida',
        'cantidad_defectuosa',
        'cantidad_faltante',
        'observacion_bodega',
    ];

    protected $casts = [
        'cantidad_reportada' => 'integer',
        'cantidad_recibida' => 'integer',
        'cantidad_defectuosa' => 'integer',
        'cantidad_faltante' => 'integer',
    ];

    public function retorno(): BelongsTo
    {
        return $this->belongsTo(OrdenConfeccionRetorno::class, 'retorno_id');
    }

    public function ordenConfeccionDetalle(): BelongsTo
    {
        return $this->belongsTo(OrdenConfeccionDetalle::class, 'orden_confeccion_detalle_id');
    }

    public function movimientosInventario(): HasMany
    {
        return $this->hasMany(InventarioMovimiento::class, 'retorno_detalle_id');
    }
}
