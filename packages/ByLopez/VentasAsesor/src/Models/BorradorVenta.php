<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Contracts\BorradorVenta as BorradorVentaContract;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoBorradorVenta;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Checkout\Models\CartProxy;
use Webkul\Sales\Models\OrderProxy;

/**
 * Borrador comercial de venta (03B): control de negocio alrededor de un Cart de
 * Bagisto. El cliente, las lineas y los totales se leen del carrito, nunca de aqui.
 */
class BorradorVenta extends Model implements BorradorVentaContract
{
    protected $table = 'bylopez_va_borradores_venta';

    protected $fillable = [
        'cart_id',
        'propietario_admin_id',
        'creado_por_admin_id',
        'estado',
        'canal_venta',
        'mecanismo_creacion',
        'external_reference',
        'asignado_hasta',
        'order_id',
    ];

    protected $casts = [
        'cart_id' => 'integer',
        'propietario_admin_id' => 'integer',
        'creado_por_admin_id' => 'integer',
        'estado' => EstadoBorradorVenta::class,
        'canal_venta' => CanalVenta::class,
        'mecanismo_creacion' => MecanismoCreacion::class,
        'asignado_hasta' => 'datetime',
        'order_id' => 'integer',
    ];

    public function carrito(): BelongsTo
    {
        return $this->belongsTo(CartProxy::modelClass(), 'cart_id');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(OrderProxy::modelClass(), 'order_id');
    }
}
