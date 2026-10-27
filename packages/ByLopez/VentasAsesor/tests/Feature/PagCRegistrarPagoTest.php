<?php

use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Enums\TipoEventoPago;
use ByLopez\VentasAsesor\Exceptions\RegistroPagoInvalidoException;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarPagoService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../helpersPagB.php';

uses(TestCase::class);

/*
| PAG-C · registrar un pago desde el pedido (D-59, Doc. 05 §42.5). Pruebas deliberadamente pocas: el flujo
| central, la idempotencia, la sobreaplicacion y la autorizacion por ruta. Todo con pedidos de fabrica dentro
| de la transaccion de la prueba (nunca el pedido 41 ni pedidos reales).
*/

const PAGC_PERMISOS = ['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.registrar', 'ventas_asesor.cartera.view_all'];

beforeEach(function () {
    pag_linea_base();
    pagb_corte('2000-01-01');
});

function pagc_datos(array $extra = []): array
{
    return array_merge([
        'monto' => '300000', 'medio_cobro' => 'transferencia', 'plataforma' => 'nequi', 'fecha_pago' => now()->toDateString(), 'referencia' => 'TRX-123',
    ], $extra);
}

function pagc_registrar(object $actor, int $orderId, array $datos, string $clave = 'clave-idempotencia-0001')
{
    return app(RegistrarPagoService::class)->registrar($actor, $orderId, $datos, $clave);
}

test('registrar 300.000 sobre un pedido de 500.000 baja el saldo a 200.000, queda sin revisar y NO crea Transactions', function () {
    $pedido = pagb_pedido(pagb_cliente(), 500000);
    $factura = pagb_factura($pedido, 'pending');
    $estadoAntes = $pedido->status;
    $actor = pagb_asesor(PAGC_PERMISOS);

    $r = pagc_registrar($actor, $pedido->id, pagc_datos());

    expect($r->duplicado)->toBeFalse()
        ->and($r->estado)->toBe('sin_revisar')
        ->and($r->saldo->total)->toBe(500000.0)
        ->and($r->saldo->aplicadoTotal)->toBe(300000.0)
        ->and($r->saldo->sinRevisar)->toBe(300000.0)
        ->and($r->saldo->verificado)->toBe(0.0)
        ->and($r->saldo->saldo)->toBe(200000.0);

    $pago = Pago::query()->findOrFail($r->pagoId);

    expect($pago->estado)->toBe(EstadoPago::SIN_REVISAR)
        ->and($pago->registrado_por_admin_id)->toBe((int) $actor->id)
        ->and($pago->referencia_normalizada)->toBe('TRX123')
        ->and($pago->aplicaciones()->count())->toBe(1)
        ->and($pago->aplicaciones()->first()->order_transaction_id)->toBeNull()
        ->and($pago->eventos()->count())->toBe(1)
        ->and($pago->eventos()->first()->tipo)->toBe(TipoEventoPago::REGISTRADO)
        ->and($pago->eventos()->first()->actor_admin_id)->toBe((int) $actor->id)
        ->and($pago->eventos()->first()->datos['saldo_antes'])->toEqual(500000)
        ->and($pago->eventos()->first()->datos['saldo_despues'])->toEqual(200000);

    // Dinero reportado, no verificado: ni Transaction, ni cambio en Invoice u Order.
    expect(DB::table('order_transactions')->where('order_id', $pedido->id)->count())->toBe(0)
        ->and(DB::table('invoices')->where('id', $factura)->value('state'))->toBe('pending')
        ->and(DB::table('orders')->where('id', $pedido->id)->value('status'))->toBe($estadoAntes);
});

test('la misma clave con la misma solicitud no duplica; con otra solicitud falla cerrado', function () {
    $pedido = pagb_pedido(pagb_cliente(), 500000);
    $actor = pagb_asesor(PAGC_PERMISOS);

    $primero = pagc_registrar($actor, $pedido->id, pagc_datos());
    $segundo = pagc_registrar($actor, $pedido->id, pagc_datos());

    expect($segundo->duplicado)->toBeTrue()
        ->and($segundo->pagoId)->toBe($primero->pagoId)
        ->and($segundo->saldo->saldo)->toBe(200000.0)
        ->and(pag_nuevos('bylopez_va_pagos'))->toBe(1)
        ->and(pag_nuevos('bylopez_va_aplicaciones_pago'))->toBe(1)
        ->and(pag_nuevos('bylopez_va_pagos_eventos'))->toBe(1);

    expect(fn () => pagc_registrar($actor, $pedido->id, pagc_datos(['monto' => '100000'])))
        ->toThrow(fn (RegistroPagoInvalidoException $e) => $e->codigo === RegistroPagoInvalidoException::IDEMPOTENCIA_EN_CONFLICTO && $e->estadoHttp() === 409);

    expect(pag_nuevos('bylopez_va_pagos'))->toBe(1);
});

