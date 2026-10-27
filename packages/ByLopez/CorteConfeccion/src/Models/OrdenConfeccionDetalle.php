<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Contracts\OrdenConfeccionDetalle as OrdenConfeccionDetalleContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Product\Models\ProductProxy;

class OrdenConfeccionDetalle extends Model implements OrdenConfeccionDetalleContract
{
    protected $table = 'bylopez_cc_ordenes_confeccion_detalles';

    protected $fillable = [
        'orden_confeccion_id',
        'product_id',
        'producto_sku',
        'producto_nombre',
        'talla',
        'cantidad_entregada',
        'prioridad',
    ];

    protected $casts = [
        'cantidad_entregada' => 'integer',
    ];

    public function ordenConfeccion(): BelongsTo
    {
        return $this->belongsTo(OrdenConfeccion::class, 'orden_confeccion_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }

    public function retornosDetalles(): HasMany
    {
        return $this->hasMany(OrdenConfeccionRetornoDetalle::class, 'orden_confeccion_detalle_id');
    }
}
