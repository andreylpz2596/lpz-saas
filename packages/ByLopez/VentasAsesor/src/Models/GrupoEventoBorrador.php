<?php

namespace ByLopez\VentasAsesor\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Evento del historial de grupo comercial de una venta (GRP-01). Una fila por evento: no se edita ni se borra. La unica
 * escritura posterior es completar `order_id` al confirmar la venta, y la hace RegistrarSnapshotGrupoPedido con un UPDATE
 * condicionado (`order_id` nulo) — alcance honesto: los eventos de Eloquent no cubren SQL directo.
 */
class GrupoEventoBorrador extends Model
{
    public const OVERRIDE_APLICADO = 'override_aplicado';

    public const OVERRIDE_CAMBIADO = 'override_cambiado';

    public const RESTAURACION = 'restauracion';

    public const OVERRIDE_CANCELADO_CAMBIO_CLIENTE = 'override_cancelado_cambio_cliente';

    public const ORIGEN_NUEVA_VENTA = 'nueva_venta';

    public const ORIGEN_CAMBIO_CLIENTE = 'cambio_cliente';

    protected $table = 'bylopez_va_borrador_grupo_eventos';

    protected $fillable = [
        'borrador_id', 'cart_id', 'customer_id', 'evento', 'origen',
        'grupo_original_id', 'grupo_original_nombre',
        'grupo_anterior_id', 'grupo_anterior_nombre',
        'grupo_nuevo_id', 'grupo_nuevo_nombre',
        'motivo', 'actor_admin_id', 'actor_nombre_snapshot', 'order_id',
    ];

    protected $casts = [
        'borrador_id' => 'integer',
        'cart_id' => 'integer',
        'customer_id' => 'integer',
        'grupo_original_id' => 'integer',
        'grupo_anterior_id' => 'integer',
        'grupo_nuevo_id' => 'integer',
        'actor_admin_id' => 'integer',
        'order_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial de grupo de una venta es inmutable.'));
        static::deleting(fn () => throw new LogicException('El historial de grupo de una venta no se borra.'));
    }
}
