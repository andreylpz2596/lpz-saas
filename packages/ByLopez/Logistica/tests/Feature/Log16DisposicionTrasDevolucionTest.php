<?php

use ByLopez\Logistica\DataObjects\SolicitudEventoEnvio;
use ByLopez\Logistica\DataObjects\SolicitudRecepcion;
use ByLopez\Logistica\Enums\FuenteEventoLogistico as Fuente;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\DisposicionCobro;
use ByLopez\Logistica\Services\Despachos\ContextoDespachoService;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use ByLopez\Logistica\Services\Despachos\DisposicionCobroService;
use ByLopez\Logistica\Services\Entregas\CobrarEntregaService;
use ByLopez\Logistica\Services\Entregas\RecibirDevolucionService;
use ByLopez\Logistica\Services\Entregas\RegistrarEntregaService;
use ByLopez\Logistica\Services\Entregas\RegistrarRetornoTransportadoraService;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../helpersDespacho.php';
require_once __DIR__.'/../../../VentasAsesor/tests/helpersPagB.php';

uses(TestCase::class);

/*
| F6 DESPUES DE UNA DEVOLUCION. Pedido por transferencia (no contra entrega) de 10 unidades a 10.000, sin pagos: decision «cobrar al
| entregar», despacho de las 10 en una guia con recaudo 100.000, entrega de 8 y retorno de 2.
|   1. Registrar la devolucion NO cambia Cartera ni la decision: sigue exigible y vigente (la unidad aun no esta recibida).
|   2. Al RECIBIR en bodega (C5) el saldo baja al valor historico de lo recibido (2 × 10.000): la decision anterior YA NO VALE
|      (queda como «anterior», se debe decidir otra vez) y el importe anterior (100.000) ya no se puede cobrar.
| Esperados calculados a mano. Solo lpz_saas_testing (transaccion de la prueba).
*/

beforeEach(function () {
    pagb_corte('2000-01-01');
    $this->admin = f03b_admin('all');
});

function ftd_saldo(int $orderId): float
{
    return (float) app(CarteraConsultaService::class)->saldosDePedidos([$orderId])[$orderId]->saldo;
}

test('FTD-1 decision de cobro, devolucion registrada (sin efecto) y recepcion en bodega: la decision se invalida y no se cobra el importe anterior', function () {
    $e = log2_pedido(10, ['pago' => 'moneytransfer', 'total' => 100000.0]);
    $e['item']->update(['price' => 10000, 'base_price' => 10000, 'total' => 100000, 'base_total' => 100000, 'base_discount_amount' => 0, 'base_tax_amount' => 0]);
    pagb_factura($e['pedido']);
    $id = (int) $e['pedido']->id;
    $bodega = log2_bodega();
    $disp = app(DisposicionCobroService::class);

    // 1) Decision B con 100.000 por confirmar; el despacho de las 10 lleva el saldo como recaudo (C1).
    $decision = $disp->registrar($bodega, $id, 'cobrar_al_entregar', null);
    $guia = app(DespachoService::class)->despachar($bodega, log2_s($e))->guia;
    $envio = (int) $guia->shipment_id;
    expect((float) $guia->recaudo_esperado)->toBe(100000.0)
        ->and($disp->situacion($id, $bodega)['vigente']['id'])->toBe((int) $decision->id)
        ->and(ftd_saldo($id))->toBe(100000.0);

    // 2) Se entregan 8; el transportador devuelve 2 (REGISTRADO, aun sin recepcion fisica).
    $si = (int) DB::table('shipment_items')->where('shipment_id', $envio)->value('id');
    $operaria = f03b_admin('custom', array_merge(LOG2_PERMISOS_BODEGA, ['logistica.entregas.recibir_devolucion']));
    app(RegistrarEntregaService::class)->registrar($operaria, new SolicitudEventoEnvio(
        shipmentId: $envio, orderId: $id, fecha: now()->toDateString(), items: [$si => 8], fuente: Fuente::BODEGA_MANUAL, idempotencyKey: 'ftd-ent-'.Str::uuid(),
    ));
    $retorno = app(RegistrarRetornoTransportadoraService::class)->registrar($operaria, new SolicitudEventoEnvio(
        shipmentId: $envio, orderId: $id, fecha: now()->toDateString(), items: [$si => 2], fuente: Fuente::BODEGA_MANUAL, idempotencyKey: 'ftd-ret-'.Str::uuid(), motivo: 'Cliente ausente',
    ))->registro;
    $retornoItem = (int) $retorno->items->sole()->id;

    // 3) Devolucion REGISTRADA: ningun efecto financiero. Saldo, decision y recaudo siguen igual.
    $trasRegistro = $disp->situacion($id, $bodega);
    expect(ftd_saldo($id))->toBe(100000.0)
        ->and($trasRegistro['vigente']['id'])->toBe((int) $decision->id)
        ->and($trasRegistro['por_confirmar'])->toBe(100000.0);

    // 4) Bodega RECIBE las 2 (aptas): C5 baja lo exigible al valor historico de lo recibido (2 × 10.000).
    app(RecibirDevolucionService::class)->recibir($operaria, new SolicitudRecepcion(
        retornoId: (int) $retorno->id, fecha: now()->toDateString(), lineas: [$retornoItem => ['recibidas' => 2, 'aptas' => 2, 'novedad' => 0, 'motivo' => '']], idempotencyKey: 'ftd-rec-'.Str::uuid(),
    ));
    $trasRecepcion = $disp->situacion($id, $bodega);

    expect(ftd_saldo($id))->toBe(80000.0)
        // La decision B tomada con 100.000 ya no vale: no hay vigente; queda como «anterior» para que se vea por que hay que decidir de nuevo.
        ->and($trasRecepcion['requiere'])->toBeTrue()->and($trasRecepcion['resuelta'])->toBeFalse()
        ->and($trasRecepcion['vigente'])->toBeNull()
        ->and($trasRecepcion['anterior']['id'])->toBe((int) $decision->id)->and($trasRecepcion['anterior']['saldo_referencia'])->toBe(100000.0)
        ->and($trasRecepcion['por_confirmar'])->toBe(80000.0);

    // 5) El importe anterior (100.000) NO se puede cobrar; el nuevo saldo (80.000 = 8 entregadas) si, y una sola vez.
    $cobrar = fn (string $monto, string $clave) => app(CobrarEntregaService::class)->cobrar($this->admin, $envio, $monto, 'contraentrega_recaudo', null, null, $clave);
    $claveAnterior = 'ftd-cobro-ant-'.Str::uuid();
    expect(log2_error(fn () => $cobrar('100000', $claveAnterior)))->toBe(Invalida::COBRO_RECHAZADO)
        ->and(DB::table('bylopez_va_pagos')->where('customer_id', $e['pedido']->customer_id)->count())->toBe(0)
        ->and(ftd_saldo($id))->toBe(80000.0);

    $ok = $cobrar('80000', 'ftd-cobro-ok-'.Str::uuid());
    expect($ok['confirmado'])->toBeTrue()
        ->and(ftd_saldo($id))->toBe(0.0)
        ->and(log2_error(fn () => $cobrar('1000', 'ftd-cobro-extra-'.Str::uuid())))->toBe(Invalida::COBRO_RECHAZADO)
        // Historial inmutable: la decision original sigue ahi, nada se reescribio.
        ->and(DisposicionCobro::query()->where('order_id', $id)->count())->toBe(1);
});

