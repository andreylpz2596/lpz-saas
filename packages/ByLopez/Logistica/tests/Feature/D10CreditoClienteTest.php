<?php

use ByLopez\Logistica\DataObjects\SolicitudEventoEnvio;
use ByLopez\Logistica\DataObjects\SolicitudRecepcion;
use ByLopez\Logistica\Enums\FuenteEventoLogistico as Fuente;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as InvalidaLog;
use ByLopez\Logistica\Models\RecepcionDevolucion;
use ByLopez\Logistica\Services\Entregas\AnularEventoLogisticoService;
use ByLopez\Logistica\Services\Entregas\RecibirDevolucionService;
use ByLopez\Logistica\Services\Entregas\RegistrarRetornoTransportadoraService;
use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Exceptions\OperacionCreditoInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Exceptions\RevisionPagoInvalidaException;
use ByLopez\VentasAsesor\Models\AjusteObligacion;
use ByLopez\VentasAsesor\Models\CreditoClienteMovimiento;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Models\PagoEvento;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Services\Cartera\ConfirmarPagoService;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarPagoService;
use ByLopez\VentasAsesor\Services\Credito\AjusteObligacionService;
use ByLopez\VentasAsesor\Services\Credito\AplicarCreditoService;
use ByLopez\VentasAsesor\Services\Credito\CreditoClienteService;
use ByLopez\VentasAsesor\Services\Credito\ReembolsarCreditoService;
use ByLopez\VentasAsesor\Services\Credito\ReversarAplicacionCreditoService;
use ByLopez\VentasAsesor\Services\Rentabilidad\PanelMensualService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\Sales\Repositories\ShipmentRepository;

require_once __DIR__.'/../../../VentasAsesor/tests/helpersRt04b1a.php';
require_once __DIR__.'/../../../VentasAsesor/tests/helpersFase03b.php';
require_once __DIR__.'/../../../VentasAsesor/tests/helpersPagB.php';
require_once __DIR__.'/../../../VentasAsesor/tests/helpersD10.php';

uses(TestCase::class);

/*
| D10 V1 · saldo a favor del cliente (credito): aplicar, reversar (PAG-I), reembolsar, ajustes de obligacion y protecciones.
| Solo lpz_saas_testing; todo dentro de la transaccion de la prueba.
|
| Escenario base: el pedido A vale 70.000 (50.000 de mercancia + 20.000 de flete), el cliente lo PAGA completo (transferencia
| confirmada, dinero real recibido) y se condona el flete (error de ByLopez) → saldo a favor de 20.000 en A. El pedido B vale 50.000.
*/


test('D10-01 aplicar credito: B baja a 30.000, el credito de A queda en 0 y no hay caja, Transaction ni C6', function () {
    $e = d10_escenario();

    expect(d10_credito($e['a']->id))->toMatchArray(['generado' => 20000.0, 'disponible' => 20000.0, 'recibido' => 70000.0]);
    $mov = d10_aplicar($e);

    expect($mov['duplicado'])->toBeFalse()
        ->and(d10_saldo($e['b']->id)->saldo)->toBe(30000.0)
        ->and(d10_credito($e['a']->id)['disponible'])->toBe(0.0)
        ->and(d10_credito($e['a']->id)['aplicado'])->toBe(20000.0)
        ->and(d10_transacciones($e['b']->id))->toBe(0.0)
        ->and(DB::table('bylopez_lg_cuenta_movimientos')->where('order_id', $e['b']->id)->count())->toBe(0);

    $pago = Pago::query()->findOrFail($mov['pago_id']);
    expect($pago->medio_cobro)->toBe('credito_cliente')->and($pago->estado)->toBe(EstadoPago::CONFIRMADO)->and($pago->guia_id)->toBeNull()
        ->and(DB::table('bylopez_va_aplicaciones_pago')->where('pago_id', $pago->id)->value('order_transaction_id'))->toBeNull()
        ->and(d10_saldo($e['b']->id)->creditoAplicado)->toBe(20000.0);
});

