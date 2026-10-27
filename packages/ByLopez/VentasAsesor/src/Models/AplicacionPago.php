<?php

namespace ByLopez\VentasAsesor\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Sales\Models\OrderProxy;
use Webkul\Sales\Models\OrderTransactionProxy;

/**
 * Que parte de un pago cubre que pedido (D-59, Doc. 05 §42.2).
 *
 * - ACTIVA = `anulada_en` NULL. Rechazar o reversar un pago anula sus aplicaciones; nunca se borran (I-3).
 * - `order_transaction_id` es el VINCULO que distingue una Transaction nativa creada por ByLopez de una
 *   «ajena» (I-5): una Transaction vinculada no vuelve a descontarse del saldo (Doc. 05 §42.3).
 * - Sin `updated_at`: solo `created_at`.
 */
class AplicacionPago extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'bylopez_va_aplicaciones_pago';

    protected $fillable = [
        'pago_id',
        'order_id',
        'invoice_id',
        'monto',
        'posicion',
        'anulada_en',
        'anulada_por_admin_id',
        'order_transaction_id',
    ];

    protected $casts = [
        'pago_id' => 'integer',
        'order_id' => 'integer',
        'invoice_id' => 'integer',
        'monto' => 'decimal:4',
        'posicion' => 'integer',
        'anulada_en' => 'datetime',
        'anulada_por_admin_id' => 'integer',
        'order_transaction_id' => 'integer',
    ];

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(OrderProxy::modelClass(), 'order_id');
    }

    public function transaccion(): BelongsTo
    {
        return $this->belongsTo(OrderTransactionProxy::modelClass(), 'order_transaction_id');
    }

    /** Aplicaciones que cuentan (no anuladas). El saldo ademas exige que su pago cuente (`EstadoPago`). */
    public function scopeActivas(Builder $consulta): Builder
    {
        return $consulta->whereNull('anulada_en');
    }

    public function estaActiva(): bool
    {
        return $this->anulada_en === null;
    }
}
