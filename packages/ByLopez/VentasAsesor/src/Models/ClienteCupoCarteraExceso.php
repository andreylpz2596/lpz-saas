<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Exceptions\OperacionCupoInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;

/**
 * Autorizacion de exceso de cupo (Doc. 35 §8). Una fila = una autorizacion con su propio ciclo: vigente → consumida (la venta que la uso,
 * con exposicion y exceso reales) | anulada (con motivo) | vencida. NUNCA se borra. Solo la escribe ExcesoCupoService (autorizar / anular /
 * vencer) y el cerco de creacion de pedidos (consumir), siempre bajo el bloqueo del cliente.
 */
class ClienteCupoCarteraExceso extends Model
{
    public const VIGENTE = 'vigente';

    public const CONSUMIDA = 'consumida';

    public const ANULADA = 'anulada';

    public const VENCIDA = 'vencida';

    protected $table = 'bylopez_va_clientes_cupo_cartera_excesos';

    protected $fillable = [
        'customer_id', 'exceso_autorizado', 'motivo', 'estado', 'vence_en', 'autorizado_por', 'autorizado_nombre',
        'consumida_en', 'order_id', 'exposicion_antes', 'cupo_al_consumir', 'nueva_obligacion', 'exceso_consumido',
        'anulada_en', 'anulada_por', 'anulada_motivo',
    ];

    protected $casts = [
        'customer_id' => 'integer', 'exceso_autorizado' => 'decimal:2', 'vence_en' => 'datetime', 'autorizado_por' => 'integer',
        'consumida_en' => 'datetime', 'order_id' => 'integer', 'exposicion_antes' => 'decimal:2', 'cupo_al_consumir' => 'decimal:2',
        'nueva_obligacion' => 'decimal:2', 'exceso_consumido' => 'decimal:2', 'anulada_en' => 'datetime', 'anulada_por' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new Invalida(Invalida::INMUTABLE, 'Las autorizaciones de exceso no se borran.'));
    }
}
