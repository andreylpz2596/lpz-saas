<?php

namespace ByLopez\VentasAsesor\Support\Logistica;

use ByLopez\Logistica\Contracts\SugiereRecaudo;
use ByLopez\Logistica\Support\RecaudoSugeridoBagisto;
use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use Illuminate\Support\Facades\DB;

/**
 * Recaudo sugerido para el despacho contra entrega, con el SALDO de Cartera (descuenta abonos ya aplicados al pedido)
 * en lugar del total de Bagisto. Solo es una sugerencia que la operaria puede corregir: NO registra ningun pago ni
 * mueve el saldo (el recaudo es lo que la transportadora debe cobrar, no dinero recibido por ByLopez).
 *
 * Sin saldo de Cartera disponible (pedido fuera de Cartera o Cartera inactiva) usa la regla base de Logistica.
 */
class SugiereRecaudoDesdeCartera implements SugiereRecaudo
{
    public function __construct(
        protected CarteraConsultaService $cartera,
        protected RecaudoSugeridoBagisto $base,
    ) {}

    public function recaudoSugerido(int $orderId): float
    {
        $sugerido = $this->base->recaudoSugerido($orderId);

        // Solo contra entrega: para otros medios la transportadora no cobra nada.
        if ($sugerido <= 0) {
            return 0.0;
        }

        $saldo = $this->cartera->saldosDePedidos([$orderId])[$orderId]->saldo ?? null;

        return $saldo === null ? $sugerido : round(max(0.0, (float) $saldo), 2);
    }

    /**
     * C1: saldo de Cartera + lo que ya cobraron las transportadoras (pagos de medio «custodia de tercero»): ese cobro baja
     * el saldo despues de entregar, pero la guia que lo cobro sigue comprometiendo ese valor. Asi el techo de la suma de
     * guias no cambia cuando se registra el cobro de una de ellas.
     */
    public function saldoExigible(int $orderId): float
    {
        $saldo = $this->cartera->saldosDePedidos([$orderId])[$orderId]->saldo ?? null;

        if ($saldo === null) {
            return $this->base->saldoExigible($orderId);
        }

        $cobradoPorTransportadoras = (float) DB::table('bylopez_va_aplicaciones_pago as a')
            ->join('bylopez_va_pagos as p', 'p.id', '=', 'a.pago_id')
            ->where('a.order_id', $orderId)->whereNull('a.anulada_en')
            // C6: un cobro vinculado a una guia (pago.guia_id) es del transportador sea cual sea el medio con que se registro.
            ->where(fn ($q) => $q->whereIn('p.medio_cobro', (array) config('ventasasesor.comisiones.medios_custodia_tercero', []))->orWhereNotNull('p.guia_id'))
            ->whereIn('p.estado', [EstadoPago::SIN_REVISAR->value, EstadoPago::CONFIRMADO->value])
            ->sum('a.monto');

        return round(max(0.0, (float) $saldo) + $cobradoPorTransportadoras, 2);
    }
}
