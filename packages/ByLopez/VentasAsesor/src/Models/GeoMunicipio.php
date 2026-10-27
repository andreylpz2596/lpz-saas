<?php

namespace ByLopez\VentasAsesor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Municipio, isla o area no municipalizada de DIVIPOLA (D-58). Clave: codigo de 5 caracteres.
 * Nunca se borra: si la fuente deja de traerlo se inactiva (`activo = false`).
 */
class GeoMunicipio extends Model
{
    protected $table = 'bylopez_geo_municipios';

    protected $primaryKey = 'codigo_divipola';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'codigo_divipola', 'nombre_oficial', 'nombre_busqueda', 'departamento_codigo', 'tipo',
        'activo', 'fuente', 'version_fuente', 'checksum_fuente',
    ];

    protected $casts = ['activo' => 'boolean'];

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(GeoDepartamento::class, 'departamento_codigo', 'codigo_divipola');
    }
}
