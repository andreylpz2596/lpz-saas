<?php

namespace ByLopez\VentasAsesor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Customer\Models\CustomerProxy;
use Webkul\User\Models\AdminProxy;

/** Vinculo auditable entre un cliente comercial (customers) y un usuario de acceso (admins). Nunca se borra. */
class ClienteAcceso extends Model
{
    public const ACTIVO = 'activo';

    public const SUSPENDIDO = 'suspendido';

    public const DESVINCULADO = 'desvinculado';

    protected $table = 'bylopez_va_clientes_acceso';

    protected $fillable = [
        'customer_id', 'admin_id', 'estado', 'habilitado_por_admin_id', 'habilitado_at',
        'suspendido_at', 'suspendido_motivo', 'desvinculado_at', 'desvinculado_motivo',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'admin_id' => 'integer',
        'habilitado_por_admin_id' => 'integer',
        'habilitado_at' => 'datetime',
        'suspendido_at' => 'datetime',
        'desvinculado_at' => 'datetime',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(CustomerProxy::modelClass(), 'customer_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(AdminProxy::modelClass(), 'admin_id');
    }
}
