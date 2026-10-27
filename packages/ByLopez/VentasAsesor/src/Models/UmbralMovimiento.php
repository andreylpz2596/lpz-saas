<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;

/** Movimiento del libro de unidades computables por asesor y mes (append-only; acumulado = Σ unidades). */
class UmbralMovimiento extends Model
{
    public const UPDATED_AT = null;

    public const VENTA = 'venta';

    public const CANCELACION = 'cancelacion';

    public const NO_ENTREGADA = 'no_entregada';

    public const REVERSO_REASIGNACION = 'reverso_reasignacion';

    public const CORRECCION = 'correccion';

    protected $table = 'bylopez_va_umbral_movimientos';

    protected $fillable = ['asesor_admin_id', 'mes', 'order_id', 'order_item_id', 'linea_id', 'tipo', 'unidades', 'confirmado_en', 'idempotency_key'];

    protected $casts = [
        'asesor_admin_id' => 'integer',
        'order_id' => 'integer',
        'order_item_id' => 'integer',
        'linea_id' => 'integer',
        'unidades' => 'integer',
        'confirmado_en' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new Invalida(Invalida::CAMPO_INMUTABLE, 'Un movimiento del umbral no se modifica.'));
        static::deleting(fn () => throw new Invalida(Invalida::BORRADO_PROHIBIDO, 'Un movimiento del umbral no se borra.'));
    }
}
