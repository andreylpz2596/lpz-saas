<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Contracts\OrdenCorteDetalle as OrdenCorteDetalleContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Models\ProductProxy;

class OrdenCorteDetalle extends Model implements OrdenCorteDetalleContract
{
    protected $table = 'bylopez_cc_ordenes_corte_detalles';

    protected $fillable = [
        'orden_corte_id',
        'product_id',
        'variant_id',
        'producto_sku',
        'producto_nombre',
        'genero_linea',
        'talla',
        'cantidad_proyectada',
        'cantidad_real_cortada',
        'observacion',
    ];

    protected $casts = [
        'cantidad_proyectada'    => 'integer',
        'cantidad_real_cortada'  => 'integer',
    ];

    public function ordenCorte(): BelongsTo
    {
        return $this->belongsTo(OrdenCorte::class, 'orden_corte_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }
}
