<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Models\Concerns\ItemSoloAdicion;
use Illuminate\Database\Eloquent\Model;

/** Unidades retornadas por la transportadora de un `shipment_item`. */
class RetornoItem extends Model
{
    use ItemSoloAdicion;

    public const UPDATED_AT = null;

    protected $table = 'bylopez_lg_retorno_items';

    protected $fillable = ['retorno_id', 'shipment_item_id', 'order_item_id', 'cantidad'];

    protected $casts = [
        'retorno_id' => 'integer',
        'shipment_item_id' => 'integer',
        'order_item_id' => 'integer',
        'cantidad' => 'integer',
    ];
}
