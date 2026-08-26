<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Contracts\RecepcionCorte as RecepcionCorteContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecepcionCorte extends Model implements RecepcionCorteContract
{
    protected $table = 'bylopez_cc_recepciones_corte';

    protected $fillable = [
        'orden_corte_id',
        'fecha_recepcion',
        'peso_sobrante_usable',
        'peso_retasos',
        'peso_desperdicio',
        'merma_total',
        'observacion',
        'recibido_por',
    ];

    protected $casts = [
        'fecha_recepcion'      => 'date',
        'peso_sobrante_usable' => 'decimal:3',
        'peso_retasos'         => 'decimal:3',
        'peso_desperdicio'     => 'decimal:3',
        'merma_total'          => 'decimal:3',
    ];

    public function ordenCorte(): BelongsTo
    {
        return $this->belongsTo(OrdenCorte::class, 'orden_corte_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(RecepcionCorteDetalle::class, 'recepcion_corte_id');
    }
}
