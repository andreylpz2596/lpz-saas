<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Models\Concerns\ItemSoloAdicion;
use Illuminate\Database\Eloquent\Model;

/** Unidades entregadas de un `shipment_item` (y su `order_item`, el ancla que leera COM-04). */
class EntregaItem extends Model
{
    use ItemSoloAdicion;

    public const UPDATED_AT = null;

    protected $table = 'bylopez_lg_entrega_items';

    protected $fillable = ['entrega_id', 'shipment_item_id', 'order_item_id', 'cantidad'];

    protected $casts = [
        'entrega_id' => 'integer',
        'shipment_item_id' => 'integer',
        'order_item_id' => 'integer',
        'cantidad' => 'integer',
    ];
}
