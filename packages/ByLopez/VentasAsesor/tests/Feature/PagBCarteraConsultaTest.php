<?php

use ByLopez\VentasAsesor\DataObjects\SaldoPedido;
use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor as Autz;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../helpersPagB.php';

uses(TestCase::class);

/*
| PAG-B · consulta de Cartera (D-59, Doc. 05 §42.3): saldo comercial del pedido, composicion del pago y cartera
| del cliente. SOLO LECTURA, derivada (P-03). Estas pruebas simulan con filas directas lo que en bloques
| posteriores haran los servicios de registrar / confirmar / rechazar: aqui no existe ninguno.
*/

beforeEach(function () {
    pagb_corte('2000-01-01');     // Cartera activa para casi cualquier pedido de prueba
});

function pagb_servicio(): CarteraConsultaService
{
    return app(CarteraConsultaService::class);
}

function pagb_saldo(int $orderId): SaldoPedido
{
    return pagb_servicio()->saldosDePedidos([$orderId])[$orderId];
}

/** Resumen compacto de la composicion para comparar de una vez. */
function pagb_resumen(SaldoPedido $s): array
{
    return [
        'total' => $s->total, 'aplicado' => $s->aplicadoTotal, 'verificado' => $s->verificado,
        'sin_revisar' => $s->sinRevisar, 'ajenas' => $s->transaccionesAjenas, 'saldo' => $s->saldo,
    ];
}

/* ---------------------------------------------------------------- fórmula */

test('ejemplo canonico: 500.000 con pago sin revisar de 300.000 → saldo 200.000; CONFIRMAR no mueve el saldo (sin -100.000)', function () {
    $cliente = pagb_cliente();
    $pedido = pagb_pedido($cliente, 500000);
    $factura = pagb_factura($pedido, 'pending');

    expect(pagb_resumen(pagb_saldo($pedido->id)))->toBe(['total' => 500000.0, 'aplicado' => 0.0, 'verificado' => 0.0, 'sin_revisar' => 0.0, 'ajenas' => 0.0, 'saldo' => 500000.0]);

    // REGISTRAR: pago ByLopez sin revisar con su aplicacion. Todavia no existe ninguna Transaction nativa.
    $pago = pagb_pago($cliente, ['monto' => 300000]);
    $aplicacion = pagb_aplicar($pago, $pedido, 300000);

    $s = pagb_saldo($pedido->id);

    expect(pagb_resumen($s))->toBe(['total' => 500000.0, 'aplicado' => 300000.0, 'verificado' => 0.0, 'sin_revisar' => 300000.0, 'ajenas' => 0.0, 'saldo' => 200000.0])
        ->and($s->enCartera)->toBeTrue()
        ->and($s->requiereRevision)->toBeFalse()
        ->and(DB::table('order_transactions')->where('order_id', $pedido->id)->count())->toBe(0);

    // CONFIRMAR (simulado): nace la Transaction nativa y se VINCULA a su aplicacion; el pago queda confirmado.
    $transaccion = pagb_transaccion($pedido, $factura, 300000);
    $aplicacion->update(['order_transaction_id' => $transaccion, 'invoice_id' => $factura]);
    $pago->update(['estado' => EstadoPago::CONFIRMADO]);

    $s = pagb_saldo($pedido->id);

    // Lo que era «sin revisar» pasa a «verificado»; el saldo NO baja otra vez.
    expect(pagb_resumen($s))->toBe(['total' => 500000.0, 'aplicado' => 300000.0, 'verificado' => 300000.0, 'sin_revisar' => 0.0, 'ajenas' => 0.0, 'saldo' => 200000.0])
        ->and($s->requiereRevision)->toBeFalse();

    // Contraste: sin el vinculo, la MISMA Transaction seria «ajena» y se descontaria dos veces.
    $aplicacion->update(['order_transaction_id' => null]);

    $sinVinculo = pagb_saldo($pedido->id);

    expect($sinVinculo->transaccionesAjenas)->toBe(300000.0)
        ->and($sinVinculo->aplicadoTotal)->toBe(600000.0)
        ->and($sinVinculo->saldo)->toBe(0.0)
        ->and($sinVinculo->tieneMotivo(SaldoPedido::REVISAR_SOBREAPLICADO))->toBeTrue();
});

