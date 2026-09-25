<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Contracts\AtribucionPedido as AtribucionPedidoContract;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoAtribucion;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\OrigenPropiedadComercial;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Exceptions\HistorialInmutableException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Sales\Models\OrderProxy;
use Webkul\User\Models\AdminProxy;

/**
 * Atribucion comercial de un pedido. Lee el pedido del core a traves de su proxy
 * de Concord y nunca lo reescribe [M-§11.1].
 *
 * Integridad (Fase 02A1):
 * - `order_id` tiene clave foranea RESTRICT: borrar un pedido atribuido falla.
 * - Los identificadores de administrador NO tienen clave foranea: sobreviven a la
 *   baja del administrador junto a su snapshot de nombre, para no producir la
 *   combinacion invalida `advisor` + `advisor_id = null`.
 * - Borrar la fila esta prohibido: destruiria la trazabilidad comercial.
 *
 * Este modelo NO decide comision: la propiedad de asesor es condicion necesaria,
 * no suficiente (D-01, D-03, D-31, D-32 siguen pendientes).
 */
class AtribucionPedido extends Model implements AtribucionPedidoContract
{
    protected $table = 'bylopez_va_order_attributions';

    protected $fillable = [
        'order_id',
        'created_by_admin_id',
        'created_by_name_snapshot',
        'advisor_id',
        'advisor_name_snapshot',
        'commercial_owner_type',
        'commercial_owner_source',
        'sales_channel',
        'creation_source',
        'attribution_status',
        'external_reference',
        'cart_id',
        'assigned_at',
        'assigned_by_admin_id',
        'notes',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'created_by_admin_id' => 'integer',
        'advisor_id' => 'integer',
        'assigned_by_admin_id' => 'integer',
        'cart_id' => 'integer',
        'assigned_at' => 'datetime',
        'commercial_owner_type' => TipoPropiedadComercial::class,
        'commercial_owner_source' => OrigenPropiedadComercial::class,
        'sales_channel' => CanalVenta::class,
        'creation_source' => MecanismoCreacion::class,
        'attribution_status' => EstadoAtribucion::class,
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $atribucion) {
            throw new HistorialInmutableException(
                "La atribucion {$atribucion->id} no puede eliminarse: destruiria la trazabilidad comercial del pedido {$atribucion->order_id}."
            );
        });
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(OrderProxy::modelClass(), 'order_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(AdminProxy::modelClass(), 'created_by_admin_id');
    }

    public function asesor(): BelongsTo
    {
        return $this->belongsTo(AdminProxy::modelClass(), 'advisor_id');
    }

    public function asignadoPor(): BelongsTo
    {
        return $this->belongsTo(AdminProxy::modelClass(), 'assigned_by_admin_id');
    }

    public function logsDeAsignacion(): HasMany
    {
        return $this->hasMany(LogAsignacionAsesor::class, 'order_attribution_id');
    }

    /**
     * Solo dice de quien es la venta. NO afirma que genere comision: eso depende
     * de reglas, costos y entregas que todavia no existen.
     */
    public function esPropiedadDeAsesor(): bool
    {
        return $this->commercial_owner_type === TipoPropiedadComercial::ASESOR;
    }

    /** Filtra en el SERVIDOR por asesor propietario [M-§12.4]. */
    public function scopeDeAsesor(Builder $consulta, int $advisorId): Builder
    {
        return $consulta->where('advisor_id', $advisorId);
    }

    public function scopeConPropiedad(Builder $consulta, TipoPropiedadComercial $propiedad): Builder
    {
        return $consulta->where('commercial_owner_type', $propiedad->value);
    }

    public function scopeSinAsignar(Builder $consulta): Builder
    {
        return $consulta->where('commercial_owner_type', TipoPropiedadComercial::SIN_ASIGNAR->value);
    }

    public function scopeConIncidencia(Builder $consulta): Builder
    {
        return $consulta->where('attribution_status', EstadoAtribucion::FALLO_TECNICO->value);
    }
}
