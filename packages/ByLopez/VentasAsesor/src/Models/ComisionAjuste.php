<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;

/** Ajuste firmado de comision. Nace pendiente de aprobacion; una vez resuelto solo puede entrar o salir de una liquidacion. */
class ComisionAjuste extends Model
{
    public const PENDIENTE = 'pendiente_aprobacion';

    public const APROBADO = 'aprobado';

    public const RECHAZADO = 'rechazado';

    public const ORIGENES = ['manual', 'anulacion_posterior', 'reasignacion_posterior', 'devolucion_posterior'];

    protected $table = 'bylopez_va_comision_ajustes';

    protected $fillable = [
        'asesor_admin_id', 'linea_id', 'order_id', 'monto', 'origen', 'motivo', 'estado', 'liquidacion_id', 'idempotency_key',
        'creado_por_admin_id', 'creado_por_name_snapshot', 'resuelto_por_admin_id', 'resuelto_por_name_snapshot',
        'resuelto_en', 'motivo_resolucion',
    ];

    protected $casts = [
        'asesor_admin_id' => 'integer',
        'linea_id' => 'integer',
        'order_id' => 'integer',
        'liquidacion_id' => 'integer',
        'resuelto_en' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $a) {
            if ($a->isDirty(['asesor_admin_id', 'linea_id', 'order_id', 'monto', 'origen', 'motivo'])) {
                throw new Invalida(Invalida::CAMPO_INMUTABLE, 'Un ajuste no se modifica: registre otro.');
            }

            if ($a->getOriginal('estado') !== self::PENDIENTE && $a->isDirty('estado')) {
                throw new Invalida(Invalida::ESTADO_INVALIDO, 'El ajuste ya fue resuelto.');
            }
        });

        static::deleting(fn () => throw new Invalida(Invalida::BORRADO_PROHIBIDO, 'Un ajuste no se borra.'));
    }
}
