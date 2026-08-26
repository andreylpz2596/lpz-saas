<?php

namespace ByLopez\CorteConfeccion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrdenCorteRollo extends Model
{
    protected $table = 'bylopez_cc_orden_corte_rollos';

    protected $fillable = [
        'orden_corte_id',
        'rollo_id',
        'peso_inicial_usado',
        'peso_sobrante',
        'peso_consumido',
    ];

    protected $casts = [
        'peso_inicial_usado' => 'decimal:3',
        'peso_sobrante'      => 'decimal:3',
        'peso_consumido'     => 'decimal:3',
    ];

    public function ordenCorte(): BelongsTo
    {
        return $this->belongsTo(OrdenCorte::class, 'orden_corte_id');
    }

    public function rollo(): BelongsTo
    {
        return $this->belongsTo(Rollo::class, 'rollo_id');
    }
}