test('FTD-2 despacho posterior a la recepcion: sin nueva decision se bloquea; con la nueva, la guia lleva el saldo ACTUAL y no el anterior', function () {
    $e = log2_pedido(10, ['pago' => 'moneytransfer', 'total' => 100000.0]);
    $e['item']->update(['price' => 10000, 'base_price' => 10000, 'total' => 100000, 'base_total' => 100000, 'base_discount_amount' => 0, 'base_tax_amount' => 0]);
    pagb_factura($e['pedido']);
    $id = (int) $e['pedido']->id;
    $bodega = log2_bodega();
    $disp = app(DisposicionCobroService::class);
    $operaria = f03b_admin('custom', array_merge(LOG2_PERMISOS_BODEGA, ['logistica.entregas.recibir_devolucion']));

    // Se despachan 6 de 10 con la decision B; el transportador devuelve 2 y Bodega las recibe.
    $disp->registrar($bodega, $id, 'cobrar_al_entregar', null);
    $guia = app(DespachoService::class)->despachar($bodega, log2_s($e, ['unidades' => [$e['item']->id => 6]]))->guia;
    $envio = (int) $guia->shipment_id;
    $si = (int) DB::table('shipment_items')->where('shipment_id', $envio)->value('id');
    $retorno = app(RegistrarRetornoTransportadoraService::class)->registrar($operaria, new SolicitudEventoEnvio(
        shipmentId: $envio, orderId: $id, fecha: now()->toDateString(), items: [$si => 2], fuente: Fuente::BODEGA_MANUAL, idempotencyKey: 'ftd2-ret-'.Str::uuid(), motivo: 'Cliente ausente',
    ))->registro;
    app(RecibirDevolucionService::class)->recibir($operaria, new SolicitudRecepcion(
        retornoId: (int) $retorno->id, fecha: now()->toDateString(), lineas: [(int) $retorno->items->sole()->id => ['recibidas' => 2, 'aptas' => 2, 'novedad' => 0, 'motivo' => '']], idempotencyKey: 'ftd2-rec-'.Str::uuid(),
    ));

    expect(ftd_saldo($id))->toBe(80000.0)
        // Las 4 pendientes NO salen con la decision anterior.
        ->and(log2_error(fn () => app(DespachoService::class)->despachar($bodega, log2_s($e, ['unidades' => [$e['item']->id => 4]]))))->toBe(Invalida::DISPOSICION_COBRO_REQUERIDA)
        ->and(DB::table('shipments')->where('order_id', $id)->count())->toBe(1);

    // El panel recibe lo mismo: pide decidir otra vez y muestra la decision anterior (la de 100.000) para que se entienda por que.
    $ctx = app(ContextoDespachoService::class)->paraPedido($id, $bodega);
    expect($ctx['disposicion_cobro']['resuelta'])->toBeFalse()->and($ctx['disposicion_cobro']['anterior']['saldo_referencia'])->toBe(100000.0);

    // Nueva decision con el saldo vigente: la guia de las 4 restantes lleva SU valor (4 × 10.000), no el saldo anterior ni el total.
    $nueva = $disp->registrar($bodega, $id, 'cobrar_al_entregar', null);
    $guia2 = app(DespachoService::class)->despachar($bodega, log2_s($e, ['unidades' => [$e['item']->id => 4]]))->guia;

    expect((float) $nueva->saldo_referencia)->toBe(80000.0)
        ->and((float) $guia2->recaudo_esperado)->toBe(40000.0)
        ->and(DisposicionCobro::query()->where('order_id', $id)->count())->toBe(2);
});