test('rechazar: las aplicaciones anuladas dejan de contar y la deuda reaparece; una aplicacion activa en un pago cerrado se senala', function () {
    $cliente = pagb_cliente();
    $pedido = pagb_pedido($cliente, 500000);
    $pago = pagb_pago($cliente, ['monto' => 300000]);
    $aplicacion = pagb_aplicar($pago, $pedido, 300000);

    expect(pagb_saldo($pedido->id)->saldo)->toBe(200000.0);

    // RECHAZAR (simulado): pago rechazado + sus aplicaciones anuladas (no borradas).
    $pago->update(['estado' => EstadoPago::RECHAZADO, 'motivo_revision' => 'No llego el dinero']);
    $aplicacion->update(['anulada_en' => now(), 'anulada_por_admin_id' => 1]);

    $s = pagb_saldo($pedido->id);

    expect(pagb_resumen($s))->toBe(['total' => 500000.0, 'aplicado' => 0.0, 'verificado' => 0.0, 'sin_revisar' => 0.0, 'ajenas' => 0.0, 'saldo' => 500000.0])
        ->and($s->requiereRevision)->toBeFalse()
        ->and($aplicacion->fresh())->not->toBeNull();                    // la historia se conserva

    // Inconsistencia (I-3): aplicacion ACTIVA de un pago ya rechazado. No cuenta, pero se senala.
    $aplicacion->update(['anulada_en' => null]);

    $incoherente = pagb_saldo($pedido->id);

    expect($incoherente->saldo)->toBe(500000.0)
        ->and($incoherente->aplicadoTotal)->toBe(0.0)
        ->and($incoherente->tieneMotivo(SaldoPedido::REVISAR_APLICACION_EN_PAGO_CERRADO))->toBeTrue()
        ->and($incoherente->requiereRevision)->toBeTrue();

    // Igual con un pago reversado.
    $pago->update(['estado' => EstadoPago::REVERSADO]);
    expect(pagb_saldo($pedido->id)->tieneMotivo(SaldoPedido::REVISAR_APLICACION_EN_PAGO_CERRADO))->toBeTrue();
});

test('Transactions nativas ajenas a ByLopez descuentan el saldo y cuentan como verificadas, junto a los pagos ByLopez', function () {
    $cliente = pagb_cliente();
    $pedido = pagb_pedido($cliente, 500000);
    $factura = pagb_factura($pedido, 'pending');

    pagb_transaccion($pedido, $factura, 100000);                 // creada a mano en el panel nativo: sin vinculo

    expect(pagb_resumen(pagb_saldo($pedido->id)))->toBe(['total' => 500000.0, 'aplicado' => 100000.0, 'verificado' => 100000.0, 'sin_revisar' => 0.0, 'ajenas' => 100000.0, 'saldo' => 400000.0]);

    pagb_aplicar(pagb_pago($cliente, ['monto' => 150000]), $pedido, 150000);

    expect(pagb_resumen(pagb_saldo($pedido->id)))->toBe(['total' => 500000.0, 'aplicado' => 250000.0, 'verificado' => 100000.0, 'sin_revisar' => 150000.0, 'ajenas' => 100000.0, 'saldo' => 250000.0]);

    // aplicado_total = verificado + sin_revisar, siempre.
    $s = pagb_saldo($pedido->id);
    expect($s->aplicadoTotal)->toBe(round($s->verificado + $s->sinRevisar, 2));
});

