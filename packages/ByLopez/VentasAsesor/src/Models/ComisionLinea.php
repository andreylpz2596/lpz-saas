<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Linea de comision por item padre de un pedido de asesor (R3). Las FUENTES (asesor, acuerdo, %, mes, precio, costo,
 * cantidad vendida) no cambian nunca. Transiciones: estimada → habilitada → (en liquidacion); estimada | habilitada sin
 * liquidacion → anulada; habilitada sin liquidacion → estimada (deshabilitada, con evento). No se borra. Solo escriben
 * los servicios de comisiones.
 */
class ComisionLinea extends Model
{
    public const ESTIMADA = 'estimada';

    public const HABILITADA = 'habilitada';

    public const ANULADA = 'anulada';

    public const FUENTES = [
        'order_id', 'order_item_id', 'asesor_admin_id', 'acuerdo_id', 'porcentaje', 'mes_comercial', 'confirmado_en',
        'familia_id', 'sku', 'cantidad_vendida', 'precio_neto_unitario', 'costo_unitario', 'base_unitaria',
    ];

    protected $table = 'bylopez_va_comision_lineas';

    protected $fillable = [
        'order_id', 'order_item_id', 'asesor_admin_id', 'asesor_name_snapshot', 'acuerdo_id', 'porcentaje', 'mes_comercial',
        'confirmado_en', 'familia_id', 'referencia', 'sku', 'cantidad_vendida', 'precio_neto_unitario', 'costo_unitario',
        'base_unitaria', 'estado', 'unidades_comisionables', 'monto', 'habilitada_en', 'evidencia', 'alerta',
        'liquidacion_id', 'anulada_en', 'motivo_anulacion',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'order_item_id' => 'integer',
        'asesor_admin_id' => 'integer',
        'acuerdo_id' => 'integer',
        'confirmado_en' => 'datetime',
        'familia_id' => 'integer',
        'cantidad_vendida' => 'integer',
        'unidades_comisionables' => 'integer',
        'habilitada_en' => 'datetime',
        'evidencia' => 'array',
        'liquidacion_id' => 'integer',
        'anulada_en' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $l) {
            foreach (self::FUENTES as $campo) {
                if ($l->isDirty($campo)) {
                    throw new Invalida(Invalida::CAMPO_INMUTABLE, "La fuente «{$campo}» de una linea de comision no cambia.");
                }
            }

            if ($l->getOriginal('estado') === self::ANULADA) {
                throw new Invalida(Invalida::ESTADO_INVALIDO, 'Una linea anulada no cambia.');
            }

            if ($l->getOriginal('liquidacion_id') !== null && $l->liquidacion_id !== null && $l->isDirty(['monto', 'unidades_comisionables', 'estado'])) {
                throw new Invalida(Invalida::LINEA_COMPROMETIDA, 'La linea esta en una liquidacion: se corrige con un ajuste.');
            }
        });

        static::deleting(fn () => throw new Invalida(Invalida::BORRADO_PROHIBIDO, 'Una linea de comision no se borra.'));
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(ComisionEvento::class, 'linea_id')->orderBy('id');
    }
}
