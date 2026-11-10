<?php

use ByLopez\Logistica\DataObjects\SolicitudEventoEnvio;
use ByLopez\Logistica\DataObjects\SolicitudRecepcion;
use ByLopez\Logistica\Enums\FuenteEventoLogistico as Fuente;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\RecepcionDevolucion;
use ByLopez\Logistica\Models\RecepcionDevolucionItem;
use ByLopez\Logistica\Models\UnidadDefectuosa;
use ByLopez\Logistica\Services\Entregas\RecibirDevolucionService;
use ByLopez\Logistica\Services\Entregas\RegistrarRetornoTransportadoraService;
use ByLopez\Logistica\Services\Stock\ConsultaDefectuosasService;
use ByLopez\VentasAsesor\Models\AplicacionPago;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Services\Cartera\ConfirmarPagoService;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarPagoService;
use ByLopez\VentasAsesor\Support\Pedidos\EstadoPagoPedido;
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
| C5 + DEFECTUOSAS (2026-10-24). Registrar una devolucion NO toca Cartera; cuando Bodega RECIBE y confirma, lo recibido (aptas +
| defectuosas, nunca los faltantes) deja de ser exigible al cliente a su valor HISTORICO, las aptas suben el stock vendible y las
| defectuosas quedan como inventario NO vendible, clasificadas. El pedido original no cambia. Solo lpz_saas_testing.
|
| Pedido de prueba: 10 u. a 10.000 con 10.000 de descuento de linea → total 90.000 (9.000 por unidad, precio HISTORICO).
*/

/** Pedido real despachado por ShipmentRepository (descuenta stock) con una devolucion REGISTRADA de `$devuelve` unidades. */
function c5_envio(int $devuelve, float $descuento = 10000.0, int $qty = 10, float $precio = 10000.0): array
{
    Mail::fake();
    pagb_corte('2000-01-01');
    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido();
    $neto = $precio * $qty - $descuento;

    $item->update([
        'qty_ordered' => $qty, 'qty_shipped' => 0, 'qty_canceled' => 0, 'qty_refunded' => 0, 'price' => $precio, 'base_price' => $precio,
        'total' => $precio * $qty, 'base_total' => $precio * $qty, 'discount_amount' => $descuento, 'base_discount_amount' => $descuento,
        'tax_amount' => 0, 'base_tax_amount' => 0,
    ]);
    $pedido->update(['grand_total' => $neto, 'base_grand_total' => $neto]);
    DB::table('order_payment')->where('order_id', $pedido->id)->update(['method' => 'moneytransfer']);
    pagb_factura($pedido, 'pending', $neto);

    // El Shipment solo descuenta (y la recepcion solo repone) stock de productos que gestionan inventario.
    $atributo = (int) DB::table('attributes')->where('code', 'manage_stock')->value('id');
    $clave = ['product_id' => $item->product_id, 'attribute_id' => $atributo, 'channel' => 'default'];

    if (DB::table('product_attribute_values')->where($clave)->exists()) {
        DB::table('product_attribute_values')->where($clave)->update(['boolean_value' => 1]);
    } else {
        DB::table('product_attribute_values')->insert($clave + ['unique_id' => 'default|'.$item->product_id.'|'.$atributo, 'locale' => null, 'boolean_value' => 1]);
    }

    DB::table('product_inventories')->updateOrInsert(['product_id' => $item->product_id, 'inventory_source_id' => 1, 'vendor_id' => 0], ['qty' => 50]);

    $envio = app(ShipmentRepository::class)->create(['order_id' => $pedido->id, 'shipment' => [
        'carrier_title' => 'BYLOPEZ', 'track_number' => 'GUIA-'.uniqid(), 'source' => 1, 'items' => [$item->id => [1 => $qty]],
    ]]);

    $si = (int) DB::table('shipment_items')->where('shipment_id', $envio->id)->value('id');
    $r = app(RegistrarRetornoTransportadoraService::class)->registrar(f03b_admin('custom', C5_PERMISOS), new SolicitudEventoEnvio(
        shipmentId: (int) $envio->id, orderId: (int) $pedido->id, fecha: now()->toDateString(), items: [$si => $devuelve],
        fuente: Fuente::BODEGA_MANUAL, idempotencyKey: 'c5-ret-'.Str::uuid(), motivo: 'Cliente rechaza',
    ));

    return [
        'pedido' => $pedido, 'item' => $item, 'envio' => $envio, 'producto' => (int) $item->product_id,
        'retorno' => $r->registro, 'retorno_item' => (int) $r->registro->items->sole()->id, 'neto' => $neto,
    ];
}

