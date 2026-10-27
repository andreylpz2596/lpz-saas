<?php

namespace ByLopez\Cargues\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TipoVersion extends Model
{
    public const BORRADOR = 'borrador';

    public const ACTIVA = 'activa';

    public const RETIRADA = 'retirada';

    protected $table = 'bylopez_cg_tipo_versiones';

    protected $guarded = [];

    protected $casts = [
        'map' => 'array',
        'limites' => 'array',
        'revision_contrato' => 'array',
        'activada_en' => 'datetime',
        'revision_en' => 'datetime',
    ];

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(Tipo::class, 'tipo_id');
    }

    public function estructura(): BelongsTo
    {
        return $this->belongsTo(Estructura::class, 'estructura_id');
    }

    public function esBorrador(): bool
    {
        return $this->estado === self::BORRADOR;
    }
}
