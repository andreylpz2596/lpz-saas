<?php

namespace ByLopez\CorteConfeccion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Inventory\Models\InventorySourceProxy;
use Webkul\Product\Models\ProductProxy;
use Webkul\User\Models\Admin;

class InventarioMovimiento extends Model
{
    protected $table = 'bylopez_cc_inventario_movimientos';

    protected $fillable = [
        'product_id',
        'inventory_source_id',
        'tipo_movimiento',
        'cantidad_anterior',
        'cantidad_movimiento',
        'cantidad_nueva',
        'orden_confeccion_id',
        'retorno_id',
        'retorno_detalle_id',
        'admin_id',
        'nombre_usuario_snapshot',
        'observacion',
        'idempotency_key',
        'origen_tipo',
        'origen_id',
    ];

    protected $casts = [
        'cantidad_anterior' => 'integer',
        'cantidad_movimiento' => 'integer',
        'cantidad_nueva' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }

    public function inventorySource(): BelongsTo
    {
        return $this->belongsTo(InventorySourceProxy::modelClass(), 'inventory_source_id');
    }

    public function ordenConfeccion(): BelongsTo
    {
        return $this->belongsTo(OrdenConfeccion::class, 'orden_confeccion_id');
    }

    public function retorno(): BelongsTo
    {
        return $this->belongsTo(OrdenConfeccionRetorno::class, 'retorno_id');
    }

    public function retornoDetalle(): BelongsTo
    {
        return $this->belongsTo(OrdenConfeccionRetornoDetalle::class, 'retorno_detalle_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
