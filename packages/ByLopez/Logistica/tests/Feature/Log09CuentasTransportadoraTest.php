<?php

use ByLopez\Logistica\Contracts\PagosPendientesDeConfirmar;
use ByLopez\Logistica\DataObjects\SolicitudEventoEnvio;
use ByLopez\Logistica\Enums\EstadoMovimientoCuenta;
use ByLopez\Logistica\Enums\FuenteEventoLogistico as Fuente;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Models\LiquidacionTransportadora;
use ByLopez\Logistica\Models\MovimientoCuenta;
use ByLopez\Logistica\Models\Transportadora;
use ByLopez\Logistica\Services\Cuentas\AjusteCuentaService;
use ByLopez\Logistica\Services\Cuentas\ConsultaCuentasService;
use ByLopez\Logistica\Services\Cuentas\GeneradorMovimientosCuenta;
use ByLopez\Logistica\Services\Cuentas\LiquidarCuentaService;
use ByLopez\Logistica\Services\Entregas\AnularEventoLogisticoService;
use ByLopez\Logistica\Services\Entregas\RegistrarEntregaService;
use ByLopez\Logistica\Services\Entregas\RegistrarRetornoTransportadoraService;
use ByLopez\Logistica\Services\Guias\CorregirGuiaService;
use ByLopez\Logistica\Support\SaldoCuenta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\Sales\Models\OrderItem;

require_once __DIR__.'/../helpersDespacho.php';
require_once __DIR__.'/../helpersDomicilios.php';

uses(TestCase::class);

/*
| Cuentas corrientes de transportadoras (2026-10-14): recaudo y servicio nacen de la ENTREGA (idempotentes, por capacidad),
| liquidacion inmutable con diferencia trasladada, ajustes solo Admin. Solo lpz_saas_testing, datos de fixture.
*/

const LOG9_ADMIN = ['logistica', 'logistica.cuentas', 'logistica.cuentas.liquidar', 'logistica.cuentas.ajustar', 'logistica.entregas', 'logistica.entregas.registrar', 'logistica.entregas.anular', 'logistica.guias', 'logistica.guias.corregir'];

