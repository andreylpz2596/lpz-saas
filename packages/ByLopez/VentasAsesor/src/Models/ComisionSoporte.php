<?php

namespace ByLopez\VentasAsesor\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Archivo (comprobante de un pago a una asesora) cargado por administracion, con su huella y el resultado de la lectura. Cargarlo
 * o leerlo NO registra ni aprueba ningun pago: solo un abono confirmado lo hace. El archivo vive en el disco PRIVADO.
 */
class ComisionSoporte extends Model
{
    protected $table = 'bylopez_va_comision_soportes';

    protected $fillable = [
        'asesor_admin_id', 'liquidacion_id', 'disco', 'ruta', 'nombre_original', 'mime', 'tamano', 'sha256',
        'lectura_estado', 'lectura_proveedor', 'lectura_resultado', 'lectura_error', 'subido_por_admin_id',
    ];

    protected $casts = [
        'asesor_admin_id' => 'integer',
        'liquidacion_id' => 'integer',
        'tamano' => 'integer',
        'lectura_resultado' => 'array',
        'subido_por_admin_id' => 'integer',
    ];
}
