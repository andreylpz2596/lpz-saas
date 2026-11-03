<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;

/** Pago (abono) registrado a una liquidacion, o su reverso. Inmutable: no se edita ni se borra. */
class ComisionAbono extends Model
{
    public const ABONO = 'abono';

    public const REVERSO = 'reverso';

    protected $table = 'bylopez_va_comision_abonos';

    protected $fillable = [
        'liquidacion_id', 'asesor_admin_id', 'tipo', 'monto', 'revierte_id', 'fecha_pago', 'medio', 'referencia', 'observacion',
        'comprobante_disco', 'comprobante_ruta', 'comprobante_nombre', 'comprobante_mime', 'comprobante_sha256',
        'soporte_id', 'operacion_huella', 'operacion_referencia', 'operacion_valor', 'lectura_detectada', 'lectura_corregida',
        'idempotency_key', 'actor_admin_id', 'actor_name_snapshot',
    ];

    protected $casts = [
        'liquidacion_id' => 'integer',
        'asesor_admin_id' => 'integer',
        'revierte_id' => 'integer',
        'fecha_pago' => 'date',
        'soporte_id' => 'integer',
        'lectura_detectada' => 'array',
        'lectura_corregida' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new Invalida(Invalida::CAMPO_INMUTABLE, 'Un abono no se modifica: se revierte.'));
        static::deleting(fn () => throw new Invalida(Invalida::BORRADO_PROHIBIDO, 'Un abono no se borra: se revierte.'));
    }
}
