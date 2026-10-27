<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Enums\EstadoIncidenciaStock;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Solicitud de regularizacion de stock para despachar un pedido. Transita UNA sola vez, de `solicitada` a `regularizada` o
 * `rechazada`, y solo escribe los campos de resolucion. Nunca se borra. Alcance honesto: eventos de Eloquent; SQL directo
 * los salta (solo escribe IncidenciasStockService).
 */
class IncidenciaStock extends Model
{
    private const AL_RESOLVER = ['estado', 'abierta', 'motivo_resolucion', 'resuelta_por_admin_id', 'resuelta_por_name_snapshot', 'resuelta_en', 'updated_at'];

    protected $table = 'bylopez_lg_incidencias_stock';

    protected $fillable = [
        'order_id', 'inventory_source_id', 'estado', 'abierta', 'nota_solicitud', 'solicitante_admin_id', 'solicitante_name_snapshot',
        'motivo_resolucion', 'resuelta_por_admin_id', 'resuelta_por_name_snapshot', 'resuelta_en', 'idempotency_key', 'huella',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'inventory_source_id' => 'integer',
        'estado' => EstadoIncidenciaStock::class,
        'abierta' => 'integer',
        'solicitante_admin_id' => 'integer',
        'resuelta_por_admin_id' => 'integer',
        'resuelta_en' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $incidencia) {
            $transita = $incidencia->getOriginal('estado') === EstadoIncidenciaStock::SOLICITADA
                && $incidencia->estado !== EstadoIncidenciaStock::SOLICITADA
                && array_diff(array_keys($incidencia->getDirty()), self::AL_RESOLVER) === [];

            if (! $transita) {
                throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['incidencia' => $incidencia->getKey()]);
            }
        });

        static::deleting(fn (self $incidencia) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['incidencia' => $incidencia->getKey()]));
    }

    public function abiertaAun(): bool
    {
        return $this->estado === EstadoIncidenciaStock::SOLICITADA;
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(IncidenciaStockLinea::class, 'incidencia_id')->orderBy('id');
    }
}