test('no se aplica mas que el saldo ni sin referencia requerida ni con Cartera inactiva, y no queda nada escrito', function () {
    $pedido = pagb_pedido(pagb_cliente(), 500000);
    $actor = pagb_asesor(PAGC_PERMISOS);

    pagc_registrar($actor, $pedido->id, pagc_datos());   // saldo: 200.000

    $rechaza = fn (array $extra, string $codigo, string $clave) => expect(fn () => pagc_registrar($actor, $pedido->id, pagc_datos($extra), $clave))
        ->toThrow(fn (RegistroPagoInvalidoException $e) => $e->codigo === $codigo);

    $rechaza(['monto' => '200000.01'], RegistroPagoInvalidoException::MONTO_EXCEDE_SALDO, 'clave-idempotencia-0002');
    $rechaza(['monto' => '0'], RegistroPagoInvalidoException::MONTO_INVALIDO, 'clave-idempotencia-0003');
    $rechaza(['referencia' => ''], RegistroPagoInvalidoException::REFERENCIA_REQUERIDA, 'clave-idempotencia-0004');
    $rechaza(['medio_cobro' => 'nequi'], RegistroPagoInvalidoException::MEDIO_COBRO_INVALIDO, 'clave-idempotencia-0005');

    pagb_corte(null);
    $rechaza(['monto' => '1000'], RegistroPagoInvalidoException::CARTERA_INACTIVA, 'clave-idempotencia-0006');

    // Solo el primer pago existe: los rechazos hicieron rollback completo (ni Pago huerfano ni evento).
    expect(pag_nuevos('bylopez_va_pagos'))->toBe(1)
        ->and(pag_nuevos('bylopez_va_aplicaciones_pago'))->toBe(1)
        ->and(pag_nuevos('bylopez_va_pagos_eventos'))->toBe(1);
});

test('la ruta exige cartera.registrar y el alcance de cartera; con ambos registra y el detalle muestra el bloque', function () {
    $cliente = pagb_cliente();
    $pedido = pagb_pedido($cliente, 500000);
    $url = route('admin.ventas_asesor.pedidos.pagos.store', ['pedido' => $pedido->id]);
    $cuerpo = pagc_datos(['idempotency_key' => 'clave-idempotencia-http-01']);

    // Sin la clave `registrar`: el Bouncer corta en la ruta (la ACL la mapea a su hoja).
    $this->actingAs(pagb_asesor(['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.view_all']), 'admin')
        ->postJson($url, $cuerpo)->assertUnauthorized();

    // Con `registrar` pero SIN alcance de lectura de cartera: el servicio niega sin revelar el pedido.
    $this->actingAs(pagb_asesor(['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.registrar']), 'admin')
        ->postJson($url, $cuerpo)->assertNotFound()->assertJsonPath('codigo', 'pedido_inexistente');

    // `view_own` sin atribucion del pedido a este asesor: tampoco (RA-6).
    $this->actingAs(pagb_asesor(['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.registrar', 'ventas_asesor.cartera.view_own']), 'admin')
        ->postJson($url, $cuerpo)->assertNotFound();

    expect(pag_nuevos('bylopez_va_pagos'))->toBe(0);

    $admin = pagb_asesor(PAGC_PERMISOS);

    $this->actingAs($admin, 'admin')->postJson($url, $cuerpo)
        ->assertCreated()
        ->assertJsonPath('saldo.saldo', 200000)
        ->assertJsonPath('estado', 'sin_revisar');

    // Reintento HTTP con la misma clave: 200 y el mismo pago, sin duplicar.
    $this->actingAs($admin, 'admin')->postJson($url, $cuerpo)->assertOk()->assertJsonPath('duplicado', true);

    expect(pag_nuevos('bylopez_va_pagos'))->toBe(1);

    // El detalle (con pedidos.view_all) muestra el bloque Pago / Cartera con las cifras del dominio.
    $lector = pagb_asesor(['ventas_asesor', 'ventas_asesor.pedidos', 'ventas_asesor.pedidos.view_all', ...PAGC_PERMISOS]);

    $this->actingAs($lector, 'admin')
        ->get(route('admin.ventas_asesor.pedidos.index', ['pedido' => $pedido->id]))
        ->assertOk()
        ->assertSee('data-bloque-cartera', false)
        ->assertSee('data-cartera-sin-revisar', false)
        ->assertSee('v-registrar-pago', false);
});
