<?php

use ByLopez\Logistica\DataObjects\SolicitudEventoEnvio;
use ByLopez\Logistica\Enums\FuenteEventoLogistico as Fuente;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\ValidacionEntrega;
use ByLopez\Logistica\Services\Entregas\AnularEventoLogisticoService;
use ByLopez\Logistica\Services\Entregas\CobrarEntregaService;
use ByLopez\Logistica\Services\Entregas\ConsultaEntregaService;
use ByLopez\Logistica\Services\Entregas\RegistrarEntregaService;
use ByLopez\Logistica\Services\Entregas\RegistrarRetornoTransportadoraService;
use ByLopez\Logistica\Services\Entregas\ValidarEntregaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\Sales\Repositories\ShipmentRepository;

require_once __DIR__.'/../../../VentasAsesor/tests/helpersRt04b1a.php';
require_once __DIR__.'/../../../VentasAsesor/tests/helpersFase03b.php';
require_once __DIR__.'/../../../VentasAsesor/tests/helpersPagB.php';

uses(TestCase::class);

/*
| Validacion de la entrega por el administrador + cobro contra entrega por Cartera (Entregas · Fase D).
| Entrega REGISTRADA ≠ VALIDADA ≠ PAGADA. Validar no escribe pagos; la factura solo pasa a pagada por Cartera. Solo lpz_saas_testing.
*/

const VD_PERMISOS = ['logistica', 'logistica.entregas', 'logistica.entregas.registrar', 'logistica.entregas.validar'];

beforeEach(function () {
    pagb_corte('2000-01-01');
});

/** Pedido real despachado de `$qty` unidades, con factura PENDIENTE (como contra entrega) por `$total`, listo para validar y cobrar. */
function vd_envio(int $qty = 10, float $total = 500000): array
{
    Mail::fake();
    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido();
    $item->update(['qty_ordered' => $qty, 'qty_shipped' => 0, 'qty_invoiced' => $qty]);
    $pedido->update(['status' => 'pending_payment', 'grand_total' => $total, 'base_grand_total' => $total, 'sub_total' => $total, 'base_sub_total' => $total, 'base_grand_total_refunded' => 0]);
    $factura = pagb_factura($pedido->fresh(), 'pending', $total);
    DB::table('order_payment')->insert(['order_id' => $pedido->id, 'method' => 'cashondelivery', 'method_title' => 'Pago contra entrega', 'created_at' => now(), 'updated_at' => now()]);

    $atributo = (int) DB::table('attributes')->where('code', 'manage_stock')->value('id');
    DB::table('product_attribute_values')->where(['product_id' => $item->product_id, 'attribute_id' => $atributo])->update(['boolean_value' => 1]);
    DB::table('product_inventories')->updateOrInsert(['product_id' => $item->product_id, 'inventory_source_id' => 1, 'vendor_id' => 0], ['qty' => 50]);

    $envio = app(ShipmentRepository::class)->create(['order_id' => $pedido->id, 'shipment' => [
        'carrier_title' => 'BYLOPEZ', 'track_number' => 'GUIA-'.uniqid(), 'source' => 1, 'items' => [$item->id => [1 => $qty]],
    ]]);

    return ['pedido' => $pedido->fresh(), 'envio' => $envio->fresh(), 'si' => (int) DB::table('shipment_items')->where('shipment_id', $envio->id)->value('id'), 'factura' => $factura, 'qty' => $qty];
}

function vd_admin(array $permisos = VD_PERMISOS)
{
    return f03b_admin('custom', $permisos);
}

function vd_evento(string $servicio, array $e, int $unidades, string $extra = 'x'): object
{
    return app($servicio)->registrar(vd_admin(), new SolicitudEventoEnvio(
        shipmentId: (int) $e['envio']->id, orderId: (int) $e['pedido']->id, fecha: now()->toDateString(), items: [$e['si'] => $unidades],
        fuente: Fuente::BODEGA_MANUAL, idempotencyKey: 'vd-'.$extra.'-'.Str::uuid(), motivo: $servicio === RegistrarRetornoTransportadoraService::class ? 'Cliente ausente' : null,
    ))->registro;
}

function vd_entregar(array $e, ?int $unidades = null): object
{
    return vd_evento(RegistrarEntregaService::class, $e, $unidades ?? $e['qty'], 'ent');
}