const C5_PERMISOS = ['logistica', 'logistica.entregas', 'logistica.entregas.registrar', 'logistica.entregas.recibir_devolucion'];

function c5_stock(array $e): int
{
    return (int) DB::table('product_inventories')->where('product_id', $e['producto'])->where('inventory_source_id', 1)->where('vendor_id', 0)->value('qty');
}

function c5_cartera(array $e): object
{
    return app(CarteraConsultaService::class)->saldosDePedidos([(int) $e['pedido']->id])[(int) $e['pedido']->id];
}

/** Pago de transferencia registrado y CONFIRMADO por Cartera (como lo haria el flujo real). */
function c5_pagar(array $e, string $monto): void
{
    $admin = f03b_admin('all');
    $pago = app(RegistrarPagoService::class)->registrar($admin, (int) $e['pedido']->id, [
        'monto' => $monto, 'medio_cobro' => 'transferencia', 'plataforma' => 'nequi', 'fecha_pago' => now()->toDateString(), 'referencia' => 'TRX-'.Str::random(6),
    ], 'c5-pago-'.Str::uuid())->pagoId;
    app(ConfirmarPagoService::class)->confirmar($admin, $pago);
}

function c5_recibir(array $e, array $linea, array $extra = []): SolicitudRecepcion
{
    return new SolicitudRecepcion(...$extra + [
        'retornoId' => (int) $e['retorno']->id, 'fecha' => now()->toDateString(),
        'lineas' => [$e['retorno_item'] => $linea], 'idempotencyKey' => 'c5-rec-'.Str::uuid(),
    ]);
}

function c5_error(callable $accion): ?string
{
    try {
        $accion();
    } catch (Invalida $e) {
        return $e->codigo;
    }

    return null;
}

test('C5-01 devolucion con saldo pendiente: registrar no toca Cartera; al recibir, aptas suben el stock vendible, la defectuosa queda NO vendible y todo lo recibido deja de ser exigible', function () {
    $e = c5_envio(devuelve: 3);
    c5_pagar($e, '40000');   // el cliente ya pago 40.000 de 90.000

    $antes = c5_cartera($e);
    $stockAntes = c5_stock($e);

    expect($antes->saldo)->toBe(50.0 * 1000)
        ->and($antes->devuelto)->toBe(0.0);

    // Devolucion REGISTRADA (ya existe): Cartera sigue exigiendo 50.000.
    expect(c5_cartera($e)->saldo)->toBe(50000.0);

    $bodega = f03b_admin('custom', C5_PERMISOS);
    $servicio = app(RecibirDevolucionService::class);

    // Clasificacion obligatoria: motivo fuera de catalogo y «otro» sin descripcion se rechazan sin crear nada.
    $linea = ['recibidas' => 3, 'aptas' => 2, 'novedad' => 1, 'motivo' => ''];

    expect(c5_error(fn () => $servicio->recibir($bodega, c5_recibir($e, $linea + ['defectos' => [['cantidad' => 1, 'motivo' => 'inventado']]]))))->toBe(Invalida::DEFECTO_MOTIVO_INVALIDO)
        ->and(c5_error(fn () => $servicio->recibir($bodega, c5_recibir($e, $linea + ['defectos' => [['cantidad' => 1, 'motivo' => 'otro']]]))))->toBe(Invalida::DEFECTO_DETALLE_REQUERIDO)
        ->and(c5_error(fn () => $servicio->recibir($bodega, c5_recibir($e, $linea + ['defectos' => [['cantidad' => 2, 'motivo' => 'costura']]]))))->toBe(Invalida::DEFECTO_CANTIDADES_INVALIDAS)
        ->and(RecepcionDevolucion::query()->where('retorno_id', $e['retorno']->id)->count())->toBe(0)
        ->and(UnidadDefectuosa::query()->where('order_id', $e['pedido']->id)->count())->toBe(0)
        ->and(c5_stock($e))->toBe($stockAntes)
        ->and(c5_cartera($e)->saldo)->toBe(50000.0);

    // Bodega recibe 3: 2 aptas + 1 defectuosa (costura, probable origen transporte).
    $r = $servicio->recibir($bodega, c5_recibir($e, $linea + ['defectos' => [['cantidad' => 1, 'motivo' => 'costura', 'origen' => 'transporte', 'observacion' => 'Se descosio el hombro']]]));
    $item = $r->recepcion->items->sole();

    // Precio HISTORICO de la linea: (100.000 − 10.000) / 10 = 9.000 por unidad → 3 × 9.000 = 27.000.
    expect((float) $item->valor_unitario)->toBe(9000.0)
        ->and((float) $item->valor_devuelto)->toBe(27000.0)
        ->and([$item->recibidas, $item->aptas, $item->novedad, $item->faltantes])->toBe([3, 2, 1, 0]);

    // Stock vendible: solo +2 (las aptas). Las defectuosas NO se suman.
    expect(c5_stock($e))->toBe($stockAntes + 2);

    // DEFECTUOSAS: 1 unidad no vendible, con motivo estructurado, origen probable, estado inicial y quien la recibio.
    $d = UnidadDefectuosa::query()->where('order_id', $e['pedido']->id)->sole();

    expect([$d->cantidad, $d->motivo_codigo, $d->origen_codigo, $d->estado, $d->detalle, $d->observacion])
        ->toBe([1, 'costura', 'transporte', 'pendiente_revision', null, 'Se descosio el hombro'])
        ->and($d->product_id)->toBe($e['producto'])
        ->and($d->actor_admin_id)->toBe((int) $bodega->id)
        ->and($d->inventory_source_id)->toBe(1);

    $consulta = app(ConsultaDefectuosasService::class);

    expect($consulta->resumen()['pendientes'])->toBeGreaterThanOrEqual(1)
        ->and($consulta->listar(['desde' => null, 'hasta' => null, 'motivo' => 'costura', 'origen' => 'transporte', 'estado' => 'pendiente_revision'])
            ->getCollection()->contains(fn ($f) => (int) $f->order_id === (int) $e['pedido']->id && $f->cantidad === 1))->toBeTrue();

    // CARTERA: el pedido original no cambia; el saldo real baja por las 3 recibidas (aptas Y defectuosa): 90.000 − 27.000 − 40.000 = 23.000.
    $despues = c5_cartera($e);

    expect($despues->total)->toBe(90000.0)
        ->and($despues->devuelto)->toBe(27000.0)
        ->and($despues->unidadesDevueltas)->toBe(3)
        ->and($despues->totalOperativo)->toBe(63000.0)
        ->and($despues->saldo)->toBe(23000.0)
        ->and($despues->saldoAFavor)->toBe(0.0)
        ->and((float) DB::table('orders')->where('id', $e['pedido']->id)->value('base_grand_total'))->toBe(90000.0)
        ->and((float) DB::table('order_items')->where('id', $e['item']->id)->value('base_total'))->toBe(100000.0);
});

