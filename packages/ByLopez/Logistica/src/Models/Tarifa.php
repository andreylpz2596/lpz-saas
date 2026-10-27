<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;

/**
 * Tarifa SUGERIDA de una transportadora en una zona, vigente desde una fecha. Inmutable: un cambio de tarifa es una fila
 * nueva, asi el historial queda completo y los despachos anteriores (que guardan su propio snapshot) no cambian.
 */
class Tarifa extends Model
{
    protected $table = 'bylopez_lg_tarifas';

    protected $fillable = ['transportadora_id', 'zona_id', 'valor', 'vigente_desde', 'creado_por_admin_id', 'creado_por_name_snapshot'];

    protected $casts = [
        'transportadora_id' => 'integer',
        'zona_id' => 'integer',
        'valor' => 'decimal:2',
        'vigente_desde' => 'date',
    ];

    protected static function booted(): void
    {
        static::updating(fn (self $t) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['tarifa' => $t->getKey()]));
        static::deleting(fn (self $t) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['tarifa' => $t->getKey()]));
    }
}