function log9_preparar(): array
{
    // Sin pagos en validacion: el despacho contra entrega no se bloquea (Cartera se prueba aparte).
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

/** Pedido despachado con la transportadora dada. Jeison cobra 10.000 en Bogota; Brian 12.000. */
function log9_envio(Transportadora $t, array $o = []): array
{
    $o += ['qty' => 2, 'envio' => 'domicilio_local', 'pago' => 'cashondelivery', 'total' => 51000.0, 'recaudo' => '51000', 'extra' => []];
    $e = log2_pedido($o['qty'], ['envio' => $o['envio'], 'pago' => $o['pago'], 'total' => $o['total']]);
    $guia = log6_despachar($e, $t, $o['extra'] + ['recaudoEsperado' => $o['recaudo']]);
    $items = DB::table('shipment_items')->where('shipment_id', $guia->shipment_id)->whereNotNull('order_item_id')->where('qty', '>', 0)->pluck('qty', 'id')->map(fn ($q) => (int) $q)->all();

    return ['pedido' => $e['pedido'], 'guia' => $guia, 'envio' => (int) $guia->shipment_id, 'items' => $items];
}

function log9_admin()
{
    return f03b_admin('custom', LOG9_ADMIN);
}

function log9_evento(string $servicio, array $e, ?array $items = null, ?string $clave = null): object
{
    return app($servicio)->registrar(log9_admin(), new SolicitudEventoEnvio(
        shipmentId: $e['envio'], orderId: (int) $e['pedido']->id, fecha: now()->toDateString(), items: $items ?? $e['items'],
        fuente: Fuente::SUPERVISOR_MANUAL, idempotencyKey: $clave ?? 'log9-'.Str::uuid(),
        motivo: $servicio === RegistrarRetornoTransportadoraService::class ? 'Cliente ausente' : null,
    ))->registro;
}

function log9_entregar(array $e, ?array $items = null, ?string $clave = null): object
{
    return log9_evento(RegistrarEntregaService::class, $e, $items, $clave);
}

/** @return array<string, float> importe vigente por concepto de un envio */
function log9_movs(int $envio): array
{
    return MovimientoCuenta::query()->where('shipment_id', $envio)->get()->groupBy(fn ($m) => $m->concepto->value)
        ->map(fn ($g) => round((float) $g->sum('importe'), 2))->sortKeys()->all();
}

function log9_saldo(Transportadora $t): float
{
    return round((float) MovimientoCuenta::query()->where('transportadora_id', $t->id)->where('estado', 'abierto')->sum('importe'), 2);
}

function log9_liquidar(Transportadora $t, string $valor, array $extra = [], $actor = null): LiquidacionTransportadora
{
    $ids = MovimientoCuenta::query()->where('transportadora_id', $t->id)->where('estado', 'abierto')->pluck('id')->all();

    return app(LiquidarCuentaService::class)->liquidar($actor ?? log9_admin(), (int) $t->id, $extra + ['movimientos' => $ids, 'valor' => $valor, 'idempotency_key' => 'liq-'.Str::uuid()]);
}

test('CC-1 CASO A contraentrega: despachar no genera dinero; la entrega genera +51.000 y -10.000 → «debe entregar $ 41.000»', function () {
    $c = log9_preparar();
    $e = log9_envio($c['jeison']);

    expect(log9_movs($e['envio']))->toBe([]);

    log9_entregar($e);

    $d = SaldoCuenta::describir(log9_saldo($c['jeison']), $c['jeison']->nombre);

    expect(log9_movs($e['envio']))->toBe(['recaudo' => 51000.0, 'servicio' => -10000.0])
        ->and(log9_saldo($c['jeison']))->toBe(41000.0)
        ->and($d['sentido'])->toBe(SaldoCuenta::DEBE_ENTREGAR)
        ->and($d['texto'])->toBe('JEISON debe entregar a ByLopez $ 41.000');
});

test('CC-2 CASO B cliente ya pago: solo el servicio -10.000 → «ByLopez debe pagar a JEISON $ 10.000»', function () {
    $c = log9_preparar();
    $e = log9_envio($c['jeison'], ['pago' => 'moneytransfer', 'recaudo' => '0']);
    log9_entregar($e);

    $d = SaldoCuenta::describir(log9_saldo($c['jeison']), $c['jeison']->nombre);

    expect(log9_movs($e['envio']))->toBe(['servicio' => -10000.0])
        ->and($d['sentido'])->toBe(SaldoCuenta::BYLOPEZ_PAGA)
        ->and($d['texto'])->toBe('ByLopez debe pagar a JEISON $ 10.000')
        ->and(SaldoCuenta::describir(0, 'X')['texto'])->toBe('Cuenta al día');
});

test('CC-3 CASO C envio gratis: ByLopez asume el domicilio → -10.000; CASO D por pagar: solo el recaudo de la mercancia', function () {
    $c = log9_preparar();

    $gratis = log9_envio($c['jeison'], ['envio' => 'free_free', 'pago' => 'moneytransfer', 'recaudo' => '0']);
    log9_entregar($gratis);

    // Por pagar: el cliente le paga el domicilio DIRECTO al transportador; el recaudo es solo la mercancia (no se vuelve venta).
    $porPagar = log9_envio($c['brian'], ['envio' => 'flatrate_flatrate']);
    log9_entregar($porPagar);

    expect(log9_movs($gratis['envio']))->toBe(['servicio' => -10000.0])
        ->and(log9_movs($porPagar['envio']))->toBe(['recaudo' => 51000.0])
        ->and(Guia::query()->find($porPagar['guia']->id)->flete_pagador->value)->toBe('cliente_al_recibir');
});

test('CC-4 CASO E PICAP (sin cuenta corriente por capacidad): 0 movimientos y no aparece en el listado', function () {
    $c = log9_preparar();
    $e = log9_envio($c['picap'], ['extra' => ['valorFlete' => '8000']]);
    log9_entregar($e);

    $ids = array_column(app(ConsultaCuentasService::class)->listado()['cuentas'], 'id');

    expect(MovimientoCuenta::query()->where('shipment_id', $e['envio'])->count())->toBe(0)
        ->and($ids)->toContain($c['jeison']->id)
        ->and($ids)->not->toContain($c['picap']->id);
});

test('CC-5/6 reintento, doble clic y resincronizacion no duplican el recaudo ni el servicio', function () {
    $c = log9_preparar();
    $e = log9_envio($c['jeison']);
    $clave = 'log9-dup-'.Str::uuid();

    log9_entregar($e, null, $clave);
    log9_entregar($e, null, $clave);

    foreach (range(1, 3) as $_) {
        app(GeneradorMovimientosCuenta::class)->sincronizarEnvio($e['envio']);
    }

    expect(MovimientoCuenta::query()->where('shipment_id', $e['envio'])->where('concepto', 'recaudo')->count())->toBe(1)
        ->and(MovimientoCuenta::query()->where('shipment_id', $e['envio'])->where('concepto', 'servicio')->count())->toBe(1)
        ->and(log9_saldo($c['jeison']))->toBe(41000.0);
});

test('CC-7 dos entregas parciales: el servicio nace con la primera unidad; el recaudo, UNA vez al completar el envio', function () {
    $c = log9_preparar();
    $e = log9_envio($c['jeison'], ['qty' => 5]);
    $item = array_key_first($e['items']);

    log9_entregar($e, [$item => 2]);
    expect(log9_movs($e['envio']))->toBe(['servicio' => -10000.0]);

    log9_entregar($e, [$item => 3]);
    expect(log9_movs($e['envio']))->toBe(['recaudo' => 51000.0, 'servicio' => -10000.0])
        ->and(MovimientoCuenta::query()->where('shipment_id', $e['envio'])->where('concepto', 'recaudo')->count())->toBe(1);
});

test('CC-8/9/10 liquidar: movimientos a liquidado, suma 0; un segundo usuario no puede; la liquidacion cerrada no se edita', function () {
    $c = log9_preparar();
    log9_entregar(log9_envio($c['jeison']));
    $ids = MovimientoCuenta::query()->where('transportadora_id', $c['jeison']->id)->pluck('id')->all();
    $clave = 'liq-'.Str::uuid();

    $l = app(LiquidarCuentaService::class)->liquidar(log9_admin(), (int) $c['jeison']->id, ['movimientos' => $ids, 'valor' => '41000', 'idempotency_key' => $clave]);
    // Mismo envio repetido (doble clic): la misma liquidacion.
    $repetida = app(LiquidarCuentaService::class)->liquidar(log9_admin(), (int) $c['jeison']->id, ['movimientos' => $ids, 'valor' => '41000', 'idempotency_key' => $clave]);
    // Otro usuario, otra clave, los mismos movimientos: rechazado.
    $segundo = log2_error(fn () => app(LiquidarCuentaService::class)->liquidar(log9_admin(), (int) $c['jeison']->id, ['movimientos' => $ids, 'valor' => '41000', 'idempotency_key' => 'liq-'.Str::uuid()]));

    expect($repetida->id)->toBe($l->id)
        ->and($segundo)->toBe(Invalida::MOVIMIENTOS_YA_LIQUIDADOS)
        ->and(MovimientoCuenta::query()->whereIn('id', $ids)->pluck('estado')->unique()->map(fn ($e) => $e->value)->all())->toBe(['liquidado'])
        ->and((float) MovimientoCuenta::query()->where('liquidacion_id', $l->id)->sum('importe'))->toBe(0.0)
        ->and((float) $l->saldo_esperado)->toBe(41000.0)
        ->and($l->sentido)->toBe('transportador_entrega')
        ->and($l->numero())->toStartWith('LIQ-')
        ->and(log9_saldo($c['jeison']))->toBe(0.0)
        ->and(log2_error(fn () => $l->update(['observacion' => 'cambio'])))->toBe(Invalida::LIQUIDACION_CERRADA)
        ->and(log2_error(fn () => $l->delete()))->toBe(Invalida::LIQUIDACION_CERRADA)
        ->and(log2_error(fn () => MovimientoCuenta::query()->find($ids[0])->update(['importe' => 1])))->toBe(Invalida::REGISTRO_INMUTABLE);
});

test('CC-11 ajuste posterior: no toca la liquidacion cerrada y entra en la cuenta siguiente', function () {
    $c = log9_preparar();
    log9_entregar(log9_envio($c['jeison']));
    $l = log9_liquidar($c['jeison'], '41000');

    $ajuste = app(AjusteCuentaService::class)->registrarAjuste(log9_admin(), (int) $c['jeison']->id, [
        'tipo' => 'a_favor_bylopez', 'valor' => '5000', 'motivo' => 'Faltante reportado despues del cierre', 'idempotency_key' => 'aj-'.Str::uuid(),
    ]);
    $siguiente = log9_liquidar($c['jeison'], '5000');

    expect($ajuste->estado)->toBe(EstadoMovimientoCuenta::ABIERTO)
        ->and($ajuste->liquidacion_id)->toBeNull()
        ->and($ajuste->fresh()->liquidacion_id)->toBe($siguiente->id)
        ->and((float) $l->fresh()->saldo_esperado)->toBe(41000.0)
        ->and($l->fresh()->movimientos_count)->toBe(2)
        ->and((float) $siguiente->saldo_esperado)->toBe(5000.0);
});

test('CC-12 BODEGA ve la cuenta pero no registra ajustes ni liquida', function () {
    $c = log9_preparar();
    log9_entregar(log9_envio($c['jeison']));
    $bodega = f03b_admin('custom', array_merge(LOG2_PERMISOS_BODEGA, ['logistica.cuentas']));

    $ctx = app(ConsultaCuentasService::class)->contexto($bodega, (int) $c['jeison']->id);

    expect($ctx['saldo']['valor'])->toBe(41000.0)
        ->and($ctx['permisos'])->toBe(['liquidar' => false, 'ajustar' => false])
        ->and(log2_error(fn () => app(AjusteCuentaService::class)->registrarAjuste($bodega, (int) $c['jeison']->id, ['tipo' => 'a_favor_bylopez', 'valor' => '1000', 'motivo' => 'x', 'idempotency_key' => 'aj-'.Str::uuid()])))->toBe(Invalida::SIN_PERMISO)
        ->and(log2_error(fn () => log9_liquidar($c['jeison'], '41000', [], $bodega)))->toBe(Invalida::SIN_PERMISO)
        ->and(log2_error(fn () => app(ConsultaCuentasService::class)->contexto(log2_bodega(), (int) $c['jeison']->id)))->toBe(Invalida::SIN_PERMISO);
});

test('CC-13/14 diferencia al liquidar: exige observacion, no modifica nada y deja la diferencia abierta para la siguiente cuenta', function () {
    $c = log9_preparar();
    log9_entregar(log9_envio($c['jeison']));

    expect(log2_error(fn () => log9_liquidar($c['jeison'], '31000')))->toBe(Invalida::OBSERVACION_REQUERIDA);

    $l = log9_liquidar($c['jeison'], '31000', ['observacion' => 'Entrego 31.000; los 10.000 restantes manana']);
    $abierta = MovimientoCuenta::query()->where('transportadora_id', $c['jeison']->id)->where('estado', 'abierto')->sole();

    expect((float) $l->diferencia)->toBe(10000.0)
        ->and((float) MovimientoCuenta::query()->where('liquidacion_id', $l->id)->sum('importe'))->toBe(0.0)
        ->and($abierta->concepto->value)->toBe('diferencia_liquidacion')
        ->and((float) $abierta->importe)->toBe(10000.0)
        ->and(SaldoCuenta::describir(log9_saldo($c['jeison']), 'JEISON')['texto'])->toBe('JEISON debe entregar a ByLopez $ 10.000');
});

test('CC-15 devolucion por si sola no crea dinero; el costo de devolucion existe solo por registro explicito del Admin', function () {
    $c = log9_preparar();
    $e = log9_envio($c['jeison']);
    log9_evento(RegistrarRetornoTransportadoraService::class, $e);

    expect(MovimientoCuenta::query()->where('shipment_id', $e['envio'])->count())->toBe(0);

    $costo = app(AjusteCuentaService::class)->registrarAjuste(log9_admin(), (int) $c['jeison']->id, [
        'concepto' => 'costo_devolucion', 'tipo' => 'a_favor_bylopez', 'valor' => '6000', 'guia_id' => $e['guia']->id,
        'motivo' => 'Viaje fallido acordado con Jeison', 'idempotency_key' => 'aj-'.Str::uuid(),
    ]);

    // Siempre a favor del transportador, ligado a la guia con devolucion.
    expect((float) $costo->importe)->toBe(-6000.0)->and($costo->shipment_id)->toBe($e['envio']);
});

test('CC-16 anular la entrega REVIERTE lo generado (nunca edita ni borra) y volver a entregar lo genera de nuevo', function () {
    $c = log9_preparar();
    $e = log9_envio($c['jeison']);
    $entrega = log9_entregar($e);

    app(AnularEventoLogisticoService::class)->anularEntrega(log9_admin(), (int) $entrega->id, 'Se registro en el envio equivocado');

    expect(log9_movs($e['envio']))->toBe(['recaudo' => 0.0, 'servicio' => 0.0])
        ->and(MovimientoCuenta::query()->where('shipment_id', $e['envio'])->where('origen_tipo', 'reverso')->count())->toBe(2);

    log9_entregar($e);

    expect(log9_movs($e['envio']))->toBe(['recaudo' => 51000.0, 'servicio' => -10000.0])
        ->and(MovimientoCuenta::query()->where('shipment_id', $e['envio'])->count())->toBe(6);
});

test('CC-17 entrega parcial con devolucion: «Requiere revision» (no se inventa la proporcion); el Admin confirma el valor una sola vez', function () {
    $c = log9_preparar();
    $e = log9_envio($c['jeison']);
    $item = array_key_first($e['items']);

    log9_entregar($e, [$item => 1]);
    log9_evento(RegistrarRetornoTransportadoraService::class, $e, [$item => 1]);

    $revisiones = app(GeneradorMovimientosCuenta::class)->revisiones([(int) $c['jeison']->id]);

    expect(log9_movs($e['envio']))->toBe(['servicio' => -10000.0])
        ->and($revisiones)->toHaveCount(1)
        ->and($revisiones[0]['motivo'])->toBe('entrega_con_devolucion');

    $clave = 'rev-'.Str::uuid();
    $m = app(AjusteCuentaService::class)->confirmarRevision(log9_admin(), $e['envio'], 'recaudo', '25500', 'Cobro solo una prenda', $clave);
    $otra = app(AjusteCuentaService::class)->confirmarRevision(log9_admin(), $e['envio'], 'recaudo', '25500', 'Cobro solo una prenda', $clave);

    expect($otra->id)->toBe($m->id)
        ->and(log9_movs($e['envio']))->toBe(['recaudo' => 25500.0, 'servicio' => -10000.0])
        ->and(app(GeneradorMovimientosCuenta::class)->revisiones([(int) $c['jeison']->id]))->toBe([])
        ->and(log2_error(fn () => app(AjusteCuentaService::class)->confirmarRevision(log9_admin(), $e['envio'], 'recaudo', '1000', 'otra', 'rev-'.Str::uuid())))->toBe(Invalida::REVISION_NO_APLICA);

    // Resincronizar no pisa la decision del Admin mientras el envio siga ambiguo.
    app(GeneradorMovimientosCuenta::class)->sincronizarEnvio($e['envio']);
    expect(log9_movs($e['envio']))->toBe(['recaudo' => 25500.0, 'servicio' => -10000.0]);
});

test('CC-19 HTTP: el listado renderiza con el saldo en palabras; el contexto JSON responde; sin permiso, 403/401', function () {
    $c = log9_preparar();
    log9_entregar(log9_envio($c['jeison']));
    $admin = f03b_admin('all');

    $html = $this->actingAs($admin, 'admin')->get(route('admin.logistica.cuentas.index'))->assertOk()->getContent();
    $json = $this->actingAs($admin, 'admin')->getJson(route('admin.logistica.cuentas.contexto', $c['jeison']->id))->assertOk()->json('contexto');

    expect($html)->toContain('data-cuenta="'.$c['jeison']->id.'"')
        ->and($html)->toContain('Debe entregar a ByLopez')
        ->and($html)->toContain('$ 41.000')
        ->and($html)->not->toContain('data-cuenta="'.$c['picap']->id.'"')
        ->and($json['saldo']['texto'])->toBe('JEISON debe entregar a ByLopez $ 41.000')
        ->and($json['liquidable']['n'])->toBe(2);

    $this->actingAs(log2_bodega(), 'admin')->getJson(route('admin.logistica.cuentas.contexto', $c['jeison']->id))->assertStatus(401);
});

test('CC-18 corregir la guia a otra transportadora mueve el dinero (reverso en la anterior, nuevo en la siguiente)', function () {
    $c = log9_preparar();
    $e = log9_envio($c['jeison']);
    log9_entregar($e);

    app(CorregirGuiaService::class)->corregir(log9_admin(), (int) $e['guia']->id, null, $c['brian']->codigo, 'La entrego Brian, no Jeison');

    expect(log9_saldo($c['jeison']))->toBe(0.0)
        ->and(log9_saldo($c['brian']))->toBe(41000.0);
});

/**
 * Pedido con VARIAS lineas, cada una con su precio efectivo, descuento e impuesto guardados en `order_items` (como los
 * congela Bagisto al vender). Devuelve tambien el shipment_item de cada linea, en el mismo orden.
 *
 * @param  list<array{qty: int, precio: float, descuento?: float, impuesto?: float}>  $lineas
 */
function log9_envio_lineas(Transportadora $t, array $lineas, string $recaudo): array
{
    $unidades = array_sum(array_column($lineas, 'qty'));
    $total = array_sum(array_map(fn ($l) => $l['precio'] * $l['qty'] - ($l['descuento'] ?? 0) + ($l['impuesto'] ?? 0), $lineas)) + 10000;
    $e = log2_pedido($lineas[0]['qty'], ['envio' => 'domicilio_local', 'total' => $total, 'stock' => $unidades + 10]);
    $ids = [];

    foreach ($lineas as $i => $l) {
        $datos = [
            'qty_ordered' => $l['qty'], 'qty_shipped' => 0, 'qty_canceled' => 0, 'qty_refunded' => 0,
            'price' => $l['precio'], 'base_price' => $l['precio'], 'total' => $l['precio'] * $l['qty'], 'base_total' => $l['precio'] * $l['qty'],
            'discount_amount' => $l['descuento'] ?? 0, 'base_discount_amount' => $l['descuento'] ?? 0,
            'tax_amount' => $l['impuesto'] ?? 0, 'base_tax_amount' => $l['impuesto'] ?? 0,
        ];

        if ($i === 0) {
            $e['item']->update($datos);
            $ids[] = (int) $e['item']->id;
        } else {
            $ids[] = (int) OrderItem::factory()->create($datos + [
                'product_id' => $e['item']->product_id, 'order_id' => $e['pedido']->id, 'sku' => $e['item']->sku,
                'type' => $e['item']->type, 'name' => $e['item']->name.' L'.$i, 'additional' => ['locale' => 'es'],
            ])->id;
        }
    }

    $guia = log6_despachar($e, $t, ['recaudoEsperado' => $recaudo]);
    $porLinea = DB::table('shipment_items')->where('shipment_id', $guia->shipment_id)->pluck('id', 'order_item_id');

    return [
        'pedido' => $e['pedido'], 'guia' => $guia, 'envio' => (int) $guia->shipment_id,
        'lineas' => array_map(fn ($id) => (int) $porLinea[$id], $ids),
    ];
}

/** Entrega lo indicado y devuelve el resto de cada linea: queda «Requiere revision». */
function log9_parcial(array $e, array $lineas, array $entregadas): array
{
    $entregar = [];
    $devolver = [];

    foreach ($e['lineas'] as $i => $si) {
        $entregar[$si] = $entregadas[$i] ?? 0;
        $devolver[$si] = $lineas[$i]['qty'] - $entregar[$si];
    }

    log9_entregar($e, array_filter($entregar));
    log9_evento(RegistrarRetornoTransportadoraService::class, $e, array_filter($devolver));

    return collect(app(GeneradorMovimientosCuenta::class)->revisiones())->firstWhere('shipment_id', $e['envio']);
}

test('CC-20 caso real: 3 camisetas devueltas vendidas a $ 8.000 = $ 24.000; recaudo $ 80.000 → sugerido $ 56.000 (del pedido, no del catalogo)', function () {
    $c = log9_preparar();
    $lineas = [['qty' => 3, 'precio' => 8000], ['qty' => 3, 'precio' => 8000], ['qty' => 3, 'precio' => 8000], ['qty' => 1, 'precio' => 8000]];
    $e = log9_envio_lineas($c['jeison'], $lineas, '80000');

    // El catalogo actual vale otra cosa: no debe influir.
    DB::table('product_flat')->where('product_id', DB::table('order_items')->where('order_id', $e['pedido']->id)->value('product_id'))->update(['price' => 99999]);

    $r = log9_parcial($e, $lineas, [0, 3, 3, 1]);

    expect($r['motivo'])->toBe('entrega_con_devolucion')
        ->and($r['devoluciones']['valor'])->toBe(24000.0)
        ->and($r['devoluciones']['lineas'][0])->toMatchArray(['cantidad' => 3, 'valor_unitario' => 8000.0, 'valor' => 24000.0])
        ->and($r['recaudo_sugerido'])->toBe(56000.0);

    // Igual al sugerido: basta confirmar, sin motivo. Sigue siendo una decision del Admin (antes no hay recaudo).
    expect(log9_movs($e['envio']))->not->toHaveKey('recaudo');
    $m = app(AjusteCuentaService::class)->confirmarRevision(log9_admin(), $e['envio'], 'recaudo', '56000', '', 'rev-'.Str::uuid());

    expect((float) $m->importe)->toBe(56000.0)
        ->and($m->motivo)->toBeNull()
        ->and($m->descripcion)->toBe(trans('logistica::app.cuentas.revision.confirmado_sugerido'));
});

test('CC-21 cada devolucion se valora con SU linea: precios distintos, descuento repartido, impuesto y devolucion parcial de una linea', function () {
    $c = log9_preparar();
    $lineas = [
        ['qty' => 2, 'precio' => 7500],                       // devuelve 1 → 7.500
        ['qty' => 2, 'precio' => 8000, 'descuento' => 1000],  // devuelve 2 → (16.000 − 1.000) / 2 × 2 = 15.000
        ['qty' => 2, 'precio' => 9000, 'impuesto' => 400],    // devuelve 1 → (18.000 + 400) / 2 = 9.200
    ];
    $e = log9_envio_lineas($c['jeison'], $lineas, '49400');
    $r = log9_parcial($e, $lineas, [1, 0, 1]);

    expect(array_map(fn ($l) => [$l['cantidad'], $l['valor_unitario'], $l['valor']], $r['devoluciones']['lineas']))
        ->toBe([[1, 7500.0, 7500.0], [2, 7500.0, 15000.0], [1, 9200.0, 9200.0]])
        ->and($r['devoluciones']['valor'])->toBe(31700.0)
        ->and($r['recaudo_sugerido'])->toBe(17700.0);
});

test('CC-22 un valor distinto al sugerido exige motivo (servidor); con motivo se registra el valor real', function () {
    $c = log9_preparar();
    $lineas = [['qty' => 3, 'precio' => 8000], ['qty' => 2, 'precio' => 8000]];
    $e = log9_envio_lineas($c['jeison'], $lineas, '40000');
    $r = log9_parcial($e, $lineas, [0, 2]);

    expect($r['recaudo_sugerido'])->toBe(16000.0)
        ->and(log2_error(fn () => app(AjusteCuentaService::class)->confirmarRevision(log9_admin(), $e['envio'], 'recaudo', '15000', ' ', 'rev-'.Str::uuid())))->toBe(Invalida::MOTIVO_REQUERIDO)
        ->and(log9_movs($e['envio']))->not->toHaveKey('recaudo');

    app(AjusteCuentaService::class)->confirmarRevision(log9_admin(), $e['envio'], 'recaudo', '15000', 'El cliente pago 1.000 menos', 'rev-'.Str::uuid());

    expect(log9_movs($e['envio'])['recaudo'])->toBe(15000.0);
});