function vd_error(callable $accion): ?string
{
    try {
        $accion();
    } catch (Invalida $x) {
        return $x->codigo;
    }

    return null;
}

function vd_estado(array $e): string
{
    return app(ConsultaEntregaService::class)->envio((int) $e['envio']->id)['validacion'];
}

function vd_validar(array $e, ?object $actor = null, ?string $clave = null, ?string $obs = null): array
{
    return app(ValidarEntregaService::class)->validar($actor ?? vd_admin(), (int) $e['envio']->id, $clave ?? 'vd-val-'.Str::uuid(), $obs);
}

test('VD-1 solo se valida una entrega completa: sin entregas, con pendientes o con devolucion sin recibir se rechaza', function () {
    $e = vd_envio(10);

    expect(vd_error(fn () => vd_validar($e)))->toBe(Invalida::VALIDACION_SIN_ENTREGAS);

    vd_entregar($e, 6);
    expect(vd_error(fn () => vd_validar($e)))->toBe(Invalida::VALIDACION_CON_PENDIENTES)
        ->and(vd_estado($e))->toBe('registrada');

    // 4 restantes devueltas: el envio queda cerrado, pero la devolucion sigue sin recibir en Bodega.
    vd_evento(RegistrarRetornoTransportadoraService::class, $e, 4, 'ret');
    expect(vd_error(fn () => vd_validar($e)))->toBe(Invalida::VALIDACION_DEVOLUCION_SIN_RECIBIR)
        ->and(vd_estado($e))->toBe('registrada')
        ->and(ValidacionEntrega::query()->count())->toBe(0);
});

test('VD-2 entrega completa: pasa de «por validar» a «validada», es idempotente y no escribe pagos ni toca la factura', function () {
    $e = vd_envio(10);
    vd_entregar($e);

    expect(vd_estado($e))->toBe('pendiente_confirmar')
        ->and(app(ConsultaEntregaService::class)->indicadores()['por_validar'])->toBe(1);

    $clave = 'vd-val-'.Str::uuid();
    $admin = vd_admin();
    $r = vd_validar($e, $admin, $clave, 'Confirmado con el cliente');

    expect($r['ya_existia'])->toBeFalse()
        ->and($r['validacion']->entregadas)->toBe(10)
        ->and($r['validacion']->actor_admin_id)->toBe((int) $admin->id)
        ->and(vd_estado($e))->toBe('confirmado')
        ->and(app(ConsultaEntregaService::class)->indicadores()['por_validar'])->toBe(0)
        // Validar NO es cobrar: la factura y el pedido siguen pendientes de pago, y no hay pagos ni transacciones.
        ->and(DB::table('invoices')->where('id', $e['factura'])->value('state'))->toBe('pending')
        ->and(DB::table('orders')->where('id', $e['pedido']->id)->value('status'))->toBe('pending_payment')
        ->and(DB::table('order_transactions')->where('order_id', $e['pedido']->id)->count())->toBe(0)
        ->and(DB::table('bylopez_va_pagos')->count())->toBe(0);

    // Reintento / doble clic con la misma clave: el mismo registro. Otra clave: ya validada.
    $otra = vd_validar($e, $admin, $clave, 'Confirmado con el cliente');
    expect($otra['ya_existia'])->toBeTrue()->and($otra['validacion']->id)->toBe($r['validacion']->id)
        ->and(vd_error(fn () => vd_validar($e, $admin, $clave, 'Otro texto')))->toBe(Invalida::IDEMPOTENCIA_CONFLICTO)
        ->and(vd_error(fn () => vd_validar($e, $admin)))->toBe(Invalida::VALIDACION_YA_REGISTRADA)
        ->and(ValidacionEntrega::query()->count())->toBe(1);
});

test('VD-3 permiso: sin logistica.entregas.validar no se valida ni se cobra', function () {
    $e = vd_envio(4);
    vd_entregar($e);
    $sin = vd_admin(['logistica', 'logistica.entregas', 'logistica.entregas.registrar']);

    expect(vd_error(fn () => vd_validar($e, $sin)))->toBe(Invalida::SIN_PERMISO)
        ->and(vd_error(fn () => app(CobrarEntregaService::class)->cobrar($sin, (int) $e['envio']->id, '100', 'efectivo', null, null, 'vd-cobro-'.Str::uuid())))->toBe(Invalida::SIN_PERMISO)
        ->and(ValidacionEntrega::query()->count())->toBe(0);
});

