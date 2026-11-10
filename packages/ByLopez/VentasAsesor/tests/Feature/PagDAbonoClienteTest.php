<?php

use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Enums\TipoEventoPago;
use ByLopez\VentasAsesor\Exceptions\RegistroPagoInvalidoException as Rechazo;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarAbonoClienteService;
use ByLopez\VentasAsesor\Support\Cartera\DistribucionFifo;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../helpersPagB.php';

uses(TestCase::class);

/*
| PAG-D · Cartera del cliente + abono general multipedido (D-59, Doc. 05 §42.5 / §42.10). Pruebas deliberadamente
| pocas, con pedidos de fabrica dentro de la transaccion de la prueba (nunca pedidos reales).
*/

const PAGD_PERMISOS = ['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.abono_general', 'ventas_asesor.cartera.view_all'];

beforeEach(function () {
    pag_linea_base();
    pagb_corte('2000-01-01');
});

function pagd_datos(string $monto, array $extra = []): array
{
    return array_merge(['monto' => $monto, 'medio_cobro' => 'transferencia', 'plataforma' => 'nequi', 'fecha_pago' => now()->toDateString(), 'referencia' => 'TRX-77'], $extra);
}

function pagd_linea(int $pedidoId, string $monto): array
{
    return ['pedido_id' => $pedidoId, 'monto' => $monto];
}

function pagd_abonar(object $actor, int $clienteId, array $datos, array $distribucion, string $clave = 'clave-abono-idempotencia-01')
{
    return app(RegistrarAbonoClienteService::class)->registrarAbono($actor, $clienteId, $datos, $distribucion, $clave);
}

test('abono multipedido con propuesta FIFO: A 50k + B 100k, abono de 120k → 50k + 70k, un solo pago sin revisar y sin Transactions', function () {
    $cliente = pagb_cliente();
    $a = pagb_pedido($cliente, 50000, ['created_at' => now()->subDays(3)]);
    $b = pagb_pedido($cliente, 100000, ['created_at' => now()->subDays(1)]);
    $actor = pagb_asesor(PAGD_PERMISOS);
    $consulta = app(CarteraConsultaService::class);

    // Cartera antes: deuda 150.000, orden FIFO (mas antiguo primero).
    $antes = $consulta->carteraDelCliente($cliente->id, $actor);

    expect($antes->carteraTotal)->toBe(150000.0)
        ->and(array_map(fn ($p) => $p->orderId, $antes->pedidos))->toBe([$a->id, $b->id]);

    $propuesta = DistribucionFifo::proponer($antes->pedidos, 120000);

    expect($propuesta)->toBe([$a->id => 50000.0, $b->id => 70000.0]);

    $r = pagd_abonar($actor, $cliente->id, pagd_datos('120000'), array_map(fn ($id, $m) => pagd_linea($id, (string) $m), array_keys($propuesta), $propuesta));

    expect($r->duplicado)->toBeFalse()
        ->and($r->estado)->toBe('sin_revisar')
        ->and($r->aplicaciones)->toHaveCount(2);

    $pago = Pago::query()->findOrFail($r->pagoId);

    expect($pago->estado)->toBe(EstadoPago::SIN_REVISAR)
        ->and($pago->customer_id)->toBe((int) $cliente->id)
        ->and($pago->aplicaciones()->orderBy('order_id')->pluck('monto', 'order_id')->map(fn ($m) => (float) $m)->all())
        ->toBe([$a->id => 50000.0, $b->id => 70000.0])
        ->and($pago->eventos()->count())->toBe(1)
        ->and($pago->eventos()->first()->tipo)->toBe(TipoEventoPago::REGISTRADO);

    // Saldos derivados al instante: A saldada, B con 30.000; cartera del cliente 30.000, sin revisar 120.000, verificado 0.
    $despues = $consulta->carteraDelCliente($cliente->id, $actor);

    expect($despues->carteraTotal)->toBe(30000.0)
        ->and($despues->sinRevisarTotal())->toBe(120000.0)
        ->and($despues->verificadoTotal())->toBe(0.0)
        ->and($despues->pedidosConSaldo())->toBe(1);

    expect(DB::table('order_transactions')->whereIn('order_id', [$a->id, $b->id])->count())->toBe(0)
        ->and(DB::table('orders')->whereIn('id', [$a->id, $b->id])->where('status', '!=', 'pending_payment')->count())->toBe(0);
});

