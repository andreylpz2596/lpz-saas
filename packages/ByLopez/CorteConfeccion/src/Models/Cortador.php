<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Contracts\Cortador as CortadorContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\User\Models\Admin;

class Cortador extends Model implements CortadorContract
{
    protected $table = 'bylopez_cc_cortadores';

    protected $fillable = [
        'nombre',
        'nit',
        'contacto',
        'telefono',
        'direccion',
        'estado',
        'observacion',
        'usuario_id',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'usuario_id');
    }

    public function ordenesCorte(): HasMany
    {
        return $this->hasMany(OrdenCorte::class, 'cortador_id', 'usuario_id');
    }
}
