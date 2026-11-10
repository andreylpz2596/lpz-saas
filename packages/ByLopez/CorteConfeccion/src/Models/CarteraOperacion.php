<?php

namespace ByLopez\CorteConfeccion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una transferencia individual dentro de un abono con detalle. Ver migracion 2026_11_01_000001. */
class CarteraOperacion extends Model
{
    protected $table = 'bylopez_cc_cartera_operaciones';

    protected $fillable = [
        'movimiento_id', 'soporte_id', 'soporte_pagina', 'valor', 'moneda', 'direccion', 'fecha_operacion', 'hora_operacion',
        'banco_origen', 'banco_destino', 'medio', 'referencia', 'autorizacion', 'contraparte', 'remitente', 'huella', 'huella_activa', 'estado',
        'duplicado_de_id', 'motivo_duplicado',
    ];

    protected $casts = [
        'fecha_operacion' => 'date',
    ];

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(CarteraMovimiento::class, 'movimiento_id');
    }

    public function soporte(): BelongsTo
    {
        return $this->belongsTo(CarteraSoporte::class, 'soporte_id');
    }
}