test('distribucion manual valida; suma distinta, linea sobre el saldo, pedido ajeno o repetido → rollback total sin pago huerfano', function () {
    $cliente = pagb_cliente();
    $a = pagb_pedido($cliente, 50000, ['created_at' => now()->subDays(3)]);
    $b = pagb_pedido($cliente, 100000, ['created_at' => now()->subDays(1)]);
    $otro = pagb_pedido(pagb_cliente(), 80000);
    $actor = pagb_asesor(PAGD_PERMISOS);

    $rechaza = fn (string $monto, array $lineas, string $codigo, string $clave) => expect(fn () => pagd_abonar($actor, $cliente->id, pagd_datos($monto), $lineas, $clave))
        ->toThrow(fn (Rechazo $e) => $e->codigo === $codigo);

    $rechaza('120000', [pagd_linea($a->id, '20000'), pagd_linea($b->id, '90000')], Rechazo::DISTRIBUCION_INVALIDA, 'clave-abono-idempotencia-02');       // suma 110k ≠ 120k
    $rechaza('120000', [pagd_linea($a->id, '10000'), pagd_linea($b->id, '110000')], Rechazo::MONTO_EXCEDE_SALDO, 'clave-abono-idempotencia-03');         // B recibe mas que su saldo
    $rechaza('120000', [pagd_linea($a->id, '60000'), pagd_linea($b->id, '60000')], Rechazo::MONTO_EXCEDE_SALDO, 'clave-abono-idempotencia-04');         // A recibe mas que su saldo
    $rechaza('120000', [pagd_linea($a->id, '20000'), pagd_linea($otro->id, '100000')], Rechazo::PEDIDO_DE_OTRO_CLIENTE, 'clave-abono-idempotencia-05');  // pedido de otro cliente
    $rechaza('100000', [pagd_linea($a->id, '50000'), pagd_linea($a->id, '50000')], Rechazo::DISTRIBUCION_INVALIDA, 'clave-abono-idempotencia-06');      // pedido repetido
    $rechaza('150001', [pagd_linea($a->id, '50000'), pagd_linea($b->id, '100001')], Rechazo::MONTO_EXCEDE_SALDO, 'clave-abono-idempotencia-07');        // supera la deuda: sin saldo a favor

    expect(pag_nuevos('bylopez_va_pagos'))->toBe(0)
        ->and(pag_nuevos('bylopez_va_aplicaciones_pago'))->toBe(0)
        ->and(pag_nuevos('bylopez_va_pagos_eventos'))->toBe(0);

    // Distribucion manual valida (no FIFO): A 20k + B 100k = 120k.
    $r = pagd_abonar($actor, $cliente->id, pagd_datos('120000'), [pagd_linea($b->id, '100000'), pagd_linea($a->id, '20000')], 'clave-abono-idempotencia-08');

    expect($r->aplicaciones)->toHaveCount(2)
        ->and(collect($r->aplicaciones)->pluck('monto', 'order_id')->all())->toBe([$a->id => 20000.0, $b->id => 100000.0])
        ->and(app(CarteraConsultaService::class)->carteraDelCliente($cliente->id, $actor)->carteraTotal)->toBe(30000.0);
});

test('idempotencia: misma clave y misma distribucion no duplica; otra distribucion con la misma clave → 409', function () {
    $cliente = pagb_cliente();
    $a = pagb_pedido($cliente, 50000, ['created_at' => now()->subDays(3)]);
    $b = pagb_pedido($cliente, 100000, ['created_at' => now()->subDays(1)]);
    $actor = pagb_asesor(PAGD_PERMISOS);
    $lineas = [pagd_linea($a->id, '50000'), pagd_linea($b->id, '70000')];

    $primero = pagd_abonar($actor, $cliente->id, pagd_datos('120000'), $lineas);
    $segundo = pagd_abonar($actor, $cliente->id, pagd_datos('120000'), array_reverse($lineas));   // el orden de las lineas no cambia la huella

    expect($segundo->duplicado)->toBeTrue()
        ->and($segundo->pagoId)->toBe($primero->pagoId)
        ->and(pag_nuevos('bylopez_va_pagos'))->toBe(1)
        ->and(pag_nuevos('bylopez_va_aplicaciones_pago'))->toBe(2)
        ->and(pag_nuevos('bylopez_va_pagos_eventos'))->toBe(1);

    expect(fn () => pagd_abonar($actor, $cliente->id, pagd_datos('120000'), [pagd_linea($a->id, '20000'), pagd_linea($b->id, '100000')]))
        ->toThrow(fn (Rechazo $e) => $e->codigo === Rechazo::IDEMPOTENCIA_EN_CONFLICTO && $e->estadoHttp() === 409);

    expect(pag_nuevos('bylopez_va_pagos'))->toBe(1);
});

