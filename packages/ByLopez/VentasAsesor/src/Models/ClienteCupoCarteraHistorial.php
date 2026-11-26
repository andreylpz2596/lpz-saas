<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Exceptions\OperacionCupoInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;

/**
 * Historial del cupo de cartera de un cliente (Doc. 35). SOLO DE ADICION: rechaza update() y delete() sobre una fila existente.
 * Mismo limite honesto que PagoEvento y CreditoClienteMovimiento: una consulta masiva por Query Builder lo saltaria; la regla del
 * modulo es escribirlo solo desde GestionCupoCarteraService.
 */
class ClienteCupoCarteraHistorial extends Model
{
    public const UPDATED_AT = null;

    public const ASIGNACION = 'asignacion';

    public const CAMBIO_CUPO = 'cambio_cupo';

    public const CAMBIO_ESTADO = 'cambio_estado';

    public const CAMBIO_CUPO_Y_ESTADO = 'cambio_cupo_estado';

    protected $table = 'bylopez_va_clientes_cupo_cartera_historial';

    protected $fillable = [
        'customer_id', 'accion', 'cupo_anterior', 'cupo_nuevo', 'estado_anterior', 'estado_nuevo', 'motivo',
        'actor_admin_id', 'actor_nombre', 'exposicion_al_cambiar',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'cupo_anterior' => 'decimal:2',
        'cupo_nuevo' => 'decimal:2',
        'exposicion_al_cambiar' => 'decimal:2',
        'actor_admin_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $fila) {
            if ($fila->exists) {
                throw new Invalida(Invalida::INMUTABLE, 'El historial del cupo es de solo adicion: no se modifica.');
            }
        });

        static::deleting(fn () => throw new Invalida(Invalida::INMUTABLE, 'El historial del cupo no se borra.'));
    }
}
