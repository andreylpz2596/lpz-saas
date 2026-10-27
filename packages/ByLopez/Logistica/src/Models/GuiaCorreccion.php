<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;

/** Historial de correcciones de una guia. SOLO DE ADICION: no se edita ni se borra. Solo lo escribe CorregirGuiaService. */
class GuiaCorreccion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'bylopez_lg_guia_correcciones';

    protected $fillable = [
        'guia_id', 'order_id', 'shipment_id', 'numero_anterior', 'numero_nuevo', 'transportadora_anterior',
        'transportadora_nueva', 'motivo', 'actor_admin_id', 'actor_name_snapshot',
    ];

    protected $casts = [
        'guia_id' => 'integer',
        'order_id' => 'integer',
        'shipment_id' => 'integer',
        'actor_admin_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn (self $c) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['correccion' => $c->getKey()]));
        static::deleting(fn (self $c) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['correccion' => $c->getKey()]));
    }
}
