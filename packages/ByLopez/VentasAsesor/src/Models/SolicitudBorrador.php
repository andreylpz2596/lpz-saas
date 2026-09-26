<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Contracts\SolicitudBorrador as SolicitudBorradorContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Models\ProductProxy;

/**
 * Solicitud comercial del borrador (03C, D-39): cuanto quiere el cliente de una variante.
 * No es stock, ni reserva, ni precio: nombre, SKU, stock y precio se leen en tiempo real.
 */
class SolicitudBorrador extends Model implements SolicitudBorradorContract
{
    protected $table = 'bylopez_va_borrador_solicitudes';

    protected $fillable = [
        'borrador_id',
        'variante_id',
        'cantidad_solicitada',
    ];

    protected $casts = [
        'borrador_id' => 'integer',
        'variante_id' => 'integer',
        'cantidad_solicitada' => 'integer',
    ];

    public function borrador(): BelongsTo
    {
        return $this->belongsTo(BorradorVenta::class, 'borrador_id');
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'variante_id');
    }
}
