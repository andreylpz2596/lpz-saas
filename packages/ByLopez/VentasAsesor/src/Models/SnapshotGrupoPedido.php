<?php

namespace ByLopez\VentasAsesor\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Snapshot comercial del grupo con el que se vendio un pedido (GRP-01). Se crea una vez con el pedido y NO se edita ni se
 * borra. Alcance honesto: eventos de Eloquent; SQL directo los salta (solo escribe RegistrarSnapshotGrupoPedido).
 */
class SnapshotGrupoPedido extends Model
{
    protected $table = 'bylopez_va_pedido_grupo_snapshots';

    protected $fillable = [
        'order_id', 'customer_id', 'borrador_id',
        'grupo_original_id', 'grupo_original_nombre', 'grupo_original_codigo',
        'grupo_efectivo_id', 'grupo_efectivo_nombre', 'grupo_efectivo_codigo',
        'grupo_override',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'customer_id' => 'integer',
        'borrador_id' => 'integer',
        'grupo_original_id' => 'integer',
        'grupo_efectivo_id' => 'integer',
        'grupo_override' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El snapshot de grupo de un pedido es inmutable.'));
        static::deleting(fn () => throw new LogicException('El snapshot de grupo de un pedido no se borra.'));
    }
}
