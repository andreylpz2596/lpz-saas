<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Enums\EstadoEventoLogistico;
use ByLopez\Logistica\Models\Concerns\EventoLogisticoInmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RECEPCION FISICA de una devolucion en bodega: Bodega confirma que llego la mercancia y cuantas unidades estan APTAS.
 * Es un evento inmutable (solo se anula con motivo, y solo si ninguna unidad se reingreso). Lo escribe unicamente
 * RecibirDevolucionService.
 */
class RecepcionDevolucion extends Model
{
    use EventoLogisticoInmutable;

    protected $table = 'bylopez_lg_recepciones_devolucion';

    protected $fillable = [
        'retorno_id', 'shipment_id', 'order_id', 'inventory_source_id', 'fecha_recepcion', 'observacion',
        'actor_admin_id', 'actor_name_snapshot', 'estado', 'idempotency_key', 'huella',
    ];

    protected $casts = [
        'retorno_id' => 'integer',
        'shipment_id' => 'integer',
        'order_id' => 'integer',
        'inventory_source_id' => 'integer',
        'fecha_recepcion' => 'date',
        'estado' => EstadoEventoLogistico::class,
        'actor_admin_id' => 'integer',
        'anulada_por_admin_id' => 'integer',
        'anulada_en' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(RecepcionDevolucionItem::class, 'recepcion_id');
    }

    public function retorno(): BelongsTo
    {
        return $this->belongsTo(Retorno::class, 'retorno_id');
    }
}
