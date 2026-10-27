<?php

namespace ByLopez\Logistica\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Zona de entrega = conjunto de municipios DIVIPOLA (Bogota 11001, Soacha 25754...). Se desactiva, no se borra. */
class Zona extends Model
{
    protected $table = 'bylopez_lg_zonas';

    protected $fillable = ['codigo', 'nombre', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function municipios(): HasMany
    {
        return $this->hasMany(ZonaMunicipio::class, 'zona_id');
    }
}
