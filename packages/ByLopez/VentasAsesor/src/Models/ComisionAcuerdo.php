<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Models\Concerns\VersionConVigencia;
use Illuminate\Database\Eloquent\Model;

/**
 * Acuerdo de comision de UN asesor (A-01): porcentaje sobre la utilidad (P − C) y umbral mensual de unidades sin
 * comision. Versionado con vigencia; `umbral_mensual_unidades` NULL = sin definir (distinto de 0).
 */
class ComisionAcuerdo extends Model
{
    use VersionConVigencia;

    public const CAMPOS_DE_CALCULO = ['asesor_admin_id', 'version', 'porcentaje', 'umbral_mensual_unidades', 'vigente_desde'];

    protected $table = 'bylopez_va_comision_acuerdos';

    protected $fillable = [
        'asesor_admin_id', 'version', 'porcentaje', 'umbral_mensual_unidades', 'vigente_desde', 'vigente_hasta', 'activo',
        'motivo', 'creado_por_admin_id', 'creado_por_name_snapshot',
    ];

    protected $casts = [
        'asesor_admin_id' => 'integer',
        'version' => 'integer',
        'porcentaje' => 'decimal:4',
        'umbral_mensual_unidades' => 'integer',
        'vigente_desde' => 'datetime',
        'vigente_hasta' => 'datetime',
        'activo' => 'boolean',
    ];

    public function umbralDefinido(): bool
    {
        return $this->umbral_mensual_unidades !== null;
    }
}
