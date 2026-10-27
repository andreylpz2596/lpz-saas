<?php

namespace ByLopez\VentasAsesor\Models;

use Illuminate\Database\Eloquent\Model;

/** Fila de control por asesor y mes: cerrojo de la habilitacion y umbral aplicado cuando se fija. */
class ComisionMes extends Model
{
    protected $table = 'bylopez_va_comision_meses';

    protected $fillable = ['asesor_admin_id', 'mes', 'acuerdo_umbral_id', 'umbral_aplicado', 'umbral_fijado_en'];

    protected $casts = [
        'asesor_admin_id' => 'integer',
        'acuerdo_umbral_id' => 'integer',
        'umbral_aplicado' => 'integer',
        'umbral_fijado_en' => 'datetime',
    ];
}
