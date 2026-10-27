<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Contracts\OrdenConfeccionRetorno as OrdenConfeccionRetornoContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\User\Models\Admin;

class OrdenConfeccionRetorno extends Model implements OrdenConfeccionRetornoContract
{
    protected $table = 'bylopez_cc_ordenes_confeccion_retornos';

    protected $fillable = [
        'codigo',
        'orden_confeccion_id',
        'taller_id',
        'numero',
        'fecha_reporte_taller',
        'reportado_por',
        'fecha_recepcion_bodega',
        'recibido_por',
        'estado',
        'observacion_taller',
        'observacion_bodega',
    ];

    protected $casts = [
        'numero' => 'integer',
        'fecha_reporte_taller' => 'datetime',
        'fecha_recepcion_bodega' => 'datetime',
    ];

    public function ordenConfeccion(): BelongsTo
    {
        return $this->belongsTo(OrdenConfeccion::class, 'orden_confeccion_id');
    }

    public function taller(): BelongsTo
    {
        return $this->belongsTo(Taller::class, 'taller_id');
    }

    public function reportadoPor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reportado_por');
    }

    public function recibidoPor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'recibido_por');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(OrdenConfeccionRetornoDetalle::class, 'retorno_id');
    }
}
