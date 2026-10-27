<?php

namespace ByLopez\CorteConfeccion\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Evento de auditoria de la cuenta de un prestador. APPEND-ONLY: se escribe solo desde los servicios y no
 * admite update ni delete a traves del modelo.
 */
class CuentaEvento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'bylopez_cc_cuenta_eventos';

    protected $fillable = [
        'taller_id',
        'cortador_id',
        'orden_confeccion_id',
        'orden_corte_id',
        'tarifa_prestador_id',
        'tipo',
        'actor_admin_id',
        'motivo',
        'datos',
    ];

    protected $casts = [
        'datos' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Los eventos de cuenta no se modifican.'));
        static::deleting(fn () => throw new LogicException('Los eventos de cuenta no se eliminan.'));
    }
}
