<?php

namespace ByLopez\VentasAsesor\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bandeja durable de reevaluacion de comisiones (Doc. 23 §10.2): una fila por pedido. Pendiente mientras
 * `version > version_procesada`. Es un dato tecnico de cola, no historia de negocio (la historia vive en los eventos de linea).
 */
class ComisionReevaluacion extends Model
{
    protected $table = 'bylopez_va_comision_reevaluaciones';

    protected $fillable = ['order_id', 'version', 'version_procesada', 'origen', 'creacion', 'intentos', 'siguiente_intento_en', 'ultimo_error', 'procesada_en'];

    protected $casts = [
        'order_id' => 'integer',
        'creacion' => 'boolean',
        'version' => 'integer',
        'version_procesada' => 'integer',
        'intentos' => 'integer',
        'siguiente_intento_en' => 'datetime',
        'procesada_en' => 'datetime',
    ];

    public function pendiente(): bool
    {
        return $this->version > $this->version_procesada;
    }
}
