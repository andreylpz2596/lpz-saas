<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Enums\TipoTransportadora;
use Illuminate\Database\Eloquent\Model;

/**
 * Catalogo de transportadoras (LOG-D-04). `codigo` es estable y no se edita; las filas se desactivan, no se borran.
 *
 * `tipo` dice QUE CLASE de transportador es; las CAPACIDADES (`liquida_cuenta_corriente`, `genera_guia_interna`) dicen
 * QUE PUEDE HACER y son independientes del tipo. El comportamiento nunca depende del nombre.
 */
class Transportadora extends Model
{
    protected $table = 'bylopez_lg_transportadoras';

    protected $fillable = ['codigo', 'nombre', 'tipo', 'liquida_cuenta_corriente', 'genera_guia_interna', 'activo'];

    protected $casts = [
        'tipo' => TipoTransportadora::class,
        'liquida_cuenta_corriente' => 'boolean',
        'genera_guia_interna' => 'boolean',
        'activo' => 'boolean',
    ];
}