test('D10-02 credito + dinero real: B queda en 0, el dinero nuevo es solo 30.000 y nunca 50.000; la comision considera la obligacion cubierta y al reversar se reevalua', function () {
    $e = d10_escenario();
    $mov = d10_aplicar($e);
    $cobro = ['saldo_pendiente', 'pagos_sin_revisar', 'cartera_requiere_revision', 'fuera_de_cartera'];
    $motivos = fn () => array_values(array_intersect(app(\ByLopez\VentasAsesor\Services\Comisiones\ElegibilidadComisionService::class)->dePedido((int) $e['b']->id)['motivos'], $cobro));

    expect($motivos())->toBe(['saldo_pendiente']);

    d10_pagar($e['admin'], $e['b']->id, '30000');

    // Con credito (origen: dinero real verificado) + transferencia la obligacion queda cubierta: ningun motivo de COBRO bloquea.
    expect($motivos())->toBe([]);

    app(ReversarAplicacionCreditoService::class)->reversar($e['admin'], $mov['movimiento_id'], 'Prueba', 'd10-rev-'.Str::uuid());

    expect($motivos())->toBe(['saldo_pendiente']);
    app(AplicarCreditoService::class)->aplicar($e['admin'], $e['a']->id, $e['b']->id, '20000', 'd10-apl-'.Str::uuid());

    $s = d10_saldo($e['b']->id);

    expect($s->saldo)->toBe(0.0)->and($s->verificado)->toBe(50000.0)->and($s->creditoAplicado)->toBe(20000.0)
        ->and($s->toArray()['dinero_real_verificado'])->toBe(30000.0)
        ->and(d10_transacciones($e['b']->id))->toBe(30000.0);
});

test('D10-09 / D10-10 factura satisfecha sin Transaction por el credito; reportes: recaudo directo solo 30.000 y credito aparte', function () {
    $e = d10_escenario();
    $panel = app(PanelMensualService::class);
    $mes = now()->format('Y-m');
    $antes = $panel->mes($mes);

    d10_aplicar($e);
    expect(DB::table('invoices')->where('order_id', $e['b']->id)->value('state'))->toBe('pending');

    d10_pagar($e['admin'], $e['b']->id, '30000');
    $despues = $panel->mes($mes);

    expect(DB::table('invoices')->where('order_id', $e['b']->id)->value('state'))->toBe('paid')
        ->and(DB::table('order_transactions')->where('order_id', $e['b']->id)->count())->toBe(1)
        ->and((float) $despues['credito_aplicado'] - (float) $antes['credito_aplicado'])->toBe(20000.0)
        ->and((float) $despues['recaudo_directo'] - (float) $antes['recaudo_directo'])->toBe(30000.0);
});

test('D10-03 reverso PAG-I: el origen recupera 20.000, B vuelve a deber, la factura vuelve a pending y el historial queda intacto', function () {
    $e = d10_escenario();
    $mov = d10_aplicar($e);
    d10_pagar($e['admin'], $e['b']->id, '30000');
    $transaccionesAntes = DB::table('order_transactions')->count();

    expect(d10_error(fn () => app(ReversarAplicacionCreditoService::class)->reversar($e['admin'], $mov['movimiento_id'], '', 'd10-rev-'.Str::uuid())))->toBe(Rechazo::MOTIVO_REQUERIDO);

    $r = app(ReversarAplicacionCreditoService::class)->reversar($e['admin'], $mov['movimiento_id'], 'El cliente cambio de pedido', 'd10-rev-'.Str::uuid());

    $pago = Pago::query()->findOrFail($mov['pago_id']);
    expect($r['duplicado'])->toBeFalse()
        ->and(d10_credito($e['a']->id)['disponible'])->toBe(20000.0)
        ->and(d10_saldo($e['b']->id)->saldo)->toBe(20000.0)
        ->and(DB::table('invoices')->where('order_id', $e['b']->id)->value('state'))->toBe('pending')
        ->and($pago->estado)->toBe(EstadoPago::REVERSADO)->and((float) $pago->monto)->toBe(20000.0)
        ->and(DB::table('bylopez_va_aplicaciones_pago')->where('pago_id', $pago->id)->whereNull('anulada_en')->count())->toBe(0)
        ->and(PagoEvento::query()->where('pago_id', $pago->id)->pluck('tipo')->map(fn ($t) => $t->value)->all())->toBe(['registrado', 'confirmado', 'reversado'])
        ->and(DB::table('order_transactions')->count())->toBe($transaccionesAntes)
        ->and(DB::table('refunds')->where('order_id', $e['b']->id)->count())->toBe(0)
        ->and(DB::table('bylopez_va_creditos_cliente_movimientos')->where('order_origen_id', $e['a']->id)->pluck('tipo')->all())->toBe(['aplicacion', 'reverso_aplicacion'])
        ->and(d10_error(fn () => app(ReversarAplicacionCreditoService::class)->reversar($e['admin'], $mov['movimiento_id'], 'otra vez', 'd10-rev-'.Str::uuid())))->toBe(Rechazo::YA_REVERSADO)
        ->and(fn () => CreditoClienteMovimiento::query()->find($mov['movimiento_id'])->update(['motivo' => 'x']))->toThrow(Rechazo::class);
});

