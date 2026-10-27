<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;

/**
 * A que linea (o ajuste positivo) se aplico cada abono, reverso o compensacion aprobada de una liquidacion. Libro
 * append-only: Σ aplicaciones de un abono = su monto; un reverso replica con signo − las del abono que revierte.
 */
class ComisionAplicacion extends Model
{
    public const ABONO = 'abono';

    public const REVERSO = 'reverso';

    public const COMPENSACION = 'compensacion';

    public const UPDATED_AT = null;

    protected $table = 'bylopez_va_comision_aplicaciones';

    protected $fillable = ['liquidacion_id', 'abono_id', 'compensa_ajuste_id', 'linea_id', 'ajuste_id', 'tipo', 'monto'];

    protected $casts = [
        'liquidacion_id' => 'integer',
        'abono_id' => 'integer',
        'compensa_ajuste_id' => 'integer',
        'linea_id' => 'integer',
        'ajuste_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new Invalida(Invalida::CAMPO_INMUTABLE, 'Una aplicacion no se modifica.'));
        static::deleting(fn () => throw new Invalida(Invalida::BORRADO_PROHIBIDO, 'Una aplicacion no se borra.'));
    }
}
