<?php

namespace ByLopez\Cargues\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tipo extends Model
{
    protected $table = 'bylopez_cg_tipos';

    protected $guarded = [];

    protected $casts = ['activo' => 'boolean'];

    public function versiones(): HasMany
    {
        return $this->hasMany(TipoVersion::class, 'tipo_id')->orderByDesc('numero');
    }

    public function versionVigente(): BelongsTo
    {
        return $this->belongsTo(TipoVersion::class, 'version_vigente_id');
    }
}
