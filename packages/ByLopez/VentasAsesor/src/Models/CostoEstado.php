<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Models\Concerns\VersionConVigencia;
use Illuminate\Database\Eloquent\Model;

/**
 * Estado del COSTO ESTANDAR de una referencia (familia de Bagisto): confirmado o provisional, con fecha de revision. El
 * costo en si sigue siendo el atributo `cost` (Control de costos); esta tabla solo dice cuanta confianza merece.
 * Sin fila vigente = «sin estado». Vencido = `revisar_hasta` ya paso (derivado).
 */
class CostoEstado extends Model
{
    use VersionConVigencia;

    public const CONFIRMADO = 'confirmado';

    public const PROVISIONAL = 'provisional';

    public const CAMPOS_DE_CALCULO = ['familia_id', 'version', 'estado', 'revisar_hasta', 'vigente_desde'];

    protected $table = 'bylopez_va_costo_estados';

    protected $fillable = [
        'familia_id', 'version', 'estado', 'revisar_hasta', 'vigente_desde', 'vigente_hasta', 'motivo',
        'creado_por_admin_id', 'creado_por_name_snapshot',
    ];

    protected $casts = [
        'familia_id' => 'integer',
        'version' => 'integer',
        'revisar_hasta' => 'date',
        'vigente_desde' => 'datetime',
        'vigente_hasta' => 'datetime',
    ];
}
