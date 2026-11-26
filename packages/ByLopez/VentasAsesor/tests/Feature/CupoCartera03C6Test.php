<?php

use ByLopez\Logistica\Contracts\GestionaCobroPedido;
use ByLopez\Logistica\Contracts\PagosPendientesDeConfirmar;
use ByLopez\Logistica\Models\MovimientoCuenta;
use ByLopez\Logistica\Services\Cuentas\LiquidarCuentaService;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use ByLopez\VentasAsesor\DataObjects\CupoCartera;
use ByLopez\VentasAsesor\Services\Cartera\CajaPedidoService;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../helpersCupo.php';
require_once __DIR__.'/../../../Logistica/tests/helpersDespacho.php';
require_once __DIR__.'/../../../Logistica/tests/helpersDomicilios.php';

uses(TestCase::class);

/*
| Cupo de cartera (Doc. 35) × C6 (recaudo → custodia del transportador → liquidacion → conciliacion con ByLopez). Solo lpz_saas_testing
| (o la base exclusiva de la rama).
|
| La regla demostrada: el cliente deja de deber (y el cupo se libera) cuando el COBRO queda confirmado en Cartera. La custodia del
| transportador es dinero que EL transportador debe a ByLopez («nunca es deuda del cliente», Logistica/docs/C6) y NO consume cupo del
| cliente; liquidarla o conciliarla tampoco lo mueve. Cupo y Cartera usan el mismo `verificado`, asi que no pueden discrepar.
*/

const CUPO_C6_LOGISTICA = ['logistica', 'logistica.cuentas', 'logistica.cuentas.liquidar', 'logistica.entregas', 'logistica.entregas.registrar'];

function cupoc6_inicio(): array
{
    config(['logistica.cuentas.recaudo_desde_cobro_desde' => '2000-01-01']);
    pagb_corte('2000-01-01');
    app()->instance(PagosPendientesDeConfirmar::class, new class implements PagosPendientesDeConfirmar
    {
        public function estado(int $orderId): array
        {
            return ['sin_revisar' => 0, 'saldo' => null];
        }
    });
    log6_municipio('11001');

    return log6_catalogo();
}

/** Pedido contra entrega de `$total` despachado con una guia C6 (sin cobrar todavia). */
function cupoc6_pedido(array $c, float $total): array
{
    $e = log2_pedido(1, ['envio' => 'domicilio_local', 'pago' => 'cashondelivery', 'total' => $total, 'stock' => 30]);
    pagb_factura($e['pedido'], 'pending', $total);
    $guia = app(DespachoService::class)->despachar(log2_bodega(), log2_s(['pedido' => $e['pedido']], [
        'transportadora' => $c['jeison']->codigo, 'fletePagador' => '', 'unidades' => [$e['item']->id => 1],
    ]))->guia;

    return ['pedido' => $e['pedido'], 'guia' => $guia];
}

/** Cobro del transportador por la via oficial: registra y CONFIRMA el pago, ligado a la guia. */
function cupoc6_cobrar(array $p, string $monto): int
{
    return app(GestionaCobroPedido::class)->cobrar(
        f03b_admin('all'), (int) $p['pedido']->id, $monto, 'contraentrega_recaudo', null, now()->toDateString(), 'cupo-c6-'.Str::uuid(), (int) $p['guia']->id,
    )['pago_id'];
}

test('CUPO-09 contra entrega (C6): consume mientras no se cobra; el cobro confirmado libera aunque el dinero siga en custodia; liquidar no mueve el cupo', function () {
    $c = cupoc6_inicio();
    config(['ventasasesor.cupo.activo' => true, 'ventasasesor.cupo.umbral_alerta_pct' => 80]);
    $admin = d10_admin();
    $p = cupoc6_pedido($c, 59000.0);
    $cliente = (object) ['id' => (int) $p['pedido']->customer_id];
    $caja = fn () => app(CajaPedidoService::class)->dePedidos([(int) $p['pedido']->id])[(int) $p['pedido']->id];

    cupo_fijar($admin, $cliente, 100000);

    // 1) Despachado contra entrega y SIN cobrar: lo esperado es una expectativa, no dinero → el cliente aun debe → consume.
    expect(cupo_de($cliente)->exposicion)->toBe(59000.0)->and(cupo_de($cliente)->disponible)->toBe(41000.0)
        ->and($caja()['aplica'])->toBeFalse();

    // 2) Cobro del transportador (pago confirmado con guia): Cartera baja a 0, la custodia es 59.000 → el cupo se LIBERA.
    $pagoId = cupoc6_cobrar($p, '59000');
    $saldo = app(CarteraConsultaService::class)->saldosDePedidos([(int) $p['pedido']->id])[(int) $p['pedido']->id];

    expect($saldo->saldo)->toBe(0.0)
        ->and($caja())->toMatchArray(['cobrado' => 59000.0, 'en_custodia' => 59000.0, 'conciliado' => 0.0])
        ->and(cupo_de($cliente)->exposicion)->toBe(0.0)->and(cupo_de($cliente)->disponible)->toBe(100000.0)
        ->and(cupo_de($cliente)->estado)->toBe(CupoCartera::ACTIVO)
        // La custodia NO es deuda del cliente: ni aparece en el detalle ni mueve el cupo.
        ->and(cupo_de($cliente, detalle: true)->pedidos)->toBe([]);

    // 3) Liquidacion con el transportador (dinero que llega a ByLopez): cambia la CAJA, no la exposicion del cliente.
    $abiertos = MovimientoCuenta::query()->where('transportadora_id', $c['jeison']->id)->where('estado', 'abierto')->orderBy('id')->pluck('id')->all();
    app(LiquidarCuentaService::class)->liquidar(f03b_admin('custom', CUPO_C6_LOGISTICA), (int) $c['jeison']->id, [
        'movimientos' => $abiertos, 'valor' => '59000', 'idempotency_key' => 'cupo-liq-'.Str::uuid(),
    ]);

    expect($caja())->toMatchArray(['en_custodia' => 0.0, 'conciliado' => 59000.0])
        ->and(cupo_de($cliente)->exposicion)->toBe(0.0)
        ->and(app(CarteraConsultaService::class)->saldosDePedidos([(int) $p['pedido']->id])[(int) $p['pedido']->id]->saldo)->toBe(0.0)
        ->and(DB::table('bylopez_va_pagos')->where('id', $pagoId)->value('guia_id'))->toBe((int) $p['guia']->id);
});

test('C6 con cobro PARCIAL: solo lo cobrado libera; el resto sigue consumiendo hasta cobrarse', function () {
    $c = cupoc6_inicio();
    config(['ventasasesor.cupo.activo' => true]);
    $admin = d10_admin();
    $p = cupoc6_pedido($c, 59000.0);
    $cliente = (object) ['id' => (int) $p['pedido']->customer_id];
    cupo_fijar($admin, $cliente, 100000);

    cupoc6_cobrar($p, '20000');

    expect(cupo_de($cliente)->exposicion)->toBe(39000.0)->and(cupo_de($cliente)->disponible)->toBe(61000.0);
});
