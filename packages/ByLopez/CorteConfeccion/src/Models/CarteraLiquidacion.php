<?php

namespace ByLopez\CorteConfeccion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Corte de cuentas: cifras congeladas e inmutables. Confirmarlo NO registra dinero nuevo. */
class CarteraLiquidacion extends Model
{
    protected $table = 'bylopez_cc_cartera_liquidaciones';

    protected $fillable = [
        'codigo', 'taller_id', 'cortador_id', 'fecha', 'trabajo_reconocido', 'abonos_aplicados', 'descuento_prestamo',
        'queda_por_pagar', 'cifras', 'observacion', 'estado', 'creado_por', 'reversado_en', 'reversado_por',
        'motivo_reverso', 'idempotency_key',
    ];

    protected $casts = [
        'fecha' => 'date',
        'cifras' => 'array',
        'reversado_en' => 'datetime',
    ];

    public function aplicaciones(): HasMany
    {
        return $this->hasMany(CarteraAplicacion::class, 'liquidacion_id');
    }
}
