<?php

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\DisposicionCobro;
use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Services\Despachos\ContextoDespachoService;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use ByLopez\Logistica\Services\Despachos\DisposicionCobroService;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Services\Cartera\ConfirmarPagoService;
use ByLopez\VentasAsesor\Services\Cartera\RechazarPagoService;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarPagoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../helpersDespacho.php';
require_once __DIR__.'/../../../VentasAsesor/tests/helpersPagB.php';

uses(TestCase::class);

/*
| F6 (regla aprobada 2026-10-26): transferencia (no contra entrega) con dinero sin confirmar → no sale en silencio con «no cobrar
| nada». A esperar (bloqueado), B cobrar al entregar (regla C1 por guia), C credito autorizado (permiso + motivo). Cartera REAL
| (fecha de corte de prueba). Solo lpz_saas_testing (transaccion de la prueba). Importes esperados calculados a mano.
*/

beforeEach(function () {
    pagb_corte('2000-01-01');
    $this->admin = f03b_admin('all');
});

function f6_pedido(array $o = []): array
{
    return log2_pedido(4, $o + ['pago' => 'moneytransfer', 'total' => 100000.0]);
}

function f6_credito()
{
    return f03b_admin('custom', array_merge(LOG2_PERMISOS_BODEGA, ['logistica.despachos.autorizar_credito']));
}

function f6_registrarPago($test, array $e, string $monto): int
{
    return app(RegistrarPagoService::class)->registrar($test->admin, (int) $e['pedido']->id, [
        'monto' => $monto, 'medio_cobro' => 'efectivo', 'fecha_pago' => now()->toDateString(),
    ], 'f6-pago-'.Str::uuid())->pagoId;
}

test('F6-1 transferencia con saldo y sin decision: el despacho se bloquea (A: esperar el pago) y no se crea nada', function () {
    $e = f6_pedido();
    $s = app(DisposicionCobroService::class)->situacion((int) $e['pedido']->id, log2_bodega());

    expect($s['requiere'])->toBeTrue()->and($s['resuelta'])->toBeFalse()->and($s['por_confirmar'])->toBe(100000.0)
        ->and($s['permisos'])->toBe(['cobrar_al_entregar' => true, 'credito' => false])
        ->and(log2_error(fn () => app(DespachoService::class)->despachar(log2_bodega(), log2_s($e))))->toBe(Invalida::DISPOSICION_COBRO_REQUERIDA)
        ->and(Guia::query()->where('order_id', $e['pedido']->id)->count())->toBe(0)
        ->and(DB::table('shipments')->where('order_id', $e['pedido']->id)->count())->toBe(0);

    // El drawer recibe la situacion para mostrar las tres opciones.
    $ctx = app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, log2_bodega());
    expect($ctx['disposicion_cobro']['requiere'])->toBeTrue()->and($ctx['recaudo_guia']['contra_entrega'])->toBeFalse();
});

test('F6-2 cobrar al entregar: la guia lleva el saldo (C1) y el metodo de pago del pedido no cambia; parcial reparte sin pasarse', function () {
    $e = f6_pedido();
    $bodega = log2_bodega();
    $d = app(DisposicionCobroService::class)->registrar($bodega, (int) $e['pedido']->id, 'cobrar_al_entregar', null);

    // Doble clic: la misma decision.
    expect(app(DisposicionCobroService::class)->registrar($bodega, (int) $e['pedido']->id, 'cobrar_al_entregar', null)->id)->toBe($d->id);

    // Parcial: 2 de 4 unidades. Total 100.000 = 4 lineas de 25.000 (la fixture no tiene flete en las lineas): mercancia 2 × 25.000.
    $item = $e['item'];
    $item->update(['price' => 25000, 'base_price' => 25000, 'total' => 100000, 'base_total' => 100000]);
    $g1 = app(DespachoService::class)->despachar($bodega, log2_s($e, ['unidades' => [$item->id => 2]]))->guia;
    $g2 = app(DespachoService::class)->despachar($bodega, log2_s($e, ['unidades' => [$item->id => 2]]))->guia;

    expect((float) $g1->recaudo_esperado)->toBe(50000.0)
        ->and((float) $g2->recaudo_esperado)->toBe(50000.0)
        ->and((float) $g1->recaudo_esperado + (float) $g2->recaudo_esperado)->toBe(100000.0)
        ->and($g1->disposicion_cobro_id)->toBe($d->id)->and($g2->disposicion_cobro_id)->toBe($d->id)
        ->and(DB::table('order_payment')->where('order_id', $e['pedido']->id)->value('method'))->toBe('moneytransfer');
});

test('F6-3 credito: solo con autorizar_credito y motivo; bodega y asesora no; la guia sale sin recaudo y queda el historial', function () {
    $e = f6_pedido();
    $servicio = app(DisposicionCobroService::class);
    $id = (int) $e['pedido']->id;

    expect(log2_error(fn () => $servicio->registrar(log2_bodega(), $id, 'credito_autorizado', 'Cliente frecuente')))->toBe(Invalida::SIN_PERMISO)
        ->and(log2_error(fn () => $servicio->registrar(log2_asesor(), $id, 'credito_autorizado', 'Cliente frecuente')))->toBe(Invalida::SIN_PERMISO)
        ->and(log2_error(fn () => $servicio->registrar(log2_asesor(), $id, 'cobrar_al_entregar', null)))->toBe(Invalida::SIN_PERMISO)
        ->and(log2_error(fn () => $servicio->registrar(f6_credito(), $id, 'credito_autorizado', '  ')))->toBe(Invalida::MOTIVO_CREDITO_REQUERIDO)
        ->and(log2_error(fn () => $servicio->registrar(f6_credito(), $id, 'regalo', 'x')))->toBe(Invalida::DISPOSICION_COBRO_INVALIDA);

    $jefe = f6_credito();
    $d = $servicio->registrar($jefe, $id, 'credito_autorizado', 'Autoriza Camilo: paga el viernes');
    $guia = app(DespachoService::class)->despachar(log2_bodega(), log2_s($e))->guia;

    expect((float) $guia->recaudo_esperado)->toBe(0.0)
        ->and($guia->disposicion_cobro_id)->toBe($d->id)
        ->and($d->actor_admin_id)->toBe((int) $jefe->id)
        ->and($d->motivo)->toBe('Autoriza Camilo: paga el viernes')
        ->and((float) $d->saldo_referencia)->toBe(100000.0)
        ->and(log2_error(fn () => $d->update(['motivo' => 'otro'])))->toBe(Invalida::REGISTRO_INMUTABLE)
        // El saldo sigue en Cartera: el credito no es un pago.
        ->and(f6_saldo($id))->toBe(100000.0);
});

