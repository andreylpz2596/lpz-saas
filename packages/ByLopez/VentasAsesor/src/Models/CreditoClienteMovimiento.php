<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Exceptions\OperacionCreditoInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;

/**
 * Movimiento del LEDGER de consumos del credito del cliente (D10 V1). SOLO DE ADICION: rechaza update() y delete() sobre una fila
 * existente. Un reverso es OTRA fila (`revierte_id`), nunca una edicion. Mismo limite honesto que PagoEvento: una consulta masiva
 * por Query Builder la saltaria; la regla del modulo es escribir solo desde los servicios de credito.
 */
class CreditoClienteMovimiento extends Model
{
    public const UPDATED_AT = null;

    public const APLICACION = 'aplicacion';

    public const REEMBOLSO = 'reembolso';

    public const REVERSO_APLICACION = 'reverso_aplicacion';

    protected $table = 'bylopez_va_creditos_cliente_movimientos';

    protected $fillable = [
        'customer_id', 'tipo', 'order_origen_id', 'order_destino_id', 'monto', 'pago_id', 'aplicacion_pago_id', 'recepcion_origen_id',
        'revierte_id', 'medio_devolucion', 'referencia', 'comprobante_ruta', 'motivo', 'actor_admin_id', 'actor_nombre',
        'idempotency_key', 'huella_solicitud', 'datos',
    ];

    protected $casts = [
        'customer_id' => 'integer', 'order_origen_id' => 'integer', 'order_destino_id' => 'integer', 'monto' => 'decimal:4',
        'pago_id' => 'integer', 'aplicacion_pago_id' => 'integer', 'revierte_id' => 'integer', 'actor_admin_id' => 'integer', 'datos' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $m) {
            if ($m->exists) {
                throw new Invalida(Invalida::INMUTABLE, 'Los movimientos de credito son de solo adicion.');
            }
        });
        static::deleting(fn () => throw new Invalida(Invalida::INMUTABLE, 'Los movimientos de credito no se borran.'));
    }
}