test('VD-4 anular la validacion: motivo obligatorio, queda «por validar», se puede validar de nuevo y mientras estuvo validada no se anula la entrega', function () {
    $e = vd_envio(5);
    $entrega = vd_entregar($e);
    $admin = vd_admin(array_merge(VD_PERMISOS, ['logistica.entregas.anular']));
    $v = vd_validar($e, $admin)['validacion'];

    // Con la validacion vigente, la entrega NO se anula (la comision depende de ella).
    expect(vd_error(fn () => app(AnularEventoLogisticoService::class)->anularEntrega($admin, (int) $entrega->id, 'error')))->toBe(Invalida::ENVIO_VALIDADO);

    expect(vd_error(fn () => app(ValidarEntregaService::class)->anular($admin, (int) $v->id, '  ')))->toBe(Invalida::MOTIVO_REQUERIDO);

    $anulada = app(ValidarEntregaService::class)->anular($admin, (int) $v->id, 'Validada por error');

    expect($anulada->vigente())->toBeFalse()->and($anulada->anulada_por_admin_id)->toBe((int) $admin->id)
        ->and(vd_estado($e))->toBe('pendiente_confirmar')
        ->and(vd_error(fn () => app(ValidarEntregaService::class)->anular($admin, (int) $v->id, 'otra vez')))->toBe(Invalida::YA_ANULADO)
        ->and(vd_validar($e, $admin)['validacion']->id)->not->toBe((int) $v->id)
        ->and(vd_estado($e))->toBe('confirmado');
});

test('VD-5 cobro contra entrega por Cartera: la factura pasa a pagada, el pedido se recalcula y repetir no duplica', function () {
    $e = vd_envio(10, 500000);
    vd_entregar($e);
    $admin = f03b_admin('all');
    app(ValidarEntregaService::class)->validar($admin, (int) $e['envio']->id, 'vd-val-'.Str::uuid());

    $cobrar = fn (string $monto, string $clave) => app(CobrarEntregaService::class)->cobrar($admin, (int) $e['envio']->id, $monto, 'contraentrega_recaudo', null, null, $clave);

    // Un cobro mayor al saldo lo rechaza Cartera (no se fuerza nada).
    expect(vd_error(fn () => $cobrar('600000', 'vd-cobro-'.Str::uuid())))->toBe(Invalida::COBRO_RECHAZADO)
        ->and(DB::table('invoices')->where('id', $e['factura'])->value('state'))->toBe('pending');

    $clave = 'vd-cobro-'.Str::uuid();
    $r = $cobrar('500000', $clave);

    expect($r['confirmado'])->toBeTrue()->and($r['duplicado'])->toBeFalse()
        ->and(DB::table('invoices')->where('id', $e['factura'])->value('state'))->toBe('paid')
        ->and(DB::table('order_transactions')->where('order_id', $e['pedido']->id)->count())->toBe(1)
        ->and(DB::table('orders')->where('id', $e['pedido']->id)->value('status'))->toBe('completed');

    // Reintento con la misma clave: el mismo pago, ninguna segunda Transaction.
    $otra = $cobrar('500000', $clave);

    expect($otra['duplicado'])->toBeTrue()->and($otra['pago_id'])->toBe($r['pago_id'])
        ->and(DB::table('order_transactions')->where('order_id', $e['pedido']->id)->count())->toBe(1)
        ->and(DB::table('bylopez_va_pagos')->count())->toBe(1);
});

test('VD-6 el cobro exige una entrega y respeta los permisos de Cartera: sin registrar en Cartera queda rechazado y sin pago', function () {
    $e = vd_envio(4);
    $sinCartera = vd_admin();

    expect(vd_error(fn () => app(CobrarEntregaService::class)->cobrar($sinCartera, (int) $e['envio']->id, '100', 'efectivo', null, null, 'vd-cobro-'.Str::uuid())))->toBe(Invalida::VALIDACION_SIN_ENTREGAS);

    vd_entregar($e);

    expect(vd_error(fn () => app(CobrarEntregaService::class)->cobrar($sinCartera, (int) $e['envio']->id, '100', 'efectivo', null, null, 'vd-cobro-'.Str::uuid())))->toBe(Invalida::COBRO_RECHAZADO)
        ->and(DB::table('bylopez_va_pagos')->count())->toBe(0)
        ->and(DB::table('order_transactions')->where('order_id', $e['pedido']->id)->count())->toBe(0);
});

