<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;

/** SKU fisico de una incidencia, con las cifras de la solicitud y, una sola vez, las de la resolucion. Nunca se borra. */
class IncidenciaStockLinea extends Model
{
    private const AL_RESOLVER = ['stock_sistema_resolucion', 'conteo_confirmado', 'diferencia', 'movimiento_id', 'updated_at'];

    protected $table = 'bylopez_lg_incidencia_stock_lineas';

    protected $fillable = [
        'incidencia_id', 'order_item_id', 'product_id', 'sku_snapshot', 'cantidad_necesaria', 'stock_sistema_solicitud', 'conteo_declarado',
        'stock_sistema_resolucion', 'conteo_confirmado', 'diferencia', 'movimiento_id',
    ];

    protected $casts = [
        'incidencia_id' => 'integer',
        'order_item_id' => 'integer',
        'product_id' => 'integer',
        'cantidad_necesaria' => 'integer',
        'stock_sistema_solicitud' => 'integer',
        'conteo_declarado' => 'integer',
        'stock_sistema_resolucion' => 'integer',
        'conteo_confirmado' => 'integer',
        'diferencia' => 'integer',
        'movimiento_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $linea) {
            $resuelveUnaVez = $linea->getOriginal('conteo_confirmado') === null
                && array_diff(array_keys($linea->getDirty()), self::AL_RESOLVER) === [];

            if (! $resuelveUnaVez) {
                throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['linea' => $linea->getKey()]);
            }
        });

        static::deleting(fn (self $linea) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['linea' => $linea->getKey()]));
    }
}