test('D10-04 doble consumo: aplicacion y reembolso del mismo credito, solo gana uno y el disponible nunca queda negativo', function () {
    $e = d10_escenario();
    d10_aplicar($e);

    expect(d10_error(fn () => app(ReembolsarCreditoService::class)->reembolsar($e['admin'], $e['a']->id, '20000', 'efectivo', null, 'Cliente lo pidio', 'd10-ree-'.Str::uuid())))->toBe(Rechazo::CREDITO_INSUFICIENTE)
        ->and(d10_error(fn () => d10_aplicar($e)))->toBe(Rechazo::CREDITO_INSUFICIENTE)
        ->and(d10_credito($e['a']->id)['disponible'])->toBe(0.0)
        ->and(DB::table('bylopez_va_creditos_cliente_movimientos')->where('order_origen_id', $e['a']->id)->count())->toBe(1);
});

test('D10-05 cancelar B con credito aplicado: bloqueado (incluida la via nativa) hasta reversar', function () {
    $e = d10_escenario();
    $mov = d10_aplicar($e);
    $pedido = \Webkul\Sales\Models\Order::query()->findOrFail($e['b']->id);

    expect(d10_error(fn () => Event::dispatch('sales.order.cancel.before', $pedido)))->toBe(Rechazo::CREDITO_APLICADO_ACTIVO);

    app(ReversarAplicacionCreditoService::class)->reversar($e['admin'], $mov['movimiento_id'], 'Se cancela el pedido', 'd10-rev-'.Str::uuid());

    expect(d10_error(fn () => Event::dispatch('sales.order.cancel.before', $pedido)))->toBeNull();
});

test('D10-07 custodia: el dinero origen sigue con el transportador → ni se aplica ni se reembolsa', function () {
    $e = d10_escenario('contraentrega_recaudo');
    $c = d10_credito($e['a']->id);

    expect($c['economico'])->toBe(20000.0)->and($c['generado'])->toBe(0.0)->and($c['en_custodia'])->toBe(70000.0)->and($c['disponible'])->toBe(0.0)
        ->and(d10_error(fn () => app(ReembolsarCreditoService::class)->reembolsar($e['admin'], $e['a']->id, '20000', 'efectivo', null, 'Devolver', 'd10-ree-'.Str::uuid())))->toBe(Rechazo::CREDITO_EN_CUSTODIA)
        ->and(d10_error(fn () => d10_aplicar($e)))->toBe(Rechazo::CREDITO_EN_CUSTODIA)
        ->and(DB::table('bylopez_va_creditos_cliente_movimientos')->count())->toBe(0);
});

