<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Contracts\OrdenConfeccionDetalle as OrdenConfeccionDetalleContract;
use ByLopez\CorteConfeccion\Enums\PrioridadOrdenConfeccion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\ProductProxy;

class OrdenConfeccionDetalle extends Model implements OrdenConfeccionDetalleContract
{
    protected $table = 'bylopez_cc_ordenes_confeccion_detalles';

    protected $fillable = [
        'orden_confeccion_id',
        'product_id',
        'attribute_family_id',
        'producto_sku',
        'producto_nombre',
        'talla',
        'cantidad_entregada',
        'cantidad_reasignada_salida',
        'cantidad_en_transito',
        'prioridad',
        'cantidad_urgente',
        'cantidad_alta',
    ];

    /** H07: conserva la familia (referencia) del producto al crear el detalle; ver migracion 2026_10_29_000002. */
    protected static function booted(): void
    {
        static::creating(function (self $detalle) {
            if ($detalle->product_id && ! $detalle->attribute_family_id) {
                $detalle->attribute_family_id = DB::table('products')->where('id', $detalle->product_id)->value('attribute_family_id');
            }

            // Prioridad por cantidad: al asignar, toda la cantidad nace en la prioridad elegida. Quien crea el detalle con
            // cantidades explicitas (la reasignacion, que traslada las del origen) no se sobrescribe.
            $atributos = $detalle->getAttributes();

            if (! array_key_exists('cantidad_urgente', $atributos) && ! array_key_exists('cantidad_alta', $atributos)) {
                $cantidad = max((int) $detalle->cantidad_entregada - (int) $detalle->cantidad_reasignada_salida, 0);
                $detalle->cantidad_urgente = $detalle->prioridad === PrioridadOrdenConfeccion::Urgente->value ? $cantidad : 0;
                $detalle->cantidad_alta = $detalle->prioridad === PrioridadOrdenConfeccion::Alta->value ? $cantidad : 0;
            }
        });
    }

    protected $casts = [
        'cantidad_entregada' => 'integer',
        'cantidad_reasignada_salida' => 'integer',
        'cantidad_en_transito' => 'integer',
        'cantidad_urgente' => 'integer',
        'cantidad_alta' => 'integer',
    ];

    /**
     * Responsabilidad vigente del taller sobre este detalle, en SQL: lo asignado originalmente menos lo que entrego a
     * otro taller por reasignacion. UNICA definicion para consultas agregadas (DataGrids, tarifas, disponibilidad).
     */
    public const SQL_VIGENTE = '(cantidad_entregada - cantidad_reasignada_salida)';

    /** Responsabilidad vigente (asignado - reasignado a otro taller). La asignacion original no se edita. */
    public function getCantidadVigenteAttribute(): int
    {
        return max((int) $this->cantidad_entregada - (int) $this->cantidad_reasignada_salida, 0);
    }

    public function cantidadReportada(): int
    {
        return (int) ($this->relationLoaded('retornosDetalles')
            ? $this->retornosDetalles->sum('cantidad_reportada')
            : $this->retornosDetalles()->sum('cantidad_reportada'));
    }

    /**
     * Lo que el taller aun puede reportar Y lo unico que puede reasignarse: vigente menos lo reportado (incluidos los
     * ENT pendientes de bodega) menos lo que llego por reasignacion y sigue en transito.
     */
    public function pendientePorReportar(): int
    {
        return max($this->cantidad_vigente - (int) $this->cantidad_en_transito - $this->cantidadReportada(), 0);
    }

    /** Lo que el taller aun debe confeccionar, incluido lo que le llega en transito: base de las cantidades prioritarias. */
    public function saldoSinReportar(): int
    {
        return max($this->cantidad_vigente - $this->cantidadReportada(), 0);
    }

    /**
     * Reparto por prioridad de lo POR CONFECCIONAR (pendientePorReportar, sin transito): Urgente primero, luego Alta y
     * Normal es el resto. Nunca supera el pendiente real aunque lo guardado lo hiciera (unidades aun en transito).
     *
     * @return array{urgente: int, alta: int, normal: int}
     */
    public function prioridadesPorConfeccionar(): array
    {
        return PrioridadOrdenConfeccion::repartir($this->pendientePorReportar(), (int) $this->cantidad_urgente, (int) $this->cantidad_alta);
    }

    public function ordenConfeccion(): BelongsTo
    {
        return $this->belongsTo(OrdenConfeccion::class, 'orden_confeccion_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }

    public function retornosDetalles(): HasMany
    {
        return $this->hasMany(OrdenConfeccionRetornoDetalle::class, 'orden_confeccion_detalle_id');
    }
}
