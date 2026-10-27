<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Enums\FuenteCostoVenta;
use ByLopez\VentasAsesor\Exceptions\CostoVentaInmutableException;
use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot del costo unitario de una linea vendida (COM-01). Una fila por `order_item_id` padre.
 *
 * INMUTABLE: rechaza `update()`, `delete()` y `save()` sobre una fila existente. Alcance honesto (igual que el
 * historial de pagos): son eventos de Eloquent; SQL directo los salta. Solo escribe `CapturarCostoVentaService`.
 *
 * `autoridad_comision = false` significa: costo CAPTURADO como referencia, NO autorizado para causar ni pagar
 * comisiones (COM-D-02).
 */
class CostoVenta extends Model
{
    protected $table = 'bylopez_va_costos_venta';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'variante_order_item_id',
        'product_id',
        'variant_product_id',
        'sku_snapshot',
        'tipo_producto',
        'costo_unitario',
        'moneda',
        'fuente',
        'fuente_referencia',
        'autoridad_comision',
        'capturado_en',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'order_item_id' => 'integer',
        'variante_order_item_id' => 'integer',
        'product_id' => 'integer',
        'variant_product_id' => 'integer',
        'costo_unitario' => 'decimal:4',
        'fuente' => FuenteCostoVenta::class,
        'autoridad_comision' => 'boolean',
        'capturado_en' => 'datetime',
    ];

    protected static function booted(): void
    {
        // `save()` sobre una fila existente SIN cambios no dispara `updating`: se bloquea aqui tambien.
        static::saving(function (self $costo) {
            if ($costo->exists) {
                throw new CostoVentaInmutableException('El snapshot de costo de una venta no se puede modificar.');
            }
        });

        static::updating(function () {
            throw new CostoVentaInmutableException('El snapshot de costo de una venta no se puede modificar.');
        });

        static::deleting(function () {
            throw new CostoVentaInmutableException('El snapshot de costo de una venta no se puede borrar.');
        });
    }

    public function tieneCosto(): bool
    {
        return $this->costo_unitario !== null;
    }
}
