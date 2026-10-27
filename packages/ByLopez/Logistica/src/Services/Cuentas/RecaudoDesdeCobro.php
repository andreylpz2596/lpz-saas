<?php

namespace ByLopez\Logistica\Services\Cuentas;

use ByLopez\Logistica\Enums\ConceptoMovimientoCuenta as Concepto;
use ByLopez\Logistica\Enums\EstadoMovimientoCuenta;
use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Models\MovimientoCuenta;
use ByLopez\Logistica\Models\Transportadora;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * C6 — el recaudo de la cuenta corriente nace del COBRO REAL. Cuando Cartera CONFIRMA un pago vinculado a una guia que liquida por
 * cuenta corriente, el transportador pasa a deberle ese dinero a ByLopez: un movimiento `recaudo` (+) por lo COBRADO (nunca por
 * lo esperado), origen `cobro`, origen_id = pago_id, clave `cobro:{pago}:recaudo` (UNIQUE: confirmar dos veces no duplica).
 *
 * Lo llama ConfirmarPagoService dentro de su propia transaccion (si algo falla, se deshace el pago completo). No toca Cartera:
 * el cliente ya pago; esto solo registra que ese dinero esta en custodia del transportador. Las guias legadas no se tocan.
 */
class RecaudoDesdeCobro
{
    public function __construct(protected ModoRecaudo $modo) {}

    public static function clave(int $pagoId): string
    {
        return 'cobro:'.$pagoId.':recaudo';
    }

    public function reconocer(int $pagoId, ?Admin $actor = null): ?MovimientoCuenta
    {
        $pago = DB::table('bylopez_va_pagos')->where('id', $pagoId)->lockForUpdate()->first(['id', 'guia_id', 'estado', 'fecha_pago', 'referencia']);

        if ($pago === null || $pago->guia_id === null || $pago->estado !== 'confirmado') {
            return null;
        }

        if ($previo = MovimientoCuenta::query()->where('idempotency_key', self::clave($pagoId))->first()) {
            return $previo;
        }

        $guia = Guia::query()->find((int) $pago->guia_id);

        if ($guia === null || $guia->liquida_cuenta_corriente !== true || ! $this->modo->esC6($guia)) {
            return null;
        }

        $transportadora = Transportadora::query()->where('codigo', $guia->transportadora_codigo)->first();

        if ($transportadora === null) {
            return null;
        }

        $cobrado = round((float) DB::table('bylopez_va_aplicaciones_pago')->where('pago_id', $pagoId)->whereNull('anulada_en')->sum('monto'), 2);

        if ($cobrado <= 0) {
            return null;
        }

        return MovimientoCuenta::query()->create([
            'transportadora_id' => (int) $transportadora->id,
            'order_id' => (int) $guia->order_id,
            'shipment_id' => (int) $guia->shipment_id,
            'guia_id' => (int) $guia->id,
            'concepto' => Concepto::RECAUDO,
            'importe' => $cobrado,
            'origen_tipo' => MovimientoCuenta::ORIGEN_COBRO,
            'origen_id' => $pagoId,
            'fecha_operativa' => substr((string) $pago->fecha_pago, 0, 10),
            'descripcion' => trans('logistica::app.cuentas.desc.cobro'),
            'referencia' => $pago->referencia,
            'estado' => EstadoMovimientoCuenta::ABIERTO,
            'idempotency_key' => self::clave($pagoId),
            'actor_admin_id' => $actor === null ? null : (int) $actor->id,
            'actor_name_snapshot' => $actor === null ? null : mb_substr((string) $actor->name, 0, 191),
        ]);
    }

    /**
     * ¿El cobro ya participo en una conciliacion? Mientras sea verdad, NO se puede anular ni reversar de forma simple: hace falta una
     * correccion financiera trazable. (Hoy un pago confirmado no tiene reverso en Cartera; la guarda queda lista para cuando exista.)
     */
    public function cobroConciliado(int $pagoId): bool
    {
        $mov = DB::table('bylopez_lg_cuenta_movimientos')->where('idempotency_key', self::clave($pagoId))->value('id');

        return $mov !== null && DB::table('bylopez_lg_conciliaciones_movimiento')->where('movimiento_id', $mov)->exists();
    }
}
