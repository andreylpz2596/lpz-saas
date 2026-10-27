<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Contracts\Taller as TallerContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\User\Models\Admin;

class Taller extends Model implements TallerContract
{
    protected $table = 'bylopez_cc_talleres';

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

    public function ordenesConfeccion(): HasMany
    {
        return $this->hasMany(OrdenConfeccion::class, 'taller_id');
    }
}
