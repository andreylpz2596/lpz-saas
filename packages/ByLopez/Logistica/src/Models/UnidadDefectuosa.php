<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Unidades DEFECTUOSAS recibidas en bodega: inventario fisico NO VENDIBLE de ByLopez (no suma al stock vendible ni se pierde).
 * Las escribe unicamente RecibirDevolucionService, en la misma transaccion de la recepcion. Nunca se borran; el estado
 * (`pendiente_revision` → `recuperada` | `perdida`) lo movera la gestion posterior (fase futura).
 */
class UnidadDefectuosa extends Model
{
    public const PENDIENTE_REVISION = 'pendiente_revision';

    public const RECUPERADA = 'recuperada';

    public const PERDIDA = 'perdida';

    protected $table = 'bylopez_lg_unidades_defectuosas';

    protected $fillable = [
        'recepcion_id', 'recepcion_item_id', 'grupo', 'shipment_id', 'order_id', 'order_item_id', 'product_id', 'inventory_source_id',
        'cantidad', 'motivo_codigo', 'origen_codigo', 'detalle', 'observacion', 'estado', 'actor_admin_id', 'actor_name_snapshot',
    ];

    protected $casts = [
        'recepcion_id' => 'integer', 'recepcion_item_id' => 'integer', 'grupo' => 'integer', 'shipment_id' => 'integer',
        'order_id' => 'integer', 'order_item_id' => 'integer', 'product_id' => 'integer', 'inventory_source_id' => 'integer',
        'cantidad' => 'integer', 'actor_admin_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleting(fn (self $u) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['defectuosa' => $u->getKey()]));
    }

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionDevolucion::class, 'recepcion_id');
    }
}