test('C5-02 pagado con devolucion: saldo por cobrar $0, saldo a favor identificado a precio historico, sin reembolso ni deuda negativa', function () {
    // (a) Pagado por completo (90.000) y devuelve 2 unidades (18.000), una apta y una defectuosa.
    $e = c5_envio(devuelve: 2);
    c5_pagar($e, '90000');
    $pagosAntes = [Pago::query()->count(), AplicacionPago::query()->where('order_id', $e['pedido']->id)->count(), DB::table('order_transactions')->where('order_id', $e['pedido']->id)->count()];

    expect(c5_cartera($e)->saldo)->toBe(0.0);

    app(RecibirDevolucionService::class)->recibir(f03b_admin('custom', C5_PERMISOS), c5_recibir($e, [
        'recibidas' => 2, 'aptas' => 1, 'novedad' => 1, 'motivo' => '', 'defectos' => [['cantidad' => 1, 'motivo' => 'mancha']],
    ]));

    $c = c5_cartera($e);

    expect($c->saldo)->toBe(0.0)                       // nunca negativo
        ->and($c->devuelto)->toBe(18000.0)             // 2 × 9.000 historicos
        ->and($c->saldoAFavor)->toBe(18000.0)          // identificado, pendiente de resolver (D10)
        ->and($c->totalOperativo)->toBe(72000.0)
        ->and($c->total)->toBe(90000.0)                // el pedido original no cambia
        ->and($c->requiereRevision)->toBeFalse();

    // Sin reembolso automatico: ningun Refund, ni pago, ni aplicacion, ni transaccion nueva.
    expect(DB::table('refunds')->where('order_id', $e['pedido']->id)->count())->toBe(0)
        ->and([Pago::query()->count(), AplicacionPago::query()->where('order_id', $e['pedido']->id)->count(), DB::table('order_transactions')->where('order_id', $e['pedido']->id)->count()])->toBe($pagosAntes)
        ->and(UnidadDefectuosa::query()->where('order_id', $e['pedido']->id)->first()->origen_codigo)->toBe('por_determinar');   // origen predeterminado

    // (b) Pago parcial SUPERIOR a la nueva obligacion: pago 90.000 de 90.000, devuelve 3 (27.000) → obligacion 63.000, a favor 27.000.
    // Y con pago de 70.000: obligacion 63.000 → debe 0 y a favor 7.000 (no se muestra como deuda negativa).
    $p = c5_envio(devuelve: 3);
    c5_pagar($p, '70000');

    expect(c5_cartera($p)->saldo)->toBe(20000.0);

    app(RecibirDevolucionService::class)->recibir(f03b_admin('custom', C5_PERMISOS), c5_recibir($p, ['recibidas' => 3, 'aptas' => 3, 'novedad' => 0, 'motivo' => '']));

    $parcial = c5_cartera($p);

    expect($parcial->saldo)->toBe(0.0)
        ->and($parcial->saldoAFavor)->toBe(7000.0)
        ->and($parcial->totalOperativo)->toBe(63000.0)
        ->and(UnidadDefectuosa::query()->where('order_id', $p['pedido']->id)->count())->toBe(0);   // todas aptas: nada no vendible
});

