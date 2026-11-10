<?php

use ByLopez\Logistica\Services\Despachos\CancelarPendienteService;
use ByLopez\VentasAsesor\Models\ComisionAjuste;
use ByLopez\VentasAsesor\Models\ComisionIncidencia;
use ByLopez\VentasAsesor\Services\Comisiones\EvaluarComisionesService;
use ByLopez\VentasAsesor\Services\Comisiones\LiquidacionesComisionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../helpersCom4.php';

uses(TestCase::class);

/*
| D10 V1 · comisiones D5 (recalcular ANTES de liquidar) y D6 (despues de pagar: SOLO el exceso). Ventas reales, solo lpz_saas_testing.
|
| Escenario (umbral 10, base 4.000 × 10 % = 400 por unidad comisionable):
|   P0 = 6 u. (acumulado 6) · P1 = 2 u. (acumulado 8) · P2 = 6 u.  → libres 2 → comisionan 4 → 4 × 400 = 1.600 en P2 (pagado y entregado).
|   Se cancela todo el pendiente de P1 (2 u.) → acumulado antes de P2 = 6 → libres 4 → comisionan 2 → 800 es lo CORRECTO.
*/

/** @return array{e: array, p2: mixed, p1: mixed} P2 habilitada con 4 unidades y 1.600. */
function d10c_escenario(): array
{
    $e = com4_escenario('10', 10);
    com4_vender($e, 6);
    $p1 = com4_vender($e, 2);
    $p2 = com4_vender($e, 6);
    com4_pagar($p2);
    com4_entregar($p2);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $p2->id);

    return ['e' => $e, 'p1' => $p1, 'p2' => $p2];
}

test('D10-11 D5: antes de liquidar, una devolucion/cancelacion que cambia las unidades recalcula la linea y deja el evento con su traza', function () {
    ['p1' => $p1, 'p2' => $p2] = d10c_escenario();
    $antes = com4_linea($p2);

    expect($antes->estado)->toBe('habilitada')->and((int) $antes->unidades_comisionables)->toBe(4)->and((float) $antes->monto)->toBe(1600.0);

    app(CancelarPendienteService::class)->cancelar(f03b_admin('all'), (int) $p1->id, 'D10-11 reduce el acumulado');
    app(EvaluarComisionesService::class)->evaluarPedido((int) $p1->id);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $p2->id);
    $despues = com4_linea($p2)->fresh();
    $evento = DB::table('bylopez_va_comision_eventos')->where('linea_id', $despues->id)->where('tipo', 'recalculada')->orderByDesc('id')->first();
    $detalle = json_decode((string) $evento->detalle, true);

    expect((int) $despues->unidades_comisionables)->toBe(2)->and((float) $despues->monto)->toBe(800.0)->and($despues->alerta)->toBeNull()
        ->and($detalle['antes'])->toMatchArray(['unidades' => 4])
        ->and($detalle['traza'])->toMatchArray(['umbral' => 10, 'acumulado_previo' => 6, 'unidades_pedido' => 6])
        // Nada se abrio: no hay liquidacion que proteger.
        ->and(ComisionIncidencia::query()->where('order_id', $p2->id)->count())->toBe(0);
});

