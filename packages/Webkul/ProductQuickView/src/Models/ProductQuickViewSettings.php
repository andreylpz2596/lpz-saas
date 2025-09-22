<?php

namespace Brainstream\ProductQuickView\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductQuickViewSettings extends Model
{
    use HasFactory;

    protected $table = 'product_quickview_settings';
    
    protected $fillable = [
        'show_full_description',
        'show_product_number',
        'show_quantity',
        'show_sku',
    ];
    
    protected $casts = [
        'show_full_description' => 'boolean',
        'show_product_number' => 'boolean',
        'show_quantity' => 'boolean',
        'show_sku' => 'boolean',
    ];
}