test('C5-03 faltantes no reducen la deuda y repetir la recepcion no duplica stock, defectuosas, ajuste ni saldo a favor', function () {
    // Devolucion de 5; Bodega recibe 3 (2 aptas + 1 defectuosa) y 2 NO llegan: faltantes.
    $e = c5_envio(devuelve: 5);
    c5_pagar($e, '30000');
    $stockAntes = c5_stock($e);
    $bodega = f03b_admin('custom', C5_PERMISOS);
    $servicio = app(RecibirDevolucionService::class);

    $solicitud = c5_recibir($e, [
        'recibidas' => 3, 'aptas' => 2, 'novedad' => 1, 'motivo' => 'Dos prendas no llegaron en la caja',
        'defectos' => [['cantidad' => 1, 'motivo' => 'otro', 'origen' => 'cliente', 'detalle' => 'Manchada con pintura']],
    ]);

    // Sin motivo, los faltantes se rechazan: no se condona nada en silencio.
    expect(c5_error(fn () => $servicio->recibir($bodega, c5_recibir($e, ['recibidas' => 3, 'aptas' => 3, 'novedad' => 0, 'motivo' => '']))))->toBe(Invalida::RECEPCION_MOTIVO_REQUERIDO);

    $r = $servicio->recibir($bodega, $solicitud);
    $item = $r->recepcion->items->sole();

    // SOLO lo recibido (3 × 9.000 = 27.000) reduce la obligacion; los 2 faltantes siguen siendo deuda del cliente.
    expect([$item->recibidas, $item->faltantes])->toBe([3, 2])
        ->and((float) $item->valor_devuelto)->toBe(27000.0)
        ->and(c5_cartera($e)->devuelto)->toBe(27000.0)
        ->and(c5_cartera($e)->saldo)->toBe(33000.0)           // 90.000 − 27.000 − 30.000
        ->and(c5_stock($e))->toBe($stockAntes + 2);

    $estado = fn () => [
        RecepcionDevolucion::query()->where('retorno_id', $e['retorno']->id)->count(),
        RecepcionDevolucionItem::query()->whereIn('recepcion_id', RecepcionDevolucion::query()->where('retorno_id', $e['retorno']->id)->pluck('id'))->count(),
        UnidadDefectuosa::query()->where('order_id', $e['pedido']->id)->count(),
        (int) UnidadDefectuosa::query()->where('order_id', $e['pedido']->id)->sum('cantidad'),
        c5_stock($e),
        c5_cartera($e)->devuelto,
        c5_cartera($e)->saldo,
        c5_cartera($e)->saldoAFavor,
    ];
    $base = $estado();

    // Reintento (doble clic / timeout): la misma recepcion; otra clave sobre la misma devolucion: ya recibida. Nada se duplica.
    $repetida = $servicio->recibir($bodega, $solicitud);

    expect($repetida->yaExistia)->toBeTrue()
        ->and((int) $repetida->recepcion->id)->toBe((int) $r->recepcion->id)
        ->and(c5_error(fn () => $servicio->recibir($bodega, c5_recibir($e, ['recibidas' => 3, 'aptas' => 2, 'novedad' => 1, 'motivo' => 'x', 'defectos' => [['cantidad' => 1, 'motivo' => 'mancha']]]))))->toBe(Invalida::RECEPCION_YA_REGISTRADA)
        ->and($estado())->toBe($base)
        ->and($base)->toBe([1, 1, 1, 1, $stockAntes + 2, 27000.0, 33000.0, 0.0]);
});

