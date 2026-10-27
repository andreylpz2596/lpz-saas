<?php

namespace ByLopez\VentasAsesor\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\User\Models\AdminProxy;

/**
 * Perfil comercial de un asesor (ASESOR-01). La identidad es el usuario de Bagisto `admin_id` (la misma que guarda la
 * atribucion en `advisor_id`); `admin_id` es unico e inmutable. Nunca se borra: se inactiva.
 */
class Asesor extends Model
{
    protected $table = 'bylopez_va_asesores';

    protected $fillable = ['admin_id', 'nombre', 'telefono', 'activo', 'creado_por_admin_id'];

    protected $casts = [
        'admin_id' => 'integer',
        'activo' => 'boolean',
        'creado_por_admin_id' => 'integer',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(AdminProxy::modelClass(), 'admin_id');
    }

    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true);
    }
}