test('pago parcial sobre un pedido: dos pagos suman y el saldo nunca es negativo', function () {
    $cliente = pagb_cliente();
    $pedido = pagb_pedido($cliente, 100.10);

    pagb_aplicar(pagb_pago($cliente, ['monto' => 33.33]), $pedido, 33.33);

    expect(pagb_saldo($pedido->id)->saldo)->toBe(66.77);

    pagb_aplicar(pagb_pago($cliente, ['monto' => 66.77]), $pedido, 66.77);

    expect(pagb_saldo($pedido->id)->saldo)->toBe(0.0);

    pagb_aplicar(pagb_pago($cliente, ['monto' => 5]), $pedido, 5);

    $s = pagb_saldo($pedido->id);

    expect($s->saldo)->toBe(0.0)                                  // nunca negativo
        ->and($s->tieneMotivo(SaldoPedido::REVISAR_SOBREAPLICADO))->toBeTrue();
});

/* ---------------------------------------------------------------- fuera de Cartera / revisar */

test('pedidos cancelados, cerrados o fraude no generan saldo (fuera de Cartera, sin cifras)', function () {
    $cliente = pagb_cliente();

    foreach (['canceled', 'closed', 'fraud'] as $estado) {
        $s = pagb_saldo(pagb_pedido($cliente, 100000, ['status' => $estado])->id);

        expect($s->enCartera)->toBeFalse()
            ->and($s->motivoFuera)->toBe(SaldoPedido::FUERA_ESTADO_NO_APLICA)
            ->and($s->saldo)->toBeNull()
            ->and($s->aplicadoTotal)->toBeNull()
            ->and($s->total)->toBe(100000.0);
    }

    foreach (['pending', 'pending_payment', 'processing', 'completed'] as $estado) {
        expect(pagb_saldo(pagb_pedido($cliente, 100000, ['status' => $estado])->id)->enCartera)->toBeTrue($estado);
    }
});

test('P-10: un pedido con Refund se marca para revision y la cartera lo muestra aparte', function () {
    $cliente = pagb_cliente();
    $pedido = pagb_pedido($cliente, 150000, ['base_grand_total_refunded' => 10000]);

    $s = pagb_saldo($pedido->id);

    expect($s->requiereRevision)->toBeTrue()
        ->and($s->tieneMotivo(SaldoPedido::REVISAR_REEMBOLSO))->toBeTrue()
        ->and($s->saldo)->toBe(150000.0);                          // no se inventa como se resuelve el Refund
});

test('fecha de corte (C-3): sin fecha Cartera esta inactiva; los pedidos anteriores son historico; el limite entra', function () {
    $cliente = pagb_cliente();
    $antes = pagb_pedido($cliente, 100000, ['created_at' => '2026-03-01 09:59:59']);
    $justo = pagb_pedido($cliente, 100000, ['created_at' => '2026-03-01 10:00:00']);
    $despues = pagb_pedido($cliente, 100000, ['created_at' => '2026-04-01 10:00:00']);

    pagb_corte(null);

    foreach ([$antes, $justo, $despues] as $pedido) {
        $s = pagb_saldo($pedido->id);

        expect($s->enCartera)->toBeFalse()->and($s->motivoFuera)->toBe(SaldoPedido::FUERA_CARTERA_INACTIVA)->and($s->saldo)->toBeNull();
    }

    pagb_corte('2026-03-01 10:00:00');

    expect(pagb_saldo($antes->id)->motivoFuera)->toBe(SaldoPedido::FUERA_ANTERIOR_A_CORTE)
        ->and(pagb_saldo($antes->id)->saldo)->toBeNull()
        ->and(pagb_saldo($justo->id)->enCartera)->toBeTrue()
        ->and(pagb_saldo($despues->id)->enCartera)->toBeTrue();
});

