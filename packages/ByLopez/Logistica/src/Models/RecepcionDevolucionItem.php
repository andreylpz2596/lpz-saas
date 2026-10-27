<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Models\Concerns\ItemSoloAdicion;
use Illuminate\Database\Eloquent\Model;

/** Una linea recibida: esperadas / recibidas / aptas / novedad (defectuosas) / faltantes y el movimiento de inventario de las aptas. */
class RecepcionDevolucionItem extends Model
{
    use ItemSoloAdicion;

    public const UPDATED_AT = null;

    public const REINGRESADO = 'reingresado';

    public const SIN_APTAS = 'sin_aptas';

    public const NO_APLICA = 'no_aplica';

    protected $table = 'bylopez_lg_recepcion_devolucion_items';

    protected $fillable = [
        'recepcion_id', 'retorno_item_id', 'shipment_item_id', 'order_item_id', 'product_id', 'esperadas', 'recibidas', 'aptas',
        'novedad', 'faltantes', 'motivo_novedad', 'reingreso_estado', 'movimiento_inventario_id', 'reingresado_en',
        // C5: snapshot historico de lo recibido (ajuste trazable de la obligacion; NULL en recepciones anteriores).
        'valor_unitario', 'valor_devuelto',
    ];

    protected $casts = [
        'esperadas' => 'integer', 'recibidas' => 'integer', 'aptas' => 'integer', 'novedad' => 'integer', 'faltantes' => 'integer',
        'movimiento_inventario_id' => 'integer', 'reingresado_en' => 'datetime',
        'valor_unitario' => 'decimal:4', 'valor_devuelto' => 'decimal:2',
    ];
}
