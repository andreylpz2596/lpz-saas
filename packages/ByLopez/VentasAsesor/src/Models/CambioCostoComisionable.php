<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Exceptions\CostoComisionableInvalidoException as Invalido;
use Illuminate\Database\Eloquent\Model;

/**
 * Bitacora de una actualizacion administrativa del costo comisionable (D-COST-05). SOLO DE ADICION: rechaza
 * update, save y delete de filas existentes. Es auditoria; nunca la fuente del costo de una venta (D-COST-06).
 */
class CambioCostoComisionable extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'bylopez_va_costo_comisionable_cambios';

    protected $fillable = [
        'lote', 'product_id', 'sku_snapshot', 'producto_snapshot', 'costo_anterior', 'costo_nuevo',
        'actor_admin_id', 'actor_nombre_snapshot', 'mecanismo', 'motivo',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'costo_anterior' => 'decimal:4',
        'costo_nuevo' => 'decimal:4',
        'actor_admin_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $cambio) {
            if ($cambio->exists) {
                throw new Invalido(Invalido::BITACORA_INMUTABLE, 'La bitacora de costos es de solo adicion.');
            }
        });

        static::updating(fn () => throw new Invalido(Invalido::BITACORA_INMUTABLE, 'La bitacora de costos es de solo adicion.'));
        static::deleting(fn () => throw new Invalido(Invalido::BITACORA_INMUTABLE, 'La bitacora de costos no se borra.'));
    }
}