/** Recibe TODO lo devuelto (10 de 10): 8 aptas + 2 defectuosas. */
function ct_recibir_todo(array $e): void
{
    app(RecibirDevolucionService::class)->recibir(f03b_admin('custom', C5_PERMISOS), c5_recibir($e, [
        'recibidas' => 10, 'aptas' => 8, 'novedad' => 2, 'motivo' => '', 'defectos' => [['cantidad' => 2, 'motivo' => 'mancha']],
    ]));
}

function ct_sin_efectos_nativos(array $e, array $antes): void
{
    // Devolucion fisica ≠ Refund: nada nativo ni de dinero se crea, y el estado nativo del pedido no se manipula.
    expect(DB::table('refunds')->where('order_id', $e['pedido']->id)->count())->toBe(0)
        ->and((float) DB::table('orders')->where('id', $e['pedido']->id)->value('base_grand_total_refunded'))->toBe(0.0)
        ->and((string) DB::table('orders')->where('id', $e['pedido']->id)->value('status'))->toBe($antes['status'])
        ->and((float) DB::table('orders')->where('id', $e['pedido']->id)->value('base_grand_total'))->toBe(100000.0)
        ->and(DB::table('order_items')->where('id', $e['item']->id)->value('qty_refunded'))->toEqual(0)
        ->and(AplicacionPago::query()->where('order_id', $e['pedido']->id)->count())->toBe($antes['aplicaciones'])
        ->and(DB::table('order_transactions')->where('order_id', $e['pedido']->id)->count())->toBe($antes['transacciones']);
}

test('CT-01 devolucion total sin pago: estado operativo «devolucion total», saldo $0, sin saldo a favor, sin Refund ni pago', function () {
    $e = c5_envio(devuelve: 10, descuento: 0.0);   // pedido 100.000, pagado 0
    $antes = [
        'status' => (string) DB::table('orders')->where('id', $e['pedido']->id)->value('status'),
        'aplicaciones' => 0, 'transacciones' => 0,
    ];

    // Registrada pero NO recibida: Cartera sigue exigiendo todo y el estado de pago es «pendiente».
    expect(c5_cartera($e)->saldo)->toBe(100000.0)
        ->and(EstadoPagoPedido::desdeCartera(c5_cartera($e))[0])->toBe('pendiente');

    ct_recibir_todo($e);
    $c = c5_cartera($e);
    [$estado, $saldo] = EstadoPagoPedido::desdeCartera($c);

    expect($c->total)->toBe(100000.0)
        ->and($c->devuelto)->toBe(100000.0)
        ->and($c->saldo)->toBe(0.0)
        ->and($c->saldoAFavor)->toBe(0.0)
        ->and($c->devolucionTotal())->toBeTrue()
        ->and($estado)->toBe('devuelto')                // ya no «pendiente»: no induce a cobrar
        ->and($saldo)->toBe(0.0);

    ct_sin_efectos_nativos($e, $antes);
});

test('CT-02 devolucion total ya pagada: saldo por cobrar $0, saldo a favor $100.000 identificado, estado pagado, sin Refund ni «reembolsado»', function () {
    $e = c5_envio(devuelve: 10, descuento: 0.0);
    c5_pagar($e, '100000');
    $antes = [
        'status' => (string) DB::table('orders')->where('id', $e['pedido']->id)->value('status'),
        'aplicaciones' => AplicacionPago::query()->where('order_id', $e['pedido']->id)->count(),
        'transacciones' => DB::table('order_transactions')->where('order_id', $e['pedido']->id)->count(),
    ];

    ct_recibir_todo($e);
    $c = c5_cartera($e);

    expect($c->saldo)->toBe(0.0)                         // nunca deuda negativa
        ->and($c->saldoAFavor)->toBe(100000.0)
        ->and($c->devolucionTotal())->toBeTrue()
        ->and(EstadoPagoPedido::desdeCartera($c)[0])->toBe('pagado')
        ->and($c->toArray()['devolucion_total'])->toBeTrue();

    ct_sin_efectos_nativos($e, $antes);

    // La vista dice «saldo a favor» y «devolucion total», y NO afirma que se haya reembolsado dinero.
    $es = trans('ventasasesor::app.cartera.bloque', [], 'es');
    expect($es['estado-a-favor'])->not->toContain('eembols')
        ->and($es['devolucion-total-nota'])->toContain('No se ha reembolsado');
});
