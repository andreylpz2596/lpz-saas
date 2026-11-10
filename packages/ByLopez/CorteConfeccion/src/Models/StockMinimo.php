<?php

namespace ByLopez\CorteConfeccion\Models;

use Illuminate\Database\Eloquent\Model;

/** Minimo de stock disponible de UNA variante (product_id simple). Sin fila = sin minimo configurado; 0 es un minimo valido. */
class StockMinimo extends Model
{
    protected $table = 'bylopez_cc_stock_minimos';

    protected $fillable = ['product_id', 'minimo', 'actualizado_por'];

    protected $casts = [
        'product_id' => 'integer',
        'minimo' => 'integer',
    ];
}
