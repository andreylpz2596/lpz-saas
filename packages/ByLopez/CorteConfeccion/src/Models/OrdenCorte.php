<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Contracts\OrdenCorte as OrdenCorteContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrdenCorte extends Model implements OrdenCorteContract
{
    protected $table = 'bylopez_cc_ordenes_corte';

    protected $fillable = [
        'codigo',
        'rollo_id',
        'cortador_id',
        'cortador_nombre',
        'cortador_nombre_snapshot',
        'color',
        'genero_linea',
        'fecha_entrega',
        'fecha_solicitud',
        'peso_entregado',
        'peso_total_rollos',
        'peso_sobrante_total',
        'merma_total',
        'peso_consumido_total',
        'total_piezas_cortadas',
        'peso_promedio_pieza',
        'estado',
        'observacion',
    ];

    protected $casts = [
        'fecha_entrega'            => 'date',
        'fecha_solicitud'          => 'date',
        'peso_entregado'           => 'decimal:3',
        'peso_total_rollos'        => 'decimal:3',
        'peso_sobrante_total'      => 'decimal:3',
        'merma_total'              => 'decimal:3',
        'peso_consumido_total'     => 'decimal:3',
        'total_piezas_cortadas'    => 'integer',
        'peso_promedio_pieza'      => 'decimal:6',
    ];

    public function rollo(): BelongsTo
    {
        return $this->belongsTo(Rollo::class, 'rollo_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(OrdenCorteDetalle::class, 'orden_corte_id');
    }

    public function rollosCorte(): HasMany
    {
        return $this->hasMany(OrdenCorteRollo::class, 'orden_corte_id');
    }

    public function recepcion(): HasOne
    {
        return $this->hasOne(RecepcionCorte::class, 'orden_corte_id');
    }
}