test('historico: Invoice paid sin Transaction ni aplicacion NO es pago; se revisa y el saldo sigue completo (el pedido 41 es el ejemplo)', function () {
    $cliente = pagb_cliente();
    $pedido = pagb_pedido($cliente, 14800, ['status' => 'processing']);
    pagb_factura($pedido, 'paid');

    $s = pagb_saldo($pedido->id);

    expect($s->saldo)->toBe(14800.0)
        ->and($s->aplicadoTotal)->toBe(0.0)
        ->and($s->requiereRevision)->toBeTrue()
        ->and($s->tieneMotivo(SaldoPedido::REVISAR_FACTURA_PAGADA_SIN_EVIDENCIA))->toBeTrue();

    // Con evidencia real (una Transaction nativa) deja de ser sospechoso.
    $factura = (int) DB::table('invoices')->where('order_id', $pedido->id)->value('id');
    pagb_transaccion($pedido, $factura, 14800);

    $conEvidencia = pagb_saldo($pedido->id);

    expect($conEvidencia->saldo)->toBe(0.0)
        ->and($conEvidencia->tieneMotivo(SaldoPedido::REVISAR_FACTURA_PAGADA_SIN_EVIDENCIA))->toBeFalse()
        ->and($conEvidencia->requiereRevision)->toBeFalse();
});

/* ---------------------------------------------------------------- cartera del cliente */

test('cartera del cliente: ejemplo Rosario (A 200.000, B 300.000, C 400.000) con FIFO y rechazo; Refund aparte; cancelados fuera', function () {
    $admin = pagb_asesor([Autz::PERMISO_MODULO, Autz::PERMISO_CARTERA_VER_TODA]);
    $rosario = pagb_cliente();
    $otra = pagb_cliente();

    $a = pagb_pedido($rosario, 200000, ['created_at' => now()->subDays(3)]);
    $b = pagb_pedido($rosario, 300000, ['created_at' => now()->subDays(2)]);
    $c = pagb_pedido($rosario, 400000, ['created_at' => now()->subDays(1)]);
    $cancelado = pagb_pedido($rosario, 100000, ['status' => 'canceled']);
    $conRefund = pagb_pedido($rosario, 150000, ['base_grand_total_refunded' => 10000]);
    pagb_pedido($otra, 999000);                                   // de otra clienta: no entra

    $cartera = pagb_servicio()->carteraDelCliente($rosario->id, $admin);

    expect($cartera->carteraTotal)->toBe(900000.0)               // A + B + C
        ->and($cartera->porRevisarTotal)->toBe(150000.0)         // el Refund, aparte
        ->and($cartera->fueraDeCartera)->toBe(1)                 // el cancelado
        ->and($cartera->carteraActiva)->toBeTrue()
        ->and(array_map(fn (SaldoPedido $p) => $p->orderId, $cartera->pedidos))->toBe([$a->id, $b->id, $c->id, $conRefund->id]);   // FIFO

    // Abono de 500.000: la propuesta FIFO cubre A y B.
    $pago = pagb_pago($rosario, ['monto' => 500000]);
    pagb_aplicar($pago, $a, 200000, ['posicion' => 1]);
    pagb_aplicar($pago, $b, 300000, ['posicion' => 2]);

    $cartera = pagb_servicio()->carteraDelCliente($rosario->id, $admin);
    $saldos = collect($cartera->pedidos)->mapWithKeys(fn (SaldoPedido $p) => [$p->orderId => $p->saldo]);

    expect($cartera->carteraTotal)->toBe(400000.0)
        ->and($saldos[$a->id])->toBe(0.0)->and($saldos[$b->id])->toBe(0.0)->and($saldos[$c->id])->toBe(400000.0)
        ->and(collect($cartera->pedidos)->firstWhere('orderId', $a->id)->sinRevisar)->toBe(200000.0);

    // El pago se rechaza: las aplicaciones se anulan y la cartera vuelve a 900.000.
    $pago->update(['estado' => EstadoPago::RECHAZADO]);
    DB::table('bylopez_va_aplicaciones_pago')->where('pago_id', $pago->id)->update(['anulada_en' => now()]);

    $cartera = pagb_servicio()->carteraDelCliente($rosario->id, $admin);
    $saldos = collect($cartera->pedidos)->mapWithKeys(fn (SaldoPedido $p) => [$p->orderId => $p->saldo]);

    expect($cartera->carteraTotal)->toBe(900000.0)
        ->and($saldos[$a->id])->toBe(200000.0)->and($saldos[$b->id])->toBe(300000.0)->and($saldos[$c->id])->toBe(400000.0)
        ->and($cartera->toArray())->toHaveKeys(['customer_id', 'cartera_activa', 'fecha_corte', 'cartera_total', 'por_revisar_total', 'pedidos', 'fuera_de_cartera']);
});

