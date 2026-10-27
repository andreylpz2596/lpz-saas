<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Enums\TipoOperacionPedido;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Clasificacion comercial de un pedido (G-11 / COM-D-15). Se crea una vez con el pedido y NO se edita ni se borra.
 * Alcance honesto: eventos de Eloquent; SQL directo los salta (solo escribe ClasificarOperacionPedido).
 */
class ClasificacionPedido extends Model
{
    public const ORIGEN_NUEVA_VENTA = 'nueva_venta';

    protected $table = 'bylopez_va_clasificaciones_pedido';

    protected $fillable = ['order_id', 'tipo_operacion', 'origen', 'borrador_id', 'actor_admin_id', 'actor_nombre_snapshot'];

    protected $casts = [
        'order_id' => 'integer',
        'tipo_operacion' => TipoOperacionPedido::class,
        'borrador_id' => 'integer',
        'actor_admin_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La clasificacion comercial de un pedido es inmutable.'));
        static::deleting(fn () => throw new LogicException('La clasificacion comercial de un pedido no se borra.'));
    }
}
