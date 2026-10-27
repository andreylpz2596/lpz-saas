<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Enums\EstadoEventoLogistico;
use ByLopez\Logistica\Models\Concerns\EventoLogisticoInmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * VALIDACION DE ENTREGA: el administrador ratifica que un envio quedo entregado. Evento inmutable (solo se anula con
 * motivo). Es el hito del que dependen la comision del asesor (COM-04) y el cierre del pedido. Lo escribe unicamente
 * ValidarEntregaService.
 */
class ValidacionEntrega extends Model
{
    use EventoLogisticoInmutable;

    protected $table = 'bylopez_lg_validaciones_entrega';

    protected $fillable = [
        'shipment_id', 'order_id', 'observacion', 'actor_admin_id', 'actor_name_snapshot', 'entregadas', 'devueltas',
        'estado', 'idempotency_key', 'huella',
    ];

    protected $casts = [
        'shipment_id' => 'integer',
        'order_id' => 'integer',
        'entregadas' => 'integer',
        'devueltas' => 'integer',
        'estado' => EstadoEventoLogistico::class,
        'actor_admin_id' => 'integer',
        'anulada_por_admin_id' => 'integer',
        'anulada_en' => 'datetime',
    ];
}