test('un pago sin_revisar NO genera credito utilizable', function () {
    pagb_corte('2000-01-01');
    $cliente = pagb_cliente();
    $admin = d10_admin();
    $a = d10_pedido($cliente, 70000, 20000);
    app(RegistrarPagoService::class)->registrar($admin, $a->id, ['monto' => '70000', 'medio_cobro' => 'transferencia', 'plataforma' => 'nequi', 'fecha_pago' => now()->toDateString(), 'referencia' => 'SR-1'], 'd10-sr-'.Str::uuid());
    app(AjusteObligacionService::class)->condonarFlete($admin, $a->id, '20000', AjusteObligacion::CAUSA_ERROR_BYLOPEZ, 'Error', 'd10-aju-'.Str::uuid());

    expect(d10_credito($a->id))->toMatchArray(['economico' => 0.0, 'generado' => 0.0, 'disponible' => 0.0])
        ->and(d10_saldo($a->id)->saldoAFavor)->toBe(20000.0);
});

test('D10-13 idempotencia: repetir aplicar, reembolsar y reversar produce un solo efecto financiero', function () {
    $e = d10_escenario();
    $clave = 'd10-idem-aplicar-0001';
    $uno = d10_aplicar($e, '12000', $clave);
    $dos = d10_aplicar($e, '12000', $clave);

    expect($dos['duplicado'])->toBeTrue()->and($dos['movimiento_id'])->toBe($uno['movimiento_id'])
        ->and(DB::table('bylopez_va_pagos')->where('medio_cobro', 'credito_cliente')->count())->toBe(1)
        ->and(d10_error(fn () => d10_aplicar($e, '5000', $clave)))->toBe(Rechazo::IDEMPOTENCIA_CONFLICTO);

    $servicio = app(ReembolsarCreditoService::class);
    $r1 = $servicio->reembolsar($e['admin'], $e['a']->id, '8000', 'efectivo', null, 'Devolver', 'd10-idem-reembolso-01');
    $r2 = $servicio->reembolsar($e['admin'], $e['a']->id, '8000', 'efectivo', null, 'Devolver', 'd10-idem-reembolso-01');

    expect($r2['duplicado'])->toBeTrue()->and($r2['movimiento_id'])->toBe($r1['movimiento_id'])
        ->and(DB::table('bylopez_va_creditos_cliente_movimientos')->where('tipo', 'reembolso')->count())->toBe(1)
        ->and(d10_credito($e['a']->id)['disponible'])->toBe(0.0);

    $rev = app(ReversarAplicacionCreditoService::class);
    $v1 = $rev->reversar($e['admin'], $uno['movimiento_id'], 'Error', 'd10-idem-reverso-0001');
    $v2 = $rev->reversar($e['admin'], $uno['movimiento_id'], 'Error', 'd10-idem-reverso-0001');

    expect($v2['duplicado'])->toBeTrue()->and($v2['reverso_id'])->toBe($v1['reverso_id'])
        ->and(DB::table('bylopez_va_creditos_cliente_movimientos')->where('tipo', 'reverso_aplicacion')->count())->toBe(1)
        ->and(d10_credito($e['a']->id)['disponible'])->toBe(12000.0);
});

test('D10-14 aislamiento: el credito de un cliente no se aplica al pedido de otro', function () {
    $e = d10_escenario();
    $otro = d10_pedido(pagb_cliente(), 50000);

    expect(d10_error(fn () => app(AplicarCreditoService::class)->aplicar($e['admin'], $e['a']->id, $otro->id, '20000', 'd10-otro-cliente-001')))->toBe(Rechazo::CLIENTE_DISTINTO)
        ->and(DB::table('bylopez_va_creditos_cliente_movimientos')->count())->toBe(0);
});

