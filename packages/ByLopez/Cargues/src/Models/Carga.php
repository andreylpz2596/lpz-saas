<?php

namespace ByLopez\Cargues\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Carga extends Model
{
    protected $table = 'bylopez_cg_cargas';

    protected $guarded = [];

    protected $casts = [
        'encabezados' => 'array',
        'relaciones' => 'array',
        'config_congelada' => 'array',
        'contadores' => 'array',
        'resultado' => 'array',
        'progreso_actualizado_en' => 'datetime',
        'encolada_en' => 'datetime',
        'iniciada_en' => 'datetime',
        'finalizada_en' => 'datetime',
    ];

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(Tipo::class, 'tipo_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(TipoVersion::class, 'tipo_version_id');
    }

    public function datos(): HasMany
    {
        return $this->hasMany(CargaDato::class, 'carga_id');
    }

    public function incidencias(): HasMany
    {
        return $this->hasMany(CargaIncidencia::class, 'carga_id');
    }
}
