<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Enums\TipoEventoPago;
use ByLopez\VentasAsesor\Exceptions\PagoEventoInmutableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento del historial de un pago (D-59, Doc. 05 §42.2, invariante I-9). SOLO DE ADICION: rechaza `update()`,
 * `delete()` y `save()` sobre una fila existente.
 *
 * Alcance honesto de la garantia (igual que el historial de atribucion): son eventos de Eloquent. Una consulta
 * masiva por Query Builder o SQL directo los salta, y no hay disparadores de base de datos. Regla del modulo:
 * los eventos solo se escriben por el servicio de pagos, nunca por consultas masivas.
 *
 * PAG-B solo define el modelo; todavia ningun servicio escribe eventos.
 */
class PagoEvento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'bylopez_va_pagos_eventos';

    protected $fillable = [
        'pago_id',
        'tipo',
        'actor_admin_id',
        'mecanismo',
        'motivo',
        'datos',
    ];

    protected $casts = [
        'pago_id' => 'integer',
        'tipo' => TipoEventoPago::class,
        'actor_admin_id' => 'integer',
        'datos' => 'array',
    ];

    protected static function booted(): void
    {
        // `save()` sobre una fila existente SIN cambios no dispara `updating`: se bloquea aqui tambien.
        static::saving(function (self $evento) {
            if ($evento->exists) {
                throw new PagoEventoInmutableException('Los eventos de un pago son de solo adicion: no se pueden modificar.');
            }
        });

        static::updating(function () {
            throw new PagoEventoInmutableException('Los eventos de un pago son de solo adicion: no se pueden modificar.');
        });

        static::deleting(function () {
            throw new PagoEventoInmutableException('Los eventos de un pago son de solo adicion: no se pueden borrar.');
        });
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }
}
