<?php

namespace ByLopez\VentasAsesor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Departamento DANE (DIVIPOLA) con su equivalencia unica en `country_states` de Bagisto (D-58).
 * La clave es el codigo DIVIPOLA de 2 caracteres (cadena: conserva el cero a la izquierda).
 */
class GeoDepartamento extends Model
{
    protected $table = 'bylopez_geo_departamentos';

    protected $primaryKey = 'codigo_divipola';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['codigo_divipola', 'nombre_oficial', 'nombre_busqueda', 'country_state_id'];

    protected $casts = ['country_state_id' => 'integer'];

    public function municipios(): HasMany
    {
        return $this->hasMany(GeoMunicipio::class, 'departamento_codigo', 'codigo_divipola');
    }
}
