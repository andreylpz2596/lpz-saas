<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Contracts\OrdenCorteDetalle as OrdenCorteDetalleContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\ProductProxy;

class OrdenCorteDetalle extends Model implements OrdenCorteDetalleContract
{
    protected $table = 'bylopez_cc_ordenes_corte_detalles';

    protected $fillable = [
        'orden_corte_id',
        'product_id',
        'attribute_family_id',
        'variant_id',
        'producto_sku',
        'producto_nombre',
        'genero_linea',
        'color',
        'talla',
        'cantidad_proyectada',
        'cantidad_real_cortada',
        'es_adicional',
        'observacion',
    ];

    /** H07: conserva la familia (referencia) del producto al crear el detalle; ver migracion 2026_10_29_000002. */
    protected static function booted(): void
    {
        static::creating(function (self $detalle) {
            if ($detalle->product_id && ! $detalle->attribute_family_id) {
                $detalle->attribute_family_id = DB::table('products')->where('id', $detalle->product_id)->value('attribute_family_id');
            }
        });
    }

    protected $casts = [
        'cantidad_proyectada' => 'integer',
        'cantidad_real_cortada' => 'integer',
        'es_adicional' => 'boolean',
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