test('VD-7 drawer JSON y bandeja: contexto con cierre, validar y cobrar por HTTP, filtro «por validar» y permisos de ruta', function () {
    $e = vd_envio(6, 300000);
    vd_entregar($e);
    $admin = f03b_admin('all');
    $sinAcceso = f03b_admin('custom', ['ventas_asesor', 'ventas_asesor.operar_como_asesor']);
    $ctx = route('admin.logistica.entregas.drawer.contexto', $e['envio']->id);
    $solo = f03b_admin('custom', ['logistica', 'logistica.entregas', 'logistica.entregas.registrar']);

    $json = $this->actingAs($admin, 'admin')->getJson($ctx)->assertOk()->json('contexto');

    expect($json['lista_para_validar'])->toBeTrue()->and($json['validada'])->toBeNull()
        ->and($json['permisos']['validar'])->toBeTrue()
        ->and($json['cierre']['pedido_estado'])->toBe('pending_payment')->and($json['cierre']['factura_estado'])->toBe('pending')
        ->and($json['cierre']['cobro']['disponible'])->toBeTrue()->and($json['cierre']['cobro']['saldo'])->toBe(300000)
        ->and(collect($json['cierre']['cobro']['medios'])->pluck('codigo')->all())->toContain('contraentrega_recaudo')->not->toContain('transferencia');

    // La bandeja lista el envio en «por validar» y ofrece el boton Validar.
    $html = $this->actingAs($admin, 'admin')->get(route('admin.logistica.entregas.index', ['estado' => 'por_validar', 'pedido' => $e['pedido']->id]))->assertOk()->getContent();
    expect($html)->toContain('data-modo="validar"');

    // ACL por ruta: sin la clave no se valida (401) y un asesor ni ve el contexto.
    $this->actingAs($sinAcceso, 'admin')->getJson($ctx)->assertStatus(401);
    $this->actingAs($solo, 'admin')->postJson(route('admin.logistica.entregas.drawer.validar', $e['envio']->id), ['idempotency_key' => 'vd-http-'.Str::uuid()])->assertStatus(401);

    $clave = 'vd-http-'.Str::uuid();
    $url = route('admin.logistica.entregas.drawer.validar', $e['envio']->id);
    $this->actingAs($admin, 'admin')->postJson($url, ['idempotency_key' => $clave, 'observacion' => 'ok'])
        ->assertOk()->assertJsonPath('ya_existia', false)->assertJsonPath('contexto.validacion', 'confirmado')->assertJsonPath('contexto.validada.actor', $admin->name);
    $this->actingAs($admin, 'admin')->postJson($url, ['idempotency_key' => $clave, 'observacion' => 'ok'])->assertOk()->assertJsonPath('ya_existia', true);
    $this->actingAs($admin, 'admin')->postJson($url, ['idempotency_key' => 'vd-http-'.Str::uuid()])->assertStatus(409)->assertJsonPath('codigo', Invalida::VALIDACION_YA_REGISTRADA);

    // Cobro por HTTP: factura pagada y el contexto lo refleja.
    $cobro = ['monto' => '300000', 'medio_cobro' => 'contraentrega_recaudo', 'idempotency_key' => 'vd-cobro-'.Str::uuid()];
    $json = $this->actingAs($admin, 'admin')->postJson(route('admin.logistica.entregas.drawer.cobrar', $e['envio']->id), $cobro)
        ->assertOk()->assertJsonPath('contexto.cierre.factura_estado', 'paid')->json('contexto');

    expect($json['cierre']['cobro']['disponible'])->toBeFalse()->and($json['cierre']['cobro']['motivo'])->toBe('sin_saldo');

    // Anular la validacion desde el drawer.
    $this->actingAs($admin, 'admin')->postJson(route('admin.logistica.entregas.drawer.anular_validacion', ValidacionEntrega::query()->value('id')), ['motivo' => 'Prueba'])
        ->assertOk()->assertJsonPath('contexto.validacion', 'pendiente_confirmar');
});