test('ACL y alcance: exige abono_general; view_own solo opera sus pedidos; la pantalla de cartera respeta view_own / view_all', function () {
    $cliente = pagb_cliente();
    $asesor = pagb_asesor(['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.abono_general', 'ventas_asesor.cartera.view_own']);
    $mio = pagb_pedido($cliente, 60000, ['created_at' => now()->subDays(2)]);
    $ajeno = pagb_pedido($cliente, 90000, ['created_at' => now()->subDays(1)]);
    pagb_atribuir($mio, $asesor);
    pagb_atribuir($ajeno, pagb_asesor(['ventas_asesor']));

    $url = route('admin.ventas_asesor.cartera.abonos.store', ['cliente' => $cliente->id]);
    $cuerpo = fn (array $dist, string $monto) => pagd_datos($monto) + ['idempotency_key' => 'clave-abono-http-'.uniqid(), 'distribucion' => $dist];

    // Con `registrar` (PAG-C) pero SIN `abono_general`: el Bouncer corta en la ruta.
    $this->actingAs(pagb_asesor(['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.registrar', 'ventas_asesor.cartera.view_all']), 'admin')
        ->postJson($url, $cuerpo([pagd_linea($mio->id, '1000')], '1000'))->assertUnauthorized();

    // view_own: un pedido ajeno dentro del abono rechaza TODO sin revelarlo; solo lo propio funciona.
    $this->actingAs($asesor, 'admin')
        ->postJson($url, $cuerpo([pagd_linea($mio->id, '10000'), pagd_linea($ajeno->id, '20000')], '30000'))
        ->assertNotFound()->assertJsonPath('codigo', 'pedido_inexistente');

    expect(pag_nuevos('bylopez_va_pagos'))->toBe(0);

    $this->actingAs($asesor, 'admin')
        ->postJson($url, $cuerpo([pagd_linea($mio->id, '10000')], '10000'))
        ->assertCreated()->assertJsonPath('pedidos_aplicados', 1);

    $pagoId = Pago::query()->orderByDesc('id')->value('id'); // el recien creado (la base de pruebas conserva pagos de otras suites)

    // Pantalla: view_own ve su cartera (solo su pedido) con el resumen del abono; sin alcance → 403; cliente sin pedidos propios → 404.
    $pantalla = $this->actingAs($asesor, 'admin')->get(route('admin.ventas_asesor.cartera.index', ['cliente' => $cliente->id, 'abono' => $pagoId]))
        ->assertOk()->assertSee('data-abono-registrado', false)->assertSee('v-registrar-abono', false)->assertViewHas('deuda_elegible', 50000.0);

    expect($pantalla->viewData('cartera')->pedidos)->toHaveCount(1);

    $this->actingAs(pagb_asesor(['ventas_asesor']), 'admin')->get(route('admin.ventas_asesor.cartera.index', ['cliente' => $cliente->id]))->assertUnauthorized();
    $this->actingAs(pagb_asesor(['ventas_asesor', 'ventas_asesor.cartera']), 'admin')->get(route('admin.ventas_asesor.cartera.index', ['cliente' => $cliente->id]))->assertForbidden();
    $this->actingAs($asesor, 'admin')->get(route('admin.ventas_asesor.cartera.index', ['cliente' => pagb_cliente()->id]))->assertNotFound();
    $this->actingAs(pagb_asesor(PAGD_PERMISOS), 'admin')->get(route('admin.ventas_asesor.cartera.index', ['cliente' => $cliente->id]))
        ->assertOk()->assertViewHas('deuda_elegible', 140000.0);
});
