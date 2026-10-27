<?php

use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Enums\TipoEventoPago;
use ByLopez\VentasAsesor\Exceptions\RevisionPagoInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Services\Cartera\ConfirmarPagoService;
use ByLopez\VentasAsesor\Services\Cartera\RechazarPagoService;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarAbonoClienteService;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarPagoService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../helpersPagB.php';

uses(TestCase::class);

/*
| PAG-E · confirmar / rechazar pagos (D-59, Doc. 05 §42.5 y §42.21). Pruebas deliberadamente pocas, con pedidos de
| fabrica dentro de la transaccion de la prueba. Los pagos se registran con los servicios REALES de PAG-C y PAG-D.
*/

const PAGE_REVISOR = ['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.revisar', 'ventas_asesor.cartera.view_all'];

beforeEach(function () {
    pagb_corte('2000-01-01');
});

/** Pedido con una factura PENDIENTE (como cashondelivery) y su metodo de pago, listo para recibir y confirmar un pago. */
function page_pedido($cliente, float $total, array $extra = [], bool $conFactura = true): array
{
    $pedido = pagb_pedido($cliente, $total, $extra);
    $factura = $conFactura ? pagb_factura($pedido, 'pending', $total) : null;

    DB::table('order_payment')->insert(['order_id' => $pedido->id, 'method' => 'cashondelivery', 'method_title' => 'Pago contra entrega', 'created_at' => now(), 'updated_at' => now()]);

    // Una linea sin enviar: sin lineas Bagisto calcularia el pedido como cerrado (verdad vacia) al recalcular su estado.
    DB::table('order_items')->insert([
        'order_id' => $pedido->id, 'sku' => 'PAGE-'.$pedido->id, 'type' => 'simple', 'name' => 'Prenda',
        'qty_ordered' => 2, 'qty_shipped' => 0, 'qty_invoiced' => 0, 'qty_canceled' => 0, 'qty_refunded' => 0,
        'price' => $total / 2, 'base_price' => $total / 2, 'total' => $total, 'base_total' => $total,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$pedido, $factura];
}

function page_registrar(object $actor, int $orderId, string $monto, string $clave = 'clave-page-registrar-001'): int
{
    return app(RegistrarPagoService::class)->registrar($actor, $orderId, [
        'monto' => $monto, 'medio_cobro' => 'transferencia', 'plataforma' => 'nequi', 'fecha_pago' => now()->toDateString(), 'referencia' => 'TRX-9',
    ], $clave)->pagoId;
}

function page_saldo(int $orderId): object
{
    return app(CarteraConsultaService::class)->saldosDePedidos([$orderId])[$orderId];
}

test('confirmar: sin_revisar → confirmado crea la Transaction nativa, enlaza la aplicacion y NO mueve el saldo; confirmar dos veces no duplica', function () {
    $cliente = pagb_cliente();
    [$pedido, $factura] = page_pedido($cliente, 500000);
    $actor = pagb_asesor(array_merge(PAGE_REVISOR, ['ventas_asesor.cartera.registrar']));
    $pagoId = page_registrar($actor, $pedido->id, '300000');

    $antes = page_saldo($pedido->id);

    expect($antes->saldo)->toBe(200000.0)->and($antes->sinRevisar)->toBe(300000.0)->and($antes->verificado)->toBe(0.0)
        ->and(DB::table('order_transactions')->where('order_id', $pedido->id)->count())->toBe(0);

    $r = app(ConfirmarPagoService::class)->confirmar($actor, $pagoId);

    expect($r->estado)->toBe('confirmado')->and($r->yaProcesado)->toBeFalse()->and($r->transacciones)->toHaveCount(1);

    $tx = DB::table('order_transactions')->where('order_id', $pedido->id)->get();

    expect($tx)->toHaveCount(1)
        ->and((float) $tx[0]->amount)->toBe(300000.0)
        ->and((int) $tx[0]->invoice_id)->toBe($factura)
        ->and($tx[0]->type)->toBe('transferencia')              // medio de cobro real
        ->and($tx[0]->payment_method)->toBe('cashondelivery')   // metodo de pago DEL PEDIDO (Bagisto lo conoce)
        ->and($tx[0]->transaction_id)->toStartWith('BYL-PAGO-'.$pagoId.'-AP-')
        ->and(json_decode($tx[0]->data, true)['origen'])->toBe('bylopez_ventas_asesor');

    $pago = Pago::query()->findOrFail($pagoId);
    $aplicacion = $pago->aplicaciones()->first();

    expect($pago->estado)->toBe(EstadoPago::CONFIRMADO)
        ->and($pago->revisado_por_admin_id)->toBe((int) $actor->id)
        ->and($aplicacion->order_transaction_id)->toBe((int) $tx[0]->id)
        ->and($pago->eventos()->orderBy('id')->pluck('tipo')->map->value->all())->toBe(['registrado', 'confirmado']);

    // El saldo NO se mueve: solo pasa de «sin revisar» a «verificado» (la Transaction vinculada no cuenta como ajena).
    $despues = page_saldo($pedido->id);

    expect($despues->saldo)->toBe(200000.0)->and($despues->aplicadoTotal)->toBe(300000.0)
        ->and($despues->sinRevisar)->toBe(0.0)->and($despues->verificado)->toBe(300000.0)
        ->and($despues->requiereRevision)->toBeFalse()
        ->and(DB::table('invoices')->where('id', $factura)->value('state'))->toBe('pending')    // factura aun no cubierta
        ->and(DB::table('orders')->where('id', $pedido->id)->value('status'))->toBe('pending_payment');

    // Doble clic / reintento: resultado estable, ninguna segunda Transaction ni evento.
    $otra = app(ConfirmarPagoService::class)->confirmar($actor, $pagoId);

    expect($otra->yaProcesado)->toBeTrue()->and($otra->transacciones)->toHaveCount(1)
        ->and(DB::table('order_transactions')->where('order_id', $pedido->id)->count())->toBe(1)
        ->and($pago->eventos()->count())->toBe(2);
});

test('confirmar un abono multipedido: una Transaction por pedido, cada una enlazada; la factura cubierta pasa a paid y sin factura utilizable se rechaza sin escribir nada', function () {
    $cliente = pagb_cliente();
    [$a, $facturaA] = page_pedido($cliente, 50000, ['created_at' => now()->subDays(2)]);
    [$b, $facturaB] = page_pedido($cliente, 100000, ['created_at' => now()->subDay()]);
    $actor = pagb_asesor(array_merge(PAGE_REVISOR, ['ventas_asesor.cartera.abono_general']));

    $abono = app(RegistrarAbonoClienteService::class)->registrarAbono($actor, $cliente->id, [
        'monto' => '150000', 'medio_cobro' => 'transferencia', 'plataforma' => 'nequi', 'fecha_pago' => now()->toDateString(), 'referencia' => 'TRX-77',
    ], [['pedido_id' => $a->id, 'monto' => '50000'], ['pedido_id' => $b->id, 'monto' => '100000']], 'clave-page-abono-0000001');

    $r = app(ConfirmarPagoService::class)->confirmar($actor, $abono->pagoId);

    expect($r->transacciones)->toHaveCount(2);

    foreach ([[$a, $facturaA, 50000.0], [$b, $facturaB, 100000.0]] as [$pedido, $factura, $monto]) {
        $tx = DB::table('order_transactions')->where('order_id', $pedido->id)->get();

        expect($tx)->toHaveCount(1)->and((float) $tx[0]->amount)->toBe($monto)->and((int) $tx[0]->invoice_id)->toBe($factura)
            ->and(DB::table('bylopez_va_aplicaciones_pago')->where('order_id', $pedido->id)->value('order_transaction_id'))->toBe((int) $tx[0]->id)
            // Factura cubierta → paid y el pedido recalcula su estado (sin UPDATE directo).
            ->and(DB::table('invoices')->where('id', $factura)->value('state'))->toBe('paid')
            ->and(DB::table('orders')->where('id', $pedido->id)->value('status'))->not->toBe('pending_payment')
            ->and(page_saldo($pedido->id)->saldo)->toBe(0.0)
            ->and(page_saldo($pedido->id)->verificado)->toBe($monto);
    }

    // Pedido SIN factura: la Transaction nativa exige invoice_id → no se confirma, el pago sigue sin revisar y no hay nada escrito.
    [$sinFactura] = page_pedido($cliente, 80000, [], conFactura: false);
    $pagoId = page_registrar($actor = pagb_asesor(array_merge(PAGE_REVISOR, ['ventas_asesor.cartera.registrar'])), $sinFactura->id, '30000', 'clave-page-sinfactura-01');

    expect(fn () => app(ConfirmarPagoService::class)->confirmar($actor, $pagoId))
        ->toThrow(fn (Rechazo $e) => $e->codigo === Rechazo::SIN_FACTURA_DISPONIBLE && ($e->detalles['motivo'] ?? null) === 'sin_factura');

    expect(Pago::query()->findOrFail($pagoId)->estado)->toBe(EstadoPago::SIN_REVISAR)
        ->and(DB::table('order_transactions')->where('order_id', $sinFactura->id)->count())->toBe(0)
        ->and(DB::table('bylopez_va_pagos_eventos')->where('pago_id', $pagoId)->count())->toBe(1)
        ->and(page_saldo($sinFactura->id)->saldo)->toBe(50000.0);
});

test('rechazar: sin_revisar → rechazado anula (logicamente) las aplicaciones, restaura la deuda y no crea Transactions; un confirmado no se rechaza', function () {
    $cliente = pagb_cliente();
    [$pedido] = page_pedido($cliente, 184000);
    $actor = pagb_asesor(array_merge(PAGE_REVISOR, ['ventas_asesor.cartera.registrar']));
    $pagoId = page_registrar($actor, $pedido->id, '100000');

    expect(page_saldo($pedido->id)->saldo)->toBe(84000.0);

    $rechazar = app(RechazarPagoService::class);

    // Motivo y categoria obligatorios.
    expect(fn () => $rechazar->rechazar($actor, $pagoId, 'duplicado', '  '))->toThrow(fn (Rechazo $e) => $e->codigo === Rechazo::MOTIVO_OBLIGATORIO);
    expect(fn () => $rechazar->rechazar($actor, $pagoId, 'inventada', 'No aparece en el banco'))->toThrow(fn (Rechazo $e) => $e->codigo === Rechazo::CATEGORIA_INVALIDA);
    expect(Pago::query()->findOrFail($pagoId)->estado)->toBe(EstadoPago::SIN_REVISAR);

    $r = $rechazar->rechazar($actor, $pagoId, 'no_encontrado', 'La transferencia no aparece en el extracto');

    expect($r->estado)->toBe('rechazado')->and($r->yaProcesado)->toBeFalse();

    $pago = Pago::query()->findOrFail($pagoId);
    $aplicacion = $pago->aplicaciones()->first();
    $evento = $pago->eventos()->where('tipo', TipoEventoPago::RECHAZADO->value)->first();

    // Nada se borra: pago, aplicacion (anulada) y eventos siguen; la deuda vuelve por derivacion.
    expect($pago->estado)->toBe(EstadoPago::RECHAZADO)
        ->and($pago->motivo_revision)->toBe('La transferencia no aparece en el extracto')
        ->and($aplicacion->anulada_en)->not->toBeNull()
        ->and($aplicacion->anulada_por_admin_id)->toBe((int) $actor->id)
        ->and($evento->actor_admin_id)->toBe((int) $actor->id)
        ->and($evento->motivo)->toBe('La transferencia no aparece en el extracto')
        ->and($evento->datos['categoria'])->toBe('no_encontrado')
        ->and($pago->eventos()->count())->toBe(2)
        ->and(DB::table('order_transactions')->where('order_id', $pedido->id)->count())->toBe(0);

    $despues = page_saldo($pedido->id);

    expect($despues->saldo)->toBe(184000.0)->and($despues->aplicadoTotal)->toBe(0.0)->and($despues->sinRevisar)->toBe(0.0);

    // Idempotente, y un pago rechazado no se puede confirmar.
    expect($rechazar->rechazar($actor, $pagoId, 'no_encontrado', 'otra vez')->yaProcesado)->toBeTrue()
        ->and($pago->eventos()->count())->toBe(2);

    expect(fn () => app(ConfirmarPagoService::class)->confirmar($actor, $pagoId))->toThrow(fn (Rechazo $e) => $e->codigo === Rechazo::ESTADO_NO_REVISABLE);

    // Confirmado → rechazado seria un REVERSO (PAG-I): se niega.
    $confirmado = page_registrar($actor, $pedido->id, '50000', 'clave-page-registrar-002');
    app(ConfirmarPagoService::class)->confirmar($actor, $confirmado);

    expect(fn () => $rechazar->rechazar($actor, $confirmado, 'otro', 'Quiero deshacerlo'))
        ->toThrow(fn (Rechazo $e) => $e->codigo === Rechazo::PAGO_CONFIRMADO_NO_RECHAZABLE && $e->estadoHttp() === 409);

    expect(Pago::query()->findOrFail($confirmado)->estado)->toBe(EstadoPago::CONFIRMADO)
        ->and(DB::table('order_transactions')->where('order_id', $pedido->id)->count())->toBe(1);
});

test('ACL y alcance: exige cartera.revisar y cartera; view_own no revisa un pago con pedidos ajenos; la bandeja y el detalle responden y las rutas confirman/rechazan', function () {
    $cliente = pagb_cliente();
    [$pedido] = page_pedido($cliente, 100000);
    $registrador = pagb_asesor(array_merge(PAGE_REVISOR, ['ventas_asesor.cartera.registrar']));
    $pagoId = page_registrar($registrador, $pedido->id, '40000');

    // Sin `revisar`: el servicio lo niega aunque tenga alcance de cartera.
    $soloRegistra = pagb_asesor(['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.registrar', 'ventas_asesor.cartera.view_all']);

    expect(fn () => app(ConfirmarPagoService::class)->confirmar($soloRegistra, $pagoId))->toThrow(fn (Rechazo $e) => $e->codigo === Rechazo::SIN_PERMISO)
        ->and(fn () => app(RechazarPagoService::class)->rechazar($soloRegistra, $pagoId, 'otro', 'No debe poder'))->toThrow(fn (Rechazo $e) => $e->codigo === Rechazo::SIN_PERMISO);

    // `revisar` sin alcance de lectura de cartera tampoco revisa.
    $sinAlcance = pagb_asesor(['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.revisar']);

    expect(fn () => app(ConfirmarPagoService::class)->confirmar($sinAlcance, $pagoId))->toThrow(fn (Rechazo $e) => $e->codigo === Rechazo::SIN_PERMISO);

    // view_own: el pago toca un pedido que NO es suyo → fuera de alcance (404, sin revelar).
    $propio = pagb_asesor(['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.revisar', 'ventas_asesor.cartera.view_own']);

    expect(fn () => app(ConfirmarPagoService::class)->confirmar($propio, $pagoId))->toThrow(fn (Rechazo $e) => $e->codigo === Rechazo::PAGO_INEXISTENTE && $e->estadoHttp() === 404);

    pagb_atribuir($pedido, $propio);   // ahora el pedido (unico del pago) es suyo

    $revisor = pagb_asesor(PAGE_REVISOR);
    $urlConfirmar = route('admin.ventas_asesor.cartera.pagos.confirmar', ['pago' => $pagoId]);
    $urlRechazar = route('admin.ventas_asesor.cartera.pagos.rechazar', ['pago' => $pagoId]);

    // Rutas POST: sin la hoja `revisar` el Bouncer corta; con ella opera.
    $this->actingAs($soloRegistra, 'admin')->postJson($urlConfirmar)->assertUnauthorized();

    // Bandeja (view_all): KPI y filtro con el mismo criterio, detalle y menu.
    $bandeja = $this->actingAs($revisor, 'admin')->get(route('admin.ventas_asesor.cartera.index', ['resumen' => 'sin_revisar']))->assertOk()
        ->assertSee('data-resumen-activo="sin_revisar"', false)->assertSee('v-revision-pago', false);

    // La base de pruebas ya trae pagos sin revisar (pedidos clonados): el KPI se compara con un conteo independiente de la
    // base y este pago debe estar en la lista filtrada (antes se exigia exactamente 1, que solo valia con la base vacia).
    $sinRevisar = DB::table('bylopez_va_pagos')->where('estado', 'sin_revisar');
    expect($bandeja->viewData('pagos')->total())->toBe($bandeja->viewData('indicadores')['sin_revisar'])
        ->and($bandeja->viewData('indicadores')['sin_revisar'])->toBe((clone $sinRevisar)->count())
        ->and($bandeja->viewData('indicadores')['monto_sin_revisar'])->toBe(round((float) (clone $sinRevisar)->sum('monto'), 2))
        ->and(DB::table('bylopez_va_pagos')->where('id', $pagoId)->value('estado'))->toBe('sin_revisar');

    $this->actingAs($revisor, 'admin')->get(route('admin.ventas_asesor.cartera.index', ['pago' => $pagoId]))->assertOk()->assertSee('data-aplicacion', false);
    $this->actingAs($propio, 'admin')->get(route('admin.ventas_asesor.cartera.index'))->assertOk();   // view_own ve la bandeja con lo suyo

    // Confirmar por HTTP; rechazar un pago ya confirmado → 409.
    $this->actingAs($revisor, 'admin')->postJson($urlConfirmar)->assertOk()->assertJsonPath('estado', 'confirmado');
    $this->actingAs($revisor, 'admin')->postJson($urlConfirmar)->assertOk()->assertJsonPath('ya_procesado', true);
    $this->actingAs($revisor, 'admin')->postJson($urlRechazar, ['categoria' => 'otro', 'motivo' => 'Quiero deshacerlo'])->assertStatus(409)->assertJsonPath('codigo', 'pago_confirmado_no_rechazable');
    $this->actingAs($revisor, 'admin')->postJson($urlRechazar, ['categoria' => 'otro', 'motivo' => 'x'])->assertStatus(422);

    expect(DB::table('order_transactions')->where('order_id', $pedido->id)->count())->toBe(1)
        ->and(page_saldo($pedido->id)->saldo)->toBe(60000.0);
});
