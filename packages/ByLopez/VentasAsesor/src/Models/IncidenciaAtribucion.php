<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Contracts\IncidenciaAtribucion as IncidenciaAtribucionContract;
use ByLopez\VentasAsesor\Enums\CodigoIncidencia;
use ByLopez\VentasAsesor\Enums\EstadoIncidencia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Sales\Models\OrderProxy;

/**
 * Incidencia de atribucion: algo que un humano tiene que revisar.
 *
 * Es una cola OPERATIVA y mutable —se reabre, se cuenta y se resuelve—, a
 * diferencia del historial de propiedad comercial, que es de solo adicion.
 *
 * La fila no guarda valores en conflicto, contexto serializado, payload, datos
 * del cliente ni mensajes de excepcion: solo el codigo estable, los NOMBRES de
 * los campos afectados y cuando se vio.
 */
class IncidenciaAtribucion extends Model implements IncidenciaAtribucionContract
{
    protected $table = 'bylopez_va_attribution_incidents';

    protected $fillable = [
        'order_id',
        'order_attribution_id',
        'codigo',
        'campos_conflicto',
        'estado',
        'attempt_count',
        'first_detected_at',
        'last_detected_at',
        'resolved_at',
        'resolved_by_admin_id',
        'resolved_by_name_snapshot',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'order_attribution_id' => 'integer',
        'codigo' => CodigoIncidencia::class,
        'campos_conflicto' => 'array',
        'estado' => EstadoIncidencia::class,
        'attempt_count' => 'integer',
        'first_detected_at' => 'datetime',
        'last_detected_at' => 'datetime',
        'resolved_at' => 'datetime',
        'resolved_by_admin_id' => 'integer',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(OrderProxy::modelClass(), 'order_id');
    }

    public function atribucion(): BelongsTo
    {
        return $this->belongsTo(AtribucionPedido::class, 'order_attribution_id');
    }

    public function estaAbierta(): bool
    {
        return $this->estado === EstadoIncidencia::ABIERTA;
    }

    public function scopeAbiertas(Builder $consulta): Builder
    {
        return $consulta->where('estado', EstadoIncidencia::ABIERTA->value);
    }

    public function scopeConCodigo(Builder $consulta, CodigoIncidencia $codigo): Builder
    {
        return $consulta->where('codigo', $codigo->value);
    }

    public function scopeDePedido(Builder $consulta, int $orderId): Builder
    {
        return $consulta->where('order_id', $orderId);
    }
}
