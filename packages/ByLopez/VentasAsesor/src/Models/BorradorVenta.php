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
        'grupo_original_id',
        'grupo_efectivo_id',
        'grupo_override',
        'grupo_override_customer_id',
        'grupo_eventos',
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
        'confirmacion_iniciada_en' => 'datetime',
        'transportadora_id' => 'integer',
        'grupo_original_id' => 'integer',
        'grupo_efectivo_id' => 'integer',
        'grupo_override' => 'boolean',
        'grupo_override_customer_id' => 'integer',
        'grupo_eventos' => 'integer',
    ];

    /**
     * 03F (D-55): `confirmation_token` y `confirmacion_iniciada_en` NO son asignables en
     * masa. Solo los escribe ConfirmarVentaService con UPDATE condicionados por el token.
     */
    protected $hidden = ['confirmation_token'];

    public function carrito(): BelongsTo
    {
        return $this->belongsTo(CartProxy::modelClass(), 'cart_id');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(OrderProxy::modelClass(), 'order_id');
    }
}
