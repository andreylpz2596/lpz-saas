<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Exceptions\OperacionCreditoInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;

/**
 * Reduccion autorizada de la obligacion de un pedido (D10 V1; hoy condonacion de flete). Solo cambian los campos de REVERSO
 * (`estado` activo → reversado, una vez): monto, concepto, causa y motivo originales son inmutables y la fila nunca se borra.
 */
class AjusteObligacion extends Model
{
    public const ACTIVO = 'activo';

    public const REVERSADO = 'reversado';

    public const CONCEPTO_FLETE = 'flete';

    public const CAUSA_ERROR_BYLOPEZ = 'error_bylopez';

    public const CAUSA_TRANSPORTADORA = 'transportadora';

    public const CAUSA_DECISION_ADMINISTRATIVA = 'decision_administrativa';

    public const CAUSAS = [self::CAUSA_ERROR_BYLOPEZ, self::CAUSA_TRANSPORTADORA, self::CAUSA_DECISION_ADMINISTRATIVA];

    protected $table = 'bylopez_va_ajustes_obligacion';

    protected $fillable = [
        'order_id', 'customer_id', 'concepto', 'causa', 'monto', 'motivo', 'estado', 'actor_admin_id', 'actor_nombre',
        'reversado_en', 'reversado_por_admin_id', 'motivo_reverso', 'reverso_idempotency_key', 'idempotency_key', 'huella_solicitud', 'datos',
    ];

    protected $casts = ['order_id' => 'integer', 'customer_id' => 'integer', 'monto' => 'decimal:4', 'reversado_en' => 'datetime', 'datos' => 'array'];

    protected static function booted(): void
    {
        static::updating(function (self $a) {
            if ($a->isDirty(['order_id', 'customer_id', 'concepto', 'causa', 'monto', 'motivo', 'actor_admin_id', 'idempotency_key', 'huella_solicitud'])) {
                throw new Invalida(Invalida::INMUTABLE, 'Un ajuste de obligacion no se modifica: se reversa y se registra otro.');
            }

            if ($a->isDirty('estado') && $a->getOriginal('estado') !== self::ACTIVO) {
                throw new Invalida(Invalida::INMUTABLE, 'El ajuste ya fue reversado.');
            }
        });
        static::deleting(fn () => throw new Invalida(Invalida::INMUTABLE, 'Un ajuste de obligacion no se borra.'));
    }
}