test('D10-12 D6: comision ya PAGADA 1.600 y hoy corresponde 800 → historico intacto + ajuste futuro de −800 (solo el exceso)', function () {
    ['e' => $e, 'p1' => $p1, 'p2' => $p2] = d10c_escenario();
    $admin = f03b_admin('all');
    $servicio = app(LiquidacionesComisionService::class);

    $liq = $servicio->crearBorrador($admin, (int) $e['asesor']->id, now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString(), [], 'D10-12', 'd10-liq-'.Str::uuid());
    $servicio->aprobar($admin, (int) $liq->id);
    $servicio->registrarAbono($admin, (int) $liq->id, ['monto' => '1600', 'fecha_pago' => now()->toDateString(), 'medio' => 'transferencia', 'referencia' => 'D10-12'], null, 'd10-abono-'.Str::uuid());
    $linea = com4_linea($p2)->fresh();
    $montoHistorico = (string) $linea->monto;

    app(CancelarPendienteService::class)->cancelar($admin, (int) $p1->id, 'D10-12 devolucion que cambia lo correcto');
    app(EvaluarComisionesService::class)->evaluarPedido((int) $p1->id);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $p2->id);

    $despues = com4_linea($p2)->fresh();
    $incidencia = ComisionIncidencia::query()->where('linea_id', $despues->id)->sole();
    $ajuste = ComisionAjuste::query()->findOrFail($incidencia->ajuste_id);

    // La liquidacion historica NO se reescribe ni se borra el pago.
    expect((string) $despues->monto)->toBe($montoHistorico)->and((int) $despues->unidades_comisionables)->toBe(4)
        ->and(DB::table('bylopez_va_comision_abonos')->where('liquidacion_id', $liq->id)->where('tipo', 'abono')->count())->toBe(1)
        // Pagado historicamente 1.600, correcto hoy 800 → ajuste de −800 (no −1.600), pendiente de aprobacion, auditado.
        ->and((float) $ajuste->monto)->toBe(-800.0)->and($ajuste->estado)->toBe(ComisionAjuste::PENDIENTE)->and($ajuste->origen)->toBe('devolucion_posterior')
        ->and($incidencia->detalle)->toMatchArray(['pagado_historico' => '1600.00', 'correcta_actual' => '800.00', 'exceso' => '800.00']);

    // Reevaluar de nuevo no duplica ni cambia nada.
    app(EvaluarComisionesService::class)->evaluarPedido((int) $p2->id);

    expect(ComisionAjuste::query()->where('linea_id', $despues->id)->count())->toBe(1)->and(ComisionIncidencia::query()->where('linea_id', $despues->id)->count())->toBe(1);
});

test('D10-12b D6: sin exceso (nada pagado todavia) NO se genera ajuste; la parte no pagada queda retenida hasta decidir', function () {
    ['e' => $e, 'p1' => $p1, 'p2' => $p2] = d10c_escenario();
    $admin = f03b_admin('all');
    $servicio = app(LiquidacionesComisionService::class);
    $liq = $servicio->crearBorrador($admin, (int) $e['asesor']->id, now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString(), [], 'D10-12b', 'd10-liq-'.Str::uuid());
    $servicio->aprobar($admin, (int) $liq->id);

    app(CancelarPendienteService::class)->cancelar($admin, (int) $p1->id, 'D10-12b devolucion antes del primer abono');
    app(EvaluarComisionesService::class)->evaluarPedido((int) $p1->id);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $p2->id);

    $linea = com4_linea($p2)->fresh();

    expect(ComisionAjuste::query()->where('linea_id', $linea->id)->count())->toBe(0)
        ->and(ComisionIncidencia::query()->where('linea_id', $linea->id)->where('estado', 'abierta')->count())->toBe(1)
        ->and((float) $linea->monto)->toBe(1600.0);
});

test('D10-fuentes: una cancelacion sobre un pedido PAGADO no genera credito utilizable (cancelacion = V2); Cartera si la descuenta del total operativo', function () {
    ['p1' => $p1] = d10c_escenario();
    com4_pagar($p1);
    $servicio = app(\ByLopez\VentasAsesor\Services\Credito\CreditoClienteService::class);

    // La regla vigente ya impide cancelar el pendiente de un pedido con pagos: no hay camino normal que convierta una cancelacion en credito.
    $rechazo = null;
    try {
        app(CancelarPendienteService::class)->cancelar(f03b_admin('all'), (int) $p1->id, 'D10 cancelacion con pagos');
    } catch (\ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException $e) {
        $rechazo = $e->getMessage();
    }

    expect($rechazo)->toContain('cancelacion_con_pagos');

    // Caso heredado/forzado: existe una cancelacion registrada sobre un pedido ya pagado. Cartera la descuenta, D10 no la toma como fuente.
    DB::table('bylopez_lg_cancelaciones')->insert([
        'order_id' => $p1->id, 'motivo' => 'fixture D10', 'unidades' => 1, 'valor_cancelado' => 4000, 'actor_admin_id' => 1, 'actor_name_snapshot' => 'fixture', 'created_at' => now(),
    ]);
    $saldo = app(\ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService::class)->saldosDePedidos([(int) $p1->id])[(int) $p1->id];
    $credito = $servicio->dePedido((int) $p1->id);

    expect($saldo->cancelado)->toBe(4000.0)
        ->and($saldo->verificado - $saldo->totalOperativo)->toBeGreaterThan(0.0)
        ->and($credito['economico'])->toBe(0.0)->and($credito['generado'])->toBe(0.0)->and($credito['disponible'])->toBe(0.0)
        ->and($servicio->delCliente((int) $p1->customer_id)['total_disponible'])->toBe(0.0);
});
