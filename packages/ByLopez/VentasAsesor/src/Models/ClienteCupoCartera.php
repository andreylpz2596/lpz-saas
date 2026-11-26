<?php

namespace ByLopez\VentasAsesor\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Politica de cupo de cartera de UN cliente (Doc. 35). Solo el limite autorizado y su estado; el «utilizado» se DERIVA de Cartera
 * (CupoCarteraClienteService) y jamas se guarda. Se escribe unicamente desde GestionCupoCarteraService, que toma el bloqueo del
 * cliente y deja el historial en la misma transaccion.
 */
class ClienteCupoCartera extends Model
{
    public const ESTADO_ACTIVO = 'activo';

    public const ESTADO_BLOQUEADO = 'bloqueado';

    public const ESTADOS = [self::ESTADO_ACTIVO, self::ESTADO_BLOQUEADO];

    protected $table = 'bylopez_va_clientes_cupo_cartera';

    protected $fillable = [
        'customer_id', 'cupo_autorizado', 'estado', 'vigencia_desde', 'motivo', 'creado_por', 'actualizado_por',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'cupo_autorizado' => 'decimal:2',
        'vigencia_desde' => 'datetime',
        'creado_por' => 'integer',
        'actualizado_por' => 'integer',
    ];
}