test('autorizacion en el servidor: sin permiso o sin alcance total no se opera credito; credito_cliente no se registra ni confirma a mano', function () {
    $e = d10_escenario();
    $sinPermiso = pagb_asesor(['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.view_all']);
    $propios = pagb_asesor(['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.view_own', 'ventas_asesor.cartera.credito', 'ventas_asesor.cartera.credito.aplicar']);

    expect(d10_error(fn () => app(AplicarCreditoService::class)->aplicar($sinPermiso, $e['a']->id, $e['b']->id, '1000', 'd10-sin-permiso-0001')))->toBe(Rechazo::SIN_PERMISO)
        ->and(d10_error(fn () => app(AplicarCreditoService::class)->aplicar($propios, $e['a']->id, $e['b']->id, '1000', 'd10-propios-0000001')))->toBe(Rechazo::SIN_PERMISO);

    // El medio no es seleccionable: el registro normal lo rechaza.
    $registro = null;

    try {
        app(RegistrarPagoService::class)->registrar($e['admin'], $e['b']->id, ['monto' => '1000', 'medio_cobro' => 'credito_cliente', 'fecha_pago' => now()->toDateString()], 'd10-medio-manual-0001');
    } catch (\ByLopez\VentasAsesor\Exceptions\RegistroPagoInvalidoException $x) {
        $registro = $x->codigo;
    }

    expect($registro)->toBe('medio_cobro_invalido');

    // Y si un pago de ese medio llegara sin_revisar, ConfirmarPagoService lo rechaza (crearia una Transaction = dinero inexistente).
    $mov = d10_aplicar($e);
    DB::table('bylopez_va_pagos')->where('id', $mov['pago_id'])->update(['estado' => 'sin_revisar']);

    expect(d10_error(fn () => app(ConfirmarPagoService::class)->confirmar($e['admin'], $mov['pago_id'])))->toBe(RevisionPagoInvalidaException::ESTADO_NO_REVISABLE);
});

test('ajustes de obligacion: tope del flete, causa y motivo obligatorios, pedido original intacto y reverso que no deja credito negativo', function () {
    pagb_corte('2000-01-01');
    $cliente = pagb_cliente();
    $admin = d10_admin();
    $a = d10_pedido($cliente, 70000, 20000);
    $servicio = app(AjusteObligacionService::class);

    expect(d10_error(fn () => $servicio->condonarFlete($admin, $a->id, '25000', AjusteObligacion::CAUSA_ERROR_BYLOPEZ, 'x', 'd10-aju-exceso-0001')))->toBe(Rechazo::AJUSTE_EXCEDE_FLETE)
        ->and(d10_error(fn () => $servicio->condonarFlete($admin, $a->id, '5000', 'cliente_cambia_de_opinion', 'x', 'd10-aju-causa-0001')))->toBe(Rechazo::CAUSA_INVALIDA)
        ->and(d10_error(fn () => $servicio->condonarFlete($admin, $a->id, '5000', AjusteObligacion::CAUSA_TRANSPORTADORA, '', 'd10-aju-motivo-001')))->toBe(Rechazo::MOTIVO_REQUERIDO);

    $r = $servicio->condonarFlete($admin, $a->id, '20000', AjusteObligacion::CAUSA_TRANSPORTADORA, 'Guia perdida por la transportadora', 'd10-aju-ok-00000001');

    expect(d10_saldo($a->id)->saldo)->toBe(50000.0)->and(d10_saldo($a->id)->total)->toBe(70000.0)
        ->and((float) DB::table('orders')->where('id', $a->id)->value('base_shipping_amount'))->toBe(20000.0)
        ->and($servicio->condonarFlete($admin, $a->id, '20000', AjusteObligacion::CAUSA_TRANSPORTADORA, 'Guia perdida por la transportadora', 'd10-aju-ok-00000001')['duplicado'])->toBeTrue();
});

test('D10-ajuste-reverso: reversar el ajuste que origino credito ya aplicado se rechaza; sin consumo se permite', function () {
    $e = d10_escenario();
    $ajusteId = (int) DB::table('bylopez_va_ajustes_obligacion')->where('order_id', $e['a']->id)->value('id');
    $servicio = app(AjusteObligacionService::class);
    $mov = d10_aplicar($e);

    expect(d10_error(fn () => $servicio->reversar($e['admin'], $ajusteId, 'Error de captura', 'd10-ajr-0000000001')))->toBe(Rechazo::REVERSO_DEJA_CREDITO_NEGATIVO)
        ->and(AjusteObligacion::query()->find($ajusteId)->estado)->toBe('activo');

    app(ReversarAplicacionCreditoService::class)->reversar($e['admin'], $mov['movimiento_id'], 'Regularizar', 'd10-rev-'.Str::uuid());
    $servicio->reversar($e['admin'], $ajusteId, 'Error de captura', 'd10-ajr-0000000002');

    expect(AjusteObligacion::query()->find($ajusteId)->estado)->toBe('reversado')
        ->and(d10_credito($e['a']->id)['economico'])->toBe(0.0)
        ->and(d10_saldo($e['a']->id)->saldo)->toBe(0.0);
});

