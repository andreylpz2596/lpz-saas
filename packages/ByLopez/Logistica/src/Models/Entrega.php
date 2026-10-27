<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Enums\EstadoEventoLogistico;
use ByLopez\Logistica\Enums\FuenteEventoLogistico;
use ByLopez\Logistica\Models\Concerns\EventoLogisticoInmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ENTREGA CANONICA (LOG-01): el cliente recibio estas unidades de este envio en `fecha_entrega`. Unica fuente de
 * «entregado» del sistema; nunca se infiere de orders.status, Shipment, Invoice ni tracking. Solo la escribe
 * RegistrarEntregaService (y AnularEventoLogisticoService la anula).
 */
class Entrega extends Model
{
    use EventoLogisticoInmutable;

    protected $table = 'bylopez_lg_entregas';

    protected $fillable = [
        'order_id', 'shipment_id', 'fecha_entrega', 'fuente', 'actor_admin_id', 'actor_name_snapshot',
        'carrier_code_snapshot', 'carrier_title_snapshot', 'track_number_snapshot', 'evidencia_referencia',
        'estado', 'idempotency_key', 'huella',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'shipment_id' => 'integer',
        'fecha_entrega' => 'date',
        'fuente' => FuenteEventoLogistico::class,
        'estado' => EstadoEventoLogistico::class,
        'actor_admin_id' => 'integer',
        'anulada_por_admin_id' => 'integer',
        'anulada_en' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(EntregaItem::class, 'entrega_id');
    }
}
