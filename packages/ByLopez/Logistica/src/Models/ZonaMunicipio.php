<?php

namespace ByLopez\Logistica\Models;

use Illuminate\Database\Eloquent\Model;

/** Municipio DIVIPOLA (CHAR 5) de una zona. UNIQUE: un municipio pertenece a una sola zona. */
class ZonaMunicipio extends Model
{
    protected $table = 'bylopez_lg_zona_municipios';

    protected $fillable = ['zona_id', 'municipio_codigo'];
}
