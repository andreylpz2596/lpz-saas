<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Enums\ConceptoMovimientoCuenta;
use ByLopez\Logistica\Enums\EstadoMovimientoCuenta;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento de la cuenta corriente de una transportadora. Libro SOLO DE ADICION: el saldo es la suma de `importe`
 * (+ = la transportadora debe a ByLopez; - = ByLopez debe a la transportadora).
 *
 * Inmutable salvo la transicion unica `abierto → liquidado` (+ `liquidacion_id`), que solo hace LiquidarCuentaService.
 * Corregir = reverso o ajuste, nunca editar. Alcance honesto: eventos de Eloquent (SQL directo los salta; la base
 * conserva los CHECK y UNIQUE como ultima barrera).
 */
class MovimientoCuenta extends Model
{
    public const ORIGEN_ENVIO = 'envio';

    public const ORIGEN_REVISION = 'revision';

    public const ORIGEN_AJUSTE = 'ajuste';

    public const ORIGEN_LIQUIDACION = 'liquidacion';

    public const ORIGEN_REVERSO = 'reverso';

    /** C6: recaudo nacido de un COBRO real confirmado (origen_id = pago_id). Distingue el recaudo C6 del legado (envio). */
    public const ORIGEN_COBRO = 'cobro';

    protected $table = 'bylopez_lg_cuenta_movimientos';

    protected $fillable = [
        'transportadora_id', 'order_id', 'shipment_id', 'guia_id', 'concepto', 'importe', 'origen_tipo', 'origen_id', 'revierte_id',
        'fecha_operativa', 'descripcion', 'motivo', 'referencia', 'estado', 'liquidacion_id', 'idempotency_key', 'huella',
        'actor_admin_id', 'actor_name_snapshot',
    ];

    protected $casts = [
        'transportadora_id' => 'integer',
        'order_id' => 'integer',
        'shipment_id' => 'integer',
        'guia_id' => 'integer',
        'origen_id' => 'integer',
        'revierte_id' => 'integer',
        'liquidacion_id' => 'integer',
        'actor_admin_id' => 'integer',
        'concepto' => ConceptoMovimientoCuenta::class,
        'estado' => EstadoMovimientoCuenta::class,
        'importe' => 'decimal:2',
        'fecha_operativa' => 'date',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $m) {
            $sucios = array_diff(array_keys($m->getDirty()), ['updated_at']);
            sort($sucios);

            $liquidando = $sucios === ['estado', 'liquidacion_id']
                && $m->getOriginal('estado') === EstadoMovimientoCuenta::ABIERTO
                && $m->estado === EstadoMovimientoCuenta::LIQUIDADO
                && $m->liquidacion_id !== null;

            if (! $liquidando) {
                throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['movimiento' => $m->getKey()]);
            }
        });

        static::deleting(fn (self $m) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['movimiento' => $m->getKey()]));
    }

    public function transportadora(): BelongsTo
    {
        return $this->belongsTo(Transportadora::class, 'transportadora_id');
    }

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(LiquidacionTransportadora::class, 'liquidacion_id');
    }
}
