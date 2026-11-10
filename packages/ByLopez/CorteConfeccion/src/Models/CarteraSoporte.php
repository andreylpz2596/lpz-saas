<?php

namespace ByLopez\CorteConfeccion\Models;

use Illuminate\Database\Eloquent\Model;

/** Comprobante adjunto (archivo privado). La lectura asistida solo deja un borrador en `lectura_resultado`. */
class CarteraSoporte extends Model
{
    protected $table = 'bylopez_cc_cartera_soportes';

    protected $fillable = [
        'taller_id', 'cortador_id', 'movimiento_id', 'ruta', 'nombre_original', 'mime', 'tamano', 'sha256',
        'lectura_estado', 'lectura_proveedor', 'lectura_resultado', 'lectura_error', 'subido_por',
    ];

    protected $casts = [
        'lectura_resultado' => 'array',
    ];
}
