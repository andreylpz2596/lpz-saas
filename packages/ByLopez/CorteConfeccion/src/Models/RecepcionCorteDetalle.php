<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Contracts\RecepcionCorteDetalle as RecepcionCorteDetalleContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Models\ProductProxy;

class RecepcionCorteDetalle extends Model implements RecepcionCorteDetalleContract
{
    protected $table = 'bylopez_cc_recepciones_corte_detalles';

    protected $fillable = [
        'recepcion_corte_id',
        'product_id',
        'producto_sku',
        'producto_nombre',
        'talla',
        'cantidad_recibida',
        'cantidad_defectuosa',
        'observacion',
    ];

    public function recepcionCorte(): BelongsTo
    {
        return $this->belongsTo(RecepcionCorte::class, 'recepcion_corte_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }
}
