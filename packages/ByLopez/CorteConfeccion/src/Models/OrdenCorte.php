<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Contracts\OrdenCorte as OrdenCorteContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Webkul\User\Models\Admin;

class OrdenCorte extends Model implements OrdenCorteContract
{
    protected $table = 'bylopez_cc_ordenes_corte';

    protected $fillable = [
        'codigo',
        'rollo_id',
        'cortador_id',
        'cortador_nombre',
        'cortador_nombre_snapshot',
        'color',
        'genero_linea',
        'fecha_entrega',
        'fecha_solicitud',
        'peso_entregado',
        'peso_total_rollos',
        'peso_sobrante_total',
        'merma_total',
        'merma_unica',
        'peso_consumido_total',
        'total_piezas_cortadas',
        'peso_promedio_pieza',
        'estado',
        'observacion',
        'creado_por_id',
        'reportado_por_id',
        'reportado_en',
    ];

    protected $casts = [
        'fecha_entrega' => 'date',
        'fecha_solicitud' => 'date',
        'peso_entregado' => 'decimal:3',
        'peso_total_rollos' => 'decimal:3',
        'peso_sobrante_total' => 'decimal:3',
        'merma_total' => 'decimal:3',
        'merma_unica' => 'boolean',
        'peso_consumido_total' => 'decimal:3',
        'total_piezas_cortadas' => 'integer',
        'peso_promedio_pieza' => 'decimal:6',
        'reportado_en' => 'datetime',
    ];

    /**
     * Peso fisico de tela asignado a la orden: lo entregado al cortador y, si
     * aun no se entrego, la suma de los rollos reservados. Es el maximo
     * posible de merma (cada OrdenCorteRollo.peso_inicial_usado es el
     * peso_disponible COMPLETO del rollo al elegirlo). Unica definicion: la
     * usan la recepcion (Request y Service) y el cierre.
     */
    public function pesoBase(): float
    {
        if ((float) $this->peso_entregado > 0) {
            return (float) $this->peso_entregado;
        }

        return (float) $this->peso_total_rollos;
    }

    /**
     * F4: las ordenes V2 no tienen cantidad proyectada (siempre 0); las historicas
     * si. Se deriva de los detalles solicitados (no hay marcador aparte): la UI
     * oculta "solicitado" cuando no hay proyeccion. Requiere la relacion detalles.
     */
    public function tieneProyeccion(): bool
    {
        return (int) $this->detalles->where('es_adicional', false)->sum('cantidad_proyectada') > 0;
    }

    public function rollo(): BelongsTo
    {
        return $this->belongsTo(Rollo::class, 'rollo_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(OrdenCorteDetalle::class, 'orden_corte_id');
    }

    public function rollosCorte(): HasMany
    {
        return $this->hasMany(OrdenCorteRollo::class, 'orden_corte_id');
    }

    /** Tarifa conservada por cada combinacion tipo de tela + referencia de la orden (Doc. 05). */
    public function tarifas(): HasMany
    {
        return $this->hasMany(OrdenTarifa::class, 'orden_corte_id');
    }

    public function recepcion(): HasOne
    {
        return $this->hasOne(RecepcionCorte::class, 'orden_corte_id');
    }

    public function ordenesConfeccion(): HasMany
    {
        return $this->hasMany(OrdenConfeccion::class, 'orden_corte_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'creado_por_id');
    }

    public function reportadoPor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reportado_por_id');
    }
}
