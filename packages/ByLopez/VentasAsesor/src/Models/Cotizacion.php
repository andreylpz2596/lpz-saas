<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Exceptions\CotizacionInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foto congelada de una cotizacion (lineas, totales, cliente y datos de pago del momento). Solo se crea: una fila existente no se
 * edita (cambiar la venta y volver a cotizar crea la version siguiente) y nunca se borra, para poder reabrir exactamente lo enviado.
 */
class Cotizacion extends Model
{
    protected $table = 'bylopez_va_cotizaciones';

    protected $fillable = [
        'borrador_id', 'version', 'customer_id', 'valida_hasta', 'huella', 'cliente', 'lineas', 'totales', 'entrega', 'datos_pago',
        'notas', 'creada_por_admin_id', 'creada_por_nombre', 'propietario_admin_id',
    ];

    protected $casts = [
        'borrador_id' => 'integer', 'version' => 'integer', 'customer_id' => 'integer', 'valida_hasta' => 'date',
        'cliente' => 'array', 'lineas' => 'array', 'totales' => 'array', 'entrega' => 'array', 'datos_pago' => 'array',
        'creada_por_admin_id' => 'integer', 'propietario_admin_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $c) {
            if ($c->exists) {
                throw new Invalida('cotizacion_inmutable');
            }
        });
        static::deleting(fn () => throw new Invalida('cotizacion_inmutable'));
    }

    public function borrador(): BelongsTo
    {
        return $this->belongsTo(BorradorVenta::class, 'borrador_id');
    }

    /** COT-000123 (y «-2» desde la segunda version de la misma venta). */
    public function numero(): string
    {
        return sprintf('COT-%06d', $this->borrador_id).($this->version > 1 ? '-'.$this->version : '');
    }
}
