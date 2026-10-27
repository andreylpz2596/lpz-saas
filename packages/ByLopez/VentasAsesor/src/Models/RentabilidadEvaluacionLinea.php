<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;

/** Linea INMUTABLE de la evaluacion de rentabilidad (una por item padre del pedido). */
class RentabilidadEvaluacionLinea extends Model
{
    protected $table = 'bylopez_va_rentabilidad_evaluacion_lineas';

    protected $fillable = [
        'evaluacion_id', 'order_item_id', 'familia_id', 'tipo_tela_id', 'referencia', 'sku', 'cantidad', 'precio_neto_unitario',
        'costo_unitario', 'estado_costo', 'politica_id', 'ganancia_requerida_unitaria', 'variable_unitario', 'cargo_pct',
        'comision_unitaria', 'contribucion_unitaria', 'precio_minimo', 'resultado',
    ];

    protected $casts = [
        'evaluacion_id' => 'integer',
        'order_item_id' => 'integer',
        'familia_id' => 'integer',
        'cantidad' => 'integer',
        'politica_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new Invalida(Invalida::CAMPO_INMUTABLE, 'La evaluacion de un pedido no se modifica.'));
        static::deleting(fn () => throw new Invalida(Invalida::BORRADO_PROHIBIDO, 'La evaluacion de un pedido no se borra.'));
    }
}
