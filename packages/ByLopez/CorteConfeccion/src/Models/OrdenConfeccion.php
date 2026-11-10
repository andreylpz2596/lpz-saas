<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Contracts\OrdenConfeccion as OrdenConfeccionContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrdenConfeccion extends Model implements OrdenConfeccionContract
{
    protected $table = 'bylopez_cc_ordenes_confeccion';

    protected $fillable = [
        'codigo',
        'orden_corte_id',
        'taller_id',
        'fecha_entrega',
        'estado',
        'observacion',
        'reasignacion_origen_id',
    ];

    protected $casts = [
        'fecha_entrega' => 'date',
    ];

    public function ordenCorte(): BelongsTo
    {
        return $this->belongsTo(OrdenCorte::class, 'orden_corte_id');
    }

    public function taller(): BelongsTo
    {
        return $this->belongsTo(Taller::class, 'taller_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(OrdenConfeccionDetalle::class, 'orden_confeccion_id');
    }

    /** Tarifa conservada por cada combinacion tipo de tela + referencia de la orden (Doc. 05). */
    public function tarifas(): HasMany
    {
        return $this->hasMany(OrdenTarifa::class, 'orden_confeccion_id');
    }

    /** Reasignaciones donde esta orden cedio prendas a otro taller. */
    public function reasignacionesSalida(): HasMany
    {
        return $this->hasMany(Reasignacion::class, 'orden_confeccion_origen_id');
    }

    /** Reasignaciones que alimentaron esta orden (normalmente una: la que la creo). */
    public function reasignacionesEntrada(): HasMany
    {
        return $this->hasMany(Reasignacion::class, 'orden_confeccion_destino_id');
    }

    public function retornos(): HasMany
    {
        return $this->hasMany(OrdenConfeccionRetorno::class, 'orden_confeccion_id');
    }

    public function movimientosInventario(): HasMany
    {
        return $this->hasMany(InventarioMovimiento::class, 'orden_confeccion_id');
    }
}
