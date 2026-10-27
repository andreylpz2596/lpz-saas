<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hecho posterior que afecta una linea YA incluida en una liquidacion (Doc. 23 §10.3). Mientras esta ABIERTA, la parte
 * no pagada de la linea queda retenida: ningun abono nuevo se le aplica. No descuenta ni borra nada por si sola; se
 * resuelve con una decision humana auditada. No se borra.
 */
class ComisionIncidencia extends Model
{
    public const ABIERTA = 'abierta';

    public const RESUELTA = 'resuelta';

    public const PERDIO_CONDICIONES = 'perdio_condiciones';

    /** Resolucion disponible hoy: la comision se mantiene (el hecho no la afecta). Las demas dependen de D5/D6. */
    public const RESOLUCION_MANTENER = 'mantener';

    /** Cierre tecnico: la liquidacion se anulo (sin abonos netos) y la linea quedo libre. */
    public const RESOLUCION_LIQUIDACION_ANULADA = 'liquidacion_anulada';

    protected $table = 'bylopez_va_comision_incidencias';

    protected $fillable = [
        'linea_id', 'liquidacion_id', 'order_id', 'asesor_admin_id', 'tipo', 'detalle', 'estado', 'ajuste_id', 'abierta_clave',
        'resolucion', 'motivo_resolucion', 'resuelta_por_admin_id', 'resuelta_por_name_snapshot', 'resuelta_en',
    ];

    protected $casts = [
        'linea_id' => 'integer',
        'liquidacion_id' => 'integer',
        'order_id' => 'integer',
        'asesor_admin_id' => 'integer',
        'detalle' => 'array',
        'ajuste_id' => 'integer',
        'resuelta_en' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $i) {
            if ($i->isDirty(['linea_id', 'liquidacion_id', 'order_id', 'asesor_admin_id', 'tipo'])) {
                throw new Invalida(Invalida::CAMPO_INMUTABLE, 'Una incidencia no cambia de linea ni de tipo.');
            }

            if ($i->getOriginal('estado') === self::RESUELTA) {
                throw new Invalida(Invalida::ESTADO_INVALIDO, 'La incidencia ya fue resuelta.');
            }
        });

        static::deleting(fn () => throw new Invalida(Invalida::BORRADO_PROHIBIDO, 'Una incidencia no se borra.'));
    }

    public static function claveAbierta(int $lineaId, string $tipo): string
    {
        return $lineaId.'|'.$tipo;
    }

    public function linea(): BelongsTo
    {
        return $this->belongsTo(ComisionLinea::class, 'linea_id');
    }
}