test('cartera: sin fecha de corte esta inactiva y no calcula nada; un cliente sin pedidos tiene cartera 0', function () {
    $admin = pagb_asesor([Autz::PERMISO_MODULO, Autz::PERMISO_CARTERA_VER_TODA]);
    $cliente = pagb_cliente();
    pagb_pedido($cliente, 100000);

    pagb_corte(null);

    $inactiva = pagb_servicio()->carteraDelCliente($cliente->id, $admin);

    expect($inactiva->carteraActiva)->toBeFalse()
        ->and($inactiva->fechaCorte)->toBeNull()
        ->and($inactiva->carteraTotal)->toBe(0.0)
        ->and($inactiva->pedidos)->toBe([])
        ->and($inactiva->fueraDeCartera)->toBe(1);

    pagb_corte('2000-01-01');

    $vacia = pagb_servicio()->carteraDelCliente(pagb_cliente()->id, $admin);

    expect($vacia->carteraTotal)->toBe(0.0)->and($vacia->pedidos)->toBe([])->and($vacia->fueraDeCartera)->toBe(0);
});

/* ---------------------------------------------------------------- alcance por actor (I-10) */

test('alcance: view_own solo ve lo atribuido a el; view_all todo; sin permiso (o solo la agrupadora) lanza; lo ajeno no se revela', function () {
    $cliente = pagb_cliente();
    $uno = pagb_asesor([Autz::PERMISO_MODULO, Autz::PERMISO_CARTERA_VER_PROPIA]);
    $dos = pagb_asesor([Autz::PERMISO_MODULO, Autz::PERMISO_CARTERA_VER_PROPIA]);

    $deUno = pagb_pedido($cliente, 100000, ['created_at' => now()->subDays(3)]);
    $deDos = pagb_pedido($cliente, 200000, ['created_at' => now()->subDays(2)]);
    $historico = pagb_pedido($cliente, 300000, ['created_at' => now()->subDays(1)]);      // sin atribucion

    pagb_atribuir($deUno, $uno);
    pagb_atribuir($deDos, $dos);
    pagb_atribuir($historico, null);

    $de = fn ($asesor) => array_map(fn (SaldoPedido $p) => $p->orderId, pagb_servicio()->carteraDelCliente($cliente->id, $asesor)->pedidos);

    expect($de($uno))->toBe([$deUno->id])
        ->and($de($dos))->toBe([$deDos->id])
        ->and(pagb_servicio()->carteraDelCliente($cliente->id, $uno)->carteraTotal)->toBe(100000.0);

    $todo = pagb_asesor([Autz::PERMISO_MODULO, Autz::PERMISO_CARTERA_VER_TODA]);

    expect($de($todo))->toBe([$deUno->id, $deDos->id, $historico->id])
        ->and(pagb_servicio()->carteraDelCliente($cliente->id, $todo)->carteraTotal)->toBe(600000.0)
        ->and($de(f03b_admin('all')))->toBe([$deUno->id, $deDos->id, $historico->id]);

    // Un pedido: propio, ajeno (no se revela si existe) e inexistente.
    expect(pagb_servicio()->saldoDePedido($deUno->id, $uno)?->orderId)->toBe($deUno->id)
        ->and(pagb_servicio()->saldoDePedido($deDos->id, $uno))->toBeNull()
        ->and(pagb_servicio()->saldoDePedido($historico->id, $uno))->toBeNull()          // RA-6: sin atribucion, se niega
        ->and(pagb_servicio()->saldoDePedido(999999999, $todo))->toBeNull()
        ->and(pagb_servicio()->saldoDePedido($deDos->id, $todo)?->orderId)->toBe($deDos->id);

    // Sin permiso de cartera, aunque tenga el modulo, la agrupadora o ver todos los pedidos.
    foreach ([[Autz::PERMISO_MODULO], [Autz::PERMISO_MODULO, 'ventas_asesor.cartera'], [Autz::PERMISO_MODULO, Autz::PERMISO_PEDIDOS_VER_TODOS]] as $permisos) {
        $sin = pagb_asesor($permisos);

        expect(fn () => pagb_servicio()->carteraDelCliente($cliente->id, $sin))->toThrow(AuthorizationException::class)
            ->and(fn () => pagb_servicio()->saldoDePedido($deUno->id, $sin))->toThrow(AuthorizationException::class);
    }
});

