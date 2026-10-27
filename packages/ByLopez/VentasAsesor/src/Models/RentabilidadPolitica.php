<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Models\Concerns\VersionConVigencia;
use Illuminate\Database\Eloquent\Model;

/**
 * Politica de ganancia minima por unidad DESPUES de comision y costos variables (opcion B). `familia_id` NULL = politica
 * por defecto; la de una referencia (familia de Bagisto) manda sobre la de defecto.
 */
class RentabilidadPolitica extends Model
{
    use VersionConVigencia;

    public const CAMPOS_DE_CALCULO = ['familia_id', 'tipo_tela_id', 'version', 'ganancia_minima_unidad', 'margen_minimo_pct', 'redondeo_escalon', 'vigente_desde'];

    protected $table = 'bylopez_va_rentabilidad_politicas';

    protected $fillable = [
        'familia_id', 'tipo_tela_id', 'version', 'ganancia_minima_unidad', 'margen_minimo_pct', 'redondeo_escalon', 'vigente_desde',
        'vigente_hasta', 'activo', 'motivo', 'creado_por_admin_id', 'creado_por_name_snapshot',
    ];

    protected $casts = [
        'familia_id' => 'integer',
        'tipo_tela_id' => 'integer',
        'version' => 'integer',
        'ganancia_minima_unidad' => 'decimal:2',
        'margen_minimo_pct' => 'decimal:4',
        'redondeo_escalon' => 'integer',
        'vigente_desde' => 'datetime',
        'vigente_hasta' => 'datetime',
        'activo' => 'boolean',
    ];
}
