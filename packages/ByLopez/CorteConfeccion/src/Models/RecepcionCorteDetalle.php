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
        'orden_corte_detalle_id',
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

    /**
     * Nullable: solo las recepciones creadas desde F0 en adelante tienen esta
     * relacion resuelta (ver migracion 2026_09_22_000001_...). Las recepciones
     * historicas quedan en NULL a proposito, sin reescritura retroactiva.
     */
    public function ordenCorteDetalle(): BelongsTo
    {
        return $this->belongsTo(OrdenCorteDetalle::class, 'orden_corte_detalle_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }
}
