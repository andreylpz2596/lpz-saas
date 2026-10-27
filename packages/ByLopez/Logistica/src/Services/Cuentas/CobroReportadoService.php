<?php

namespace ByLopez\Logistica\Services\Cuentas;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Models\Transportadora;
use ByLopez\Logistica\Services\Entregas\CobrarEntregaService;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * COBRO REPORTADO (C6): una transportadora externa (99envios, nacional contra entrega) que cobra al cliente SIN que nadie lo registre
 * al entregar. El cobro nace cuando el Administrador registra lo que la transportadora REPORTA haber cobrado de UNA guia: importe real,
 * fecha y referencia o evidencia. Es el mismo hecho que un cobro en la entrega (pago confirmado con guia_id → recaudo en custodia):
 * este servicio solo valida la guia y delega en CobrarEntregaService, que delega en Cartera. No asume entregado = cobrado, no liquida
 * (la liquidacion es otro hecho, otro flujo) y no escribe pagos ni movimientos por su cuenta. Inicialmente manual; mas adelante podra
 * alimentarse por importacion o API sin cambiar nada de esto.
 */
class CobroReportadoService
{
    public const MEDIO = 'contraentrega_recaudo';

    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected CobrarEntregaService $cobrar,
    ) {}

    /**
     * @param  array{guia_id?: mixed, valor?: mixed, fecha?: mixed, referencia?: mixed, idempotency_key?: mixed}  $datos
     * @return array{pago_id: int, confirmado: bool, duplicado: bool, aviso: ?string}
     */
    public function registrar(?Admin $actor, int $transportadoraId, array $datos): array
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_CUENTAS_LIQUIDAR)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }

        $transportadora = Transportadora::query()->find($transportadoraId) ?? throw new Invalida(Invalida::CUENTA_INEXISTENTE);
        $guia = Guia::query()->find((int) ($datos['guia_id'] ?? 0));

        if ($guia === null || $guia->transportadora_codigo !== $transportadora->codigo || $guia->liquida_cuenta_corriente !== true) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, ['motivo' => 'guia_no_es_de_la_cuenta']);
        }

        $valor = trim((string) ($datos['valor'] ?? ''));

        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $valor) || (float) $valor <= 0) {
            throw new Invalida(Invalida::VALOR_INVALIDO);
        }

        $referencia = trim((string) ($datos['referencia'] ?? ''));

        return $this->cobrar->cobrar(
            $actor, (int) $guia->shipment_id, $valor, self::MEDIO, $referencia === '' ? null : mb_substr($referencia, 0, 100),
            ($datos['fecha'] ?? null) === null ? null : (string) $datos['fecha'], (string) ($datos['idempotency_key'] ?? ''),
        );
    }

    /**
     * Guias de la cuenta a las que se puede reportar un cobro: entregadas (al menos una unidad) y con recaudo esperado; con lo ya cobrado.
     *
     * @return list<array<string, mixed>>
     */
    public function guiasParaCobro(Transportadora $transportadora): array
    {
        $guias = DB::table('bylopez_lg_guias as g')->join('orders as o', 'o.id', '=', 'g.order_id')
            ->where('g.transportadora_codigo', $transportadora->codigo)->where('g.liquida_cuenta_corriente', true)->where('g.recaudo_esperado', '>', 0)
            ->whereExists(fn ($q) => $q->from('bylopez_lg_entregas as e')->whereColumn('e.shipment_id', 'g.shipment_id')->where('e.estado', 'confirmado'))
            ->orderByDesc('g.id')->limit(80)->get(['g.id', 'g.numero_guia', 'g.recaudo_esperado', 'o.increment_id']);

        $cobrado = DB::table('bylopez_va_pagos')->whereIn('guia_id', $guias->pluck('id')->all() ?: [0])->where('estado', '!=', 'rechazado')
            ->groupBy('guia_id')->selectRaw('guia_id, SUM(monto) AS total')->pluck('total', 'guia_id');

        return $guias->map(fn ($g) => [
            'id' => (int) $g->id,
            'pedido' => $g->increment_id,
            'guia' => $g->numero_guia,
            'esperado' => round((float) $g->recaudo_esperado, 2),
            'cobrado' => round((float) ($cobrado[$g->id] ?? 0), 2),
        ])->all();
    }
}