/** Pedido real despachado (ShipmentRepository) y pagado; con devolucion REGISTRADA para recibirla luego (fuente C5 del credito). */
function d10_envio_c5(int $devuelve): array
{
    Mail::fake();
    pagb_corte('2000-01-01');
    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido();
    $qty = 10;
    $neto = 90000.0;

    $item->update([
        'qty_ordered' => $qty, 'qty_shipped' => 0, 'qty_canceled' => 0, 'qty_refunded' => 0, 'price' => 10000, 'base_price' => 10000,
        'total' => 100000, 'base_total' => 100000, 'discount_amount' => 10000, 'base_discount_amount' => 10000, 'tax_amount' => 0, 'base_tax_amount' => 0,
    ]);
    $pedido->update(['grand_total' => $neto, 'base_grand_total' => $neto]);
    DB::table('order_payment')->where('order_id', $pedido->id)->update(['method' => 'moneytransfer']);
    pagb_factura($pedido, 'pending', $neto);

    $atributo = (int) DB::table('attributes')->where('code', 'manage_stock')->value('id');
    $clave = ['product_id' => $item->product_id, 'attribute_id' => $atributo, 'channel' => 'default'];
    DB::table('product_attribute_values')->where($clave)->exists()
        ? DB::table('product_attribute_values')->where($clave)->update(['boolean_value' => 1])
        : DB::table('product_attribute_values')->insert($clave + ['unique_id' => 'default|'.$item->product_id.'|'.$atributo, 'locale' => null, 'boolean_value' => 1]);
    DB::table('product_inventories')->updateOrInsert(['product_id' => $item->product_id, 'inventory_source_id' => 1, 'vendor_id' => 0], ['qty' => 50]);

    $envio = app(ShipmentRepository::class)->create(['order_id' => $pedido->id, 'shipment' => [
        'carrier_title' => 'BYLOPEZ', 'track_number' => 'GUIA-'.uniqid(), 'source' => 1, 'items' => [$item->id => [1 => $qty]],
    ]]);
    $si = (int) DB::table('shipment_items')->where('shipment_id', $envio->id)->value('id');
    $permisos = ['logistica', 'logistica.entregas', 'logistica.entregas.registrar', 'logistica.entregas.recibir_devolucion', 'logistica.entregas.anular'];
    $r = app(RegistrarRetornoTransportadoraService::class)->registrar(f03b_admin('custom', $permisos), new SolicitudEventoEnvio(
        shipmentId: (int) $envio->id, orderId: (int) $pedido->id, fecha: now()->toDateString(), items: [$si => $devuelve],
        fuente: Fuente::BODEGA_MANUAL, idempotencyKey: 'd10-ret-'.Str::uuid(), motivo: 'Cliente rechaza',
    ));

    return ['pedido' => $pedido, 'envio' => $envio, 'retorno' => $r->registro, 'retorno_item' => (int) $r->registro->items->sole()->id, 'permisos' => $permisos];
}