/* ---------------------------------------------------------------- lote y rendimiento */

test('por lote: ids inexistentes se omiten, los repetidos se unifican y el orden de entrada se conserva', function () {
    $cliente = pagb_cliente();
    $uno = pagb_pedido($cliente, 100);
    $dos = pagb_pedido($cliente, 200);

    expect(pagb_servicio()->saldosDePedidos([]))->toBe([])
        ->and(array_keys(pagb_servicio()->saldosDePedidos([$dos->id, 999999999, $uno->id, $dos->id])))->toBe([$dos->id, $uno->id]);
});

test('sin N+1: las consultas no crecen con la cantidad de pedidos (maximo 6 por lote: 5 de Cartera + la agrupada de devoluciones recibidas (C5))', function () {
    $cliente = pagb_cliente();
    $contar = function (array $ids): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        pagb_servicio()->saldosDePedidos($ids);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $primero = pagb_pedido($cliente, 100000);
    pagb_aplicar(pagb_pago($cliente), $primero, 1000);

    $conUno = $contar([$primero->id]);

    $ids = [$primero->id];

    for ($i = 0; $i < 14; $i++) {
        $pedido = pagb_pedido($cliente, 100000 + $i);
        $factura = pagb_factura($pedido, $i % 2 ? 'paid' : 'pending');
        pagb_transaccion($pedido, $factura, 500);
        pagb_aplicar(pagb_pago($cliente), $pedido, 1000);
        $ids[] = $pedido->id;
    }

    $conQuince = $contar($ids);

    expect($conUno)->toBeLessThanOrEqual(6)
        ->and($conQuince)->toBe($conUno)
        ->and(count(pagb_servicio()->saldosDePedidos($ids)))->toBe(15);
});

test('el servicio es de solo lectura: consultar no escribe nada', function () {
    $cliente = pagb_cliente();
    $pedido = pagb_pedido($cliente, 100000);
    $pago = pagb_pago($cliente);
    pagb_aplicar($pago, $pedido, 40000);
    $actor = f03b_admin('all');                              // se crea ANTES de medir: no es parte del servicio

    $antes = [
        DB::table('bylopez_va_pagos')->count(), DB::table('bylopez_va_aplicaciones_pago')->count(),
        DB::table('bylopez_va_pagos_eventos')->count(), DB::table('order_transactions')->count(), DB::table('invoices')->count(),
    ];

    DB::flushQueryLog();
    DB::enableQueryLog();

    pagb_servicio()->saldosDePedidos([$pedido->id]);
    pagb_servicio()->carteraDelCliente($cliente->id, $actor);

    $escrituras = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => preg_match('/^\s*(insert|update|delete|replace)/i', $q))->all();
    DB::disableQueryLog();

    expect($escrituras)->toBe([])
        ->and([
            DB::table('bylopez_va_pagos')->count(), DB::table('bylopez_va_aplicaciones_pago')->count(),
            DB::table('bylopez_va_pagos_eventos')->count(), DB::table('order_transactions')->count(), DB::table('invoices')->count(),
        ])->toBe($antes);
});
