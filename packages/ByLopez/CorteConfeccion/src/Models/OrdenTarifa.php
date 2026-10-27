<?php

namespace ByLopez\CorteConfeccion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Attribute\Models\AttributeOption;

/**
 * Tarifa unitaria conservada por una orden para UNA combinacion tipo de tela + referencia (familia Bagisto).
 * `tarifa_unitaria` null = «Sin tarifa». Ver migracion bylopez_cc_orden_tarifas.
 */
class OrdenTarifa extends Model
{
    protected $table = 'bylopez_cc_orden_tarifas';

    protected $fillable = [
        'orden_confeccion_id',
        'orden_corte_id',
        'tipo_tela_id',
        'attribute_family_id',
        'tarifa_unitaria',
        'tarifa_prestador_id',
        'motivo',
        'fijada_por_admin_id',
        'fijada_en',
    ];

    protected $casts = [
        'tarifa_unitaria' => 'decimal:4',
        'fijada_en' => 'datetime',
    ];

    public function tipoTela(): BelongsTo
    {
        return $this->belongsTo(AttributeOption::class, 'tipo_tela_id');
    }

    public function tieneTarifa(): bool
    {
        return $this->tarifa_unitaria !== null;
    }

    /** 'sin_tarifa' | 'ficha' (copiada de la ficha) | 'pactada' (acordada para esta orden). */
    public function origen(): string
    {
        if (! $this->tieneTarifa()) {
            return 'sin_tarifa';
        }

        return $this->motivo !== null ? 'pactada' : 'ficha';
    }
}
