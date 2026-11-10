<?php

namespace ByLopez\CorteConfeccion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Aplicacion de un movimiento a una orden (o a la deuda previa). Se anula, nunca se borra. */
class CarteraAplicacion extends Model
{
    public const PAGO_TRABAJO = 'pago_trabajo';

    public const DESCUENTO_PRESTAMO = 'descuento_prestamo';

    protected $table = 'bylopez_cc_cartera_aplicaciones';

    protected $fillable = [
        'movimiento_id', 'concepto', 'orden_confeccion_id', 'orden_corte_id', 'saldo_inicial_id', 'valor', 'liquidacion_id',
        'anulada_en', 'anulada_por', 'motivo_anulacion', 'creado_por',
    ];

    protected $casts = [
        'anulada_en' => 'datetime',
    ];

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(CarteraMovimiento::class, 'movimiento_id');
    }

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(CarteraLiquidacion::class, 'liquidacion_id');
    }
}
