<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Enums\EstadoEventoLogistico;
use ByLopez\Logistica\Enums\FuenteEventoLogistico;
use ByLopez\Logistica\Models\Concerns\EventoLogisticoInmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RETORNO DE TRANSPORTADORA (LOG-D-03): la transportadora no entrego y devolvio estas unidades. Evento separado de la
 * entrega; no es refund ni RMA ni devolucion de un cliente. Solo lo escribe RegistrarRetornoTransportadoraService.
 */
class Retorno extends Model
{
    use EventoLogisticoInmutable;

    protected $table = 'bylopez_lg_retornos';

    protected $fillable = [
        'order_id', 'shipment_id', 'fecha_retorno', 'fuente', 'motivo', 'motivo_codigo', 'actor_admin_id', 'actor_name_snapshot',
        'carrier_code_snapshot', 'carrier_title_snapshot', 'track_number_snapshot', 'evidencia_referencia',
        'estado', 'idempotency_key', 'huella',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'shipment_id' => 'integer',
        'fecha_retorno' => 'date',
        'fuente' => FuenteEventoLogistico::class,
        'estado' => EstadoEventoLogistico::class,
        'actor_admin_id' => 'integer',
        'anulada_por_admin_id' => 'integer',
        'anulada_en' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(RetornoItem::class, 'retorno_id');
    }
}
