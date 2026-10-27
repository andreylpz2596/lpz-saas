<?php

namespace ByLopez\Logistica\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Domiciliario elegido por el asesor en la venta para un pedido (D1). Preferencia del despacho, no asignacion. */
class PreferenciaEntrega extends Model
{
    public const ORIGEN_VENTA = 'venta';

    protected $table = 'bylopez_lg_preferencias_entrega';

    protected $fillable = ['order_id', 'transportadora_id', 'origen', 'seleccionado_por_admin_id'];

    protected $casts = [
        'order_id' => 'integer',
        'transportadora_id' => 'integer',
        'seleccionado_por_admin_id' => 'integer',
    ];

    public function transportadora(): BelongsTo
    {
        return $this->belongsTo(Transportadora::class);
    }
}