test('F6-4 si el pago cambia la decision queda vieja: pago reportado → decidir de nuevo; reportado ≠ verificado; rechazo y confirmacion', function () {
    $e = f6_pedido();
    $id = (int) $e['pedido']->id;
    $bodega = log2_bodega();
    $servicio = app(DisposicionCobroService::class);
    $servicio->registrar($bodega, $id, 'cobrar_al_entregar', null);

    // Se reporta un pago de 40.000 (sin revisar): saldo 60.000, por confirmar sigue en 100.000 (lo reportado no es verificado).
    $pago = f6_registrarPago($this, $e, '40000');
    $s = $servicio->situacion($id, $bodega);

    expect($s['por_confirmar'])->toBe(100000.0)->and($s['vigente'])->toBeNull()->and($s['anterior']['tipo'])->toBe('cobrar_al_entregar')
        ->and(log2_error(fn () => app(DespachoService::class)->despachar($bodega, log2_s($e))))->toBe(Invalida::DISPOSICION_COBRO_REQUERIDA)
        // Cobrar al entregar con un pago en validacion: el valor a cobrar es incierto.
        ->and(log2_error(fn () => $servicio->registrar($bodega, $id, 'cobrar_al_entregar', null)))->toBe(Invalida::PAGO_PENDIENTE_CONFIRMAR);

    // Rechazo: el saldo vuelve a 100.000 y sin pagos en validacion, la situacion es la misma de la decision → vuelve a valer.
    app(RechazarPagoService::class)->rechazar($this->admin, $pago, 'no_encontrado', 'No llego a la cuenta');
    expect($servicio->situacion($id)['vigente']['tipo'] ?? null)->toBe('cobrar_al_entregar');

    // Pago confirmado por el total: ya no hay nada por confirmar → no necesita decision y sale sin recaudo.
    // La fixture del pedido no trae factura; confirmar crea la Transaction sobre la factura (si ya existe una, se usa esa).
    if (! DB::table('invoices')->where('order_id', $id)->exists()) {
        pagb_factura($e['pedido']);
    }
    $total = f6_registrarPago($this, $e, '100000');
    app(ConfirmarPagoService::class)->confirmar($this->admin, $total);

    expect($servicio->situacion($id)['requiere'])->toBeFalse();
    $guia = app(DespachoService::class)->despachar($bodega, log2_s($e))->guia;
    expect((float) $guia->recaudo_esperado)->toBe(0.0)->and($guia->disposicion_cobro_id)->toBeNull();
});

test('F6-5 contra entrega no necesita decision (el metodo ya la tomo); la decision no aplica a un pedido sin saldo', function () {
    $cod = log2_pedido(2, ['pago' => 'cashondelivery', 'total' => 51000.0]);

    expect(app(DisposicionCobroService::class)->situacion((int) $cod['pedido']->id)['requiere'])->toBeFalse()
        ->and(log2_error(fn () => app(DisposicionCobroService::class)->registrar(log2_bodega(), (int) $cod['pedido']->id, 'cobrar_al_entregar', null)))->toBe(Invalida::DISPOSICION_NO_APLICA);

    $guia = app(DespachoService::class)->despachar(log2_bodega(), log2_s($cod))->guia;
    expect((float) $guia->recaudo_esperado)->toBe(51000.0)->and($guia->disposicion_cobro_id)->toBeNull();
});

test('F6-6 HTTP: bodega decide «cobrar al entregar» por la ruta; el credito sin la clave da 403 y no escribe', function () {
    $e = f6_pedido();
    $url = route('admin.logistica.despachos.disposicion_cobro', $e['pedido']->id);

    $this->actingAs(log2_bodega(), 'admin')->postJson($url, ['tipo' => 'credito_autorizado', 'motivo' => 'Quiero darle credito'])
        ->assertStatus(403)->assertJsonPath('codigo', Invalida::SIN_PERMISO);
    expect(DisposicionCobro::query()->where('order_id', $e['pedido']->id)->count())->toBe(0);

    $this->actingAs(log2_bodega(), 'admin')->postJson($url, ['tipo' => 'cobrar_al_entregar'])
        ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('situacion.vigente.tipo', 'cobrar_al_entregar');

    $this->actingAs(f6_credito(), 'admin')->getJson(route('admin.logistica.despachos.contexto', $e['pedido']->id))
        ->assertOk()->assertJsonPath('contexto.disposicion_cobro.permisos.credito', true)
        ->assertJsonPath('contexto.envio.pago_pendiente.verificado', 0);
});

function f6_saldo(int $orderId): float
{
    return (float) app(CarteraConsultaService::class)->saldosDePedidos([$orderId])[$orderId]->saldo;
}
