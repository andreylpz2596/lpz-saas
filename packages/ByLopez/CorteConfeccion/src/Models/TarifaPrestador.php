<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Contracts\TarifaPrestador as TarifaPrestadorContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarifa por prenda de un taller o de un cortador (una fila es de uno u otro). Historial inmutable: solo
 * cambian los campos de anulacion (ver TarifaPrestadorService).
 */
class TarifaPrestador extends Model implements TarifaPrestadorContract
{
    public const UPDATED_AT = null;

    protected $table = 'bylopez_cc_tarifas_prestador';

    protected $fillable = [
        'taller_id',
        'cortador_id',
        'tipo_tela_id',
        'attribute_family_id',
        'valor_unitario',
        'vigente_desde',
        'observacion',
        'creado_por_admin_id',
        'anulada_en',
        'anulada_por_admin_id',
        'motivo_anulacion',
    ];

    protected $casts = [
        'valor_unitario' => 'decimal:4',
        'vigente_desde' => 'date',
        'anulada_en' => 'datetime',
    ];

    public function taller(): BelongsTo
    {
        return $this->belongsTo(Taller::class, 'taller_id');
    }

    public function cortador(): BelongsTo
    {
        return $this->belongsTo(Cortador::class, 'cortador_id');
    }

    /** Tarifa general del primer intento de Fase 2 (sin tela ni referencia): historial, ya no se aplica. */
    public function esLegacyGeneral(): bool
    {
        return $this->attribute_family_id === null;
    }

    public function estaAnulada(): bool
    {
        return $this->anulada_en !== null;
    }
}