test('D10-06 C5: generado por la recepcion; anular una recepcion cuyo credito ya se consumio se bloquea, y tras reversar se permite', function () {
    $c = d10_envio_c5(devuelve: 3);
    $admin = d10_admin();
    d10_pagar($admin, $c['pedido']->id, '90000');
    $bodega = f03b_admin('custom', $c['permisos']);

    $recepcion = app(RecibirDevolucionService::class)->recibir($bodega, new SolicitudRecepcion(
        retornoId: (int) $c['retorno']->id, fecha: now()->toDateString(),
        // Unidades defectuosas (no vuelven a vendible): su recepcion SI es anulable; una con aptas reingresadas ya no lo era (otra regla previa).
        lineas: [$c['retorno_item'] => ['recibidas' => 3, 'aptas' => 0, 'novedad' => 3, 'motivo' => '', 'defectos' => [['cantidad' => 3, 'motivo' => 'costura', 'origen' => 'transporte', 'observacion' => 'Descosidas']]]],
        idempotencyKey: 'd10-rec-'.Str::uuid(),
    ))->recepcion;

    // 3 unidades × 9.000 = 27.000 recibidas en bodega y 90.000 pagados → 27.000 de credito.
    expect(d10_credito($c['pedido']->id)['disponible'])->toBe(27000.0)
        // D10: la devolucion C5 recibida ya NO bloquea la comision con `devolucion_sin_politica` (D5/D6 + D10 la cubren).
        ->and(app(\ByLopez\VentasAsesor\Services\Comisiones\ElegibilidadComisionService::class)->dePedido((int) $c['pedido']->id)['motivos'])->not->toContain('devolucion_sin_politica');

    $destino = d10_pedido(DB::table('orders')->where('id', $c['pedido']->id)->value('customer_id') ? \Webkul\Customer\Models\Customer::query()->find($c['pedido']->customer_id) : pagb_cliente(), 40000);
    $mov = app(AplicarCreditoService::class)->aplicar($admin, $c['pedido']->id, $destino->id, '27000', 'd10-c5-aplicar-0001');

    $anular = fn () => app(AnularEventoLogisticoService::class)->anularRecepcion($bodega, (int) $recepcion->id, 'Error de captura');

    expect(d10_error($anular))->toBe(InvalidaLog::RECEPCION_CON_CREDITO_CONSUMIDO)
        ->and(RecepcionDevolucion::query()->find($recepcion->id)->estado->value)->toBe('confirmado')
        ->and(d10_credito($c['pedido']->id)['disponible'])->toBe(0.0);

    app(ReversarAplicacionCreditoService::class)->reversar($admin, $mov['movimiento_id'], 'Regularizar antes de anular', 'd10-rev-'.Str::uuid());

    expect(d10_error($anular))->toBeNull()
        ->and(RecepcionDevolucion::query()->find($recepcion->id)->estado->value)->toBe('anulado');
});

test('D10-08 C1: B con guia cuyo recaudo comprometido supera lo exigible tras aplicar → bloqueado sin crear movimientos', function () {
    $c = d10_envio_c5(devuelve: 0 + 1);
    $e = d10_escenario();
    // B es un pedido DESPACHADO con una guia que cobra 50.000 contra entrega.
    $shipment = (int) $c['envio']->id;
    $b = $c['pedido'];
    DB::table('orders')->where('id', $b->id)->update(['customer_id' => $e['cliente']->id]);
    DB::table('bylopez_lg_guias')->insert([
        'order_id' => $b->id, 'shipment_id' => $shipment, 'transportadora_codigo' => 'prueba', 'numero_guia' => 'G-'.uniqid(), 'origen_guia' => 'manual',
        'flete_pagador' => 'bylopez', 'recaudo_esperado' => 90000, 'recaudo_estado' => 'pendiente_liquidar', 'actor_admin_id' => $e['admin']->id, 'actor_name_snapshot' => 'Prueba',
        'idempotency_key' => 'd10-guia-'.uniqid(), 'huella' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Aplicar 20.000 dejaria exigible 70.000 con 90.000 comprometidos en la guia.
    expect(d10_error(fn () => app(AplicarCreditoService::class)->aplicar($e['admin'], $e['a']->id, $b->id, '20000', 'd10-c1-bloqueo-0001')))->toBe(Rechazo::RECAUDO_COMPROMETIDO)
        ->and(DB::table('bylopez_va_creditos_cliente_movimientos')->count())->toBe(0)
        ->and(DB::table('bylopez_va_pagos')->where('medio_cobro', 'credito_cliente')->count())->toBe(0);
});
