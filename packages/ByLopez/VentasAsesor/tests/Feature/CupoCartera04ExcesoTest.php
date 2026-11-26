<?php

use ByLopez\VentasAsesor\Exceptions\ConfirmacionVentaInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Models\ClienteCupoCarteraExceso as Exceso;
use ByLopez\VentasAsesor\Services\Cupo\CupoCarteraClienteService;
use ByLopez\VentasAsesor\Services\Cupo\ExcesoCupoService;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Services\Venta\ConfirmarVentaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Sales\Models\Order;

require_once __DIR__.'/../helpersCupo.php';

uses(TestCase::class);

/*
| Cupo de cartera (Doc. 35 §8) — excepcion «Autorizar exceso»: UNA venta por encima del cupo (o sin cupo), autorizada por el administrador,
| de un solo uso, con vencimiento, consumida de forma atomica dentro de la transaccion del pedido y auditada. Solo lpz_saas_testing (o la
| base exclusiva de la rama). La carrera por una misma autorizacion con procesos reales esta en tests/Concurrencia/CupoConcurrenteTest.php.
*/

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();
    ['admin' => $this->admin, 'cliente' => $this->cliente] = cupo_base();
    $this->asesor = f03b_admin('custom', [
        AutorizacionVentasAsesor::PERMISO_MODULO, AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR, BorradorVentaService::PERMISO,
    ]);
    $this->excesos = app(ExcesoCupoService::class);
});

function exceso_codigo(callable $accion): ?string
{
    return cupo_error($accion);
}

// ------------------------------------------------------------------ permisos y validaciones

test('EXC-01 solo el administrador autorizado autoriza y anula: asesor, rol `all` ajeno (BODEGA) y quien solo tiene la clave NO', function () {
    $asesor = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_CUPO_VER]);
    $conClave = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_CUPO_VER, AutorizacionVentasAsesor::PERMISO_CUPO_AUTORIZAR_EXCESO]);
    $bodegaAll = f03b_admin('all');

    foreach ([$asesor, $conClave, $bodegaAll] as $actor) {
        expect(exceso_codigo(fn () => $this->excesos->autorizar($actor, $this->cliente->id, 50000, 'Intento')))->toBe('cupo_sin_permiso');
    }

    $fila = $this->excesos->autorizar($this->admin, $this->cliente->id, 50000, 'Venta de contado');

    foreach ([$asesor, $conClave, $bodegaAll] as $actor) {
        expect(exceso_codigo(fn () => $this->excesos->anular($actor, $fila['id'], 'Intento')))->toBe('cupo_sin_permiso');
    }

    expect(Exceso::query()->where('customer_id', $this->cliente->id)->count())->toBe(1)
        ->and(Exceso::query()->find($fila['id'])->estado)->toBe('vigente');
});

test('EXC-02 validaciones: monto <= 0 o mal formado, motivo obligatorio, cliente inexistente, cliente bloqueado y una sola vigente', function () {
    $a = fn (string|int|float $monto, string $motivo = 'Motivo', ?int $cliente = null) => exceso_codigo(
        fn () => $this->excesos->autorizar($this->admin, $cliente ?? $this->cliente->id, $monto, $motivo));

    expect($a(0))->toBe('cupo_monto_invalido')
        ->and($a('-5'))->toBe('cupo_monto_invalido')
        ->and($a('1e6'))->toBe('cupo_monto_invalido')
        ->and($a('10.555'))->toBe('cupo_monto_invalido')
        ->and($a('abc'))->toBe('cupo_monto_invalido')
        ->and($a(1000, '   '))->toBe('cupo_motivo_requerido')
        ->and($a(1000, str_repeat('x', 501)))->toBe('cupo_motivo_requerido')
        ->and($a(1000, 'Motivo', 999999999))->toBe('cupo_cliente_inexistente')
        ->and(Exceso::query()->count())->toBe(0);

    expect($a(1000))->toBeNull();
    expect($a(2000))->toBe('cupo_exceso_vigente_existente')->and(Exceso::query()->where('customer_id', $this->cliente->id)->count())->toBe(1);

    $bloqueado = pagb_cliente();
    cupo_fijar($this->admin, $bloqueado, 100000, 'bloqueado', 'En mora');
    expect($a(1000, 'Motivo', (int) $bloqueado->id))->toBe('cupo_cliente_bloqueado');
});

// ------------------------------------------------------------------ consumo en la venta REAL

test('EXC-03 un cliente SIN cupo + venta de contado: rechazada; con la autorizacion pasa UNA vez y queda todo auditado (cliente, monto, motivo, admin, pedido, exposicion, exceso)', function () {
    // Estado objetivo de produccion para la transferencia: factura PENDIENTE (con `paid` Cartera marca «factura pagada sin evidencia»
    // y pide revision antes de aplicar pagos; ese estado de prueba es el D-48 sin desplegar y no es lo que se quiere probar aqui).
    DB::table('core_config')->updateOrInsert(
        ['code' => 'sales.payment_methods.moneytransfer.invoice_status', 'channel_code' => core()->getDefaultChannel()->code, 'locale_code' => null],
        ['value' => 'pending'],
    );
    \Illuminate\Support\Facades\Cache::flush();

    [$b1, $total1, $p1] = cupo_venta($this, $this->cliente, 80000);
    expect(cupo_confirmar($this, $b1, $total1)->codigo)->toBe(Rechazo::CUPO_SIN_ASIGNAR);
    cupo_sin_pedido_ni_reserva($b1, $p1);

    $auth = $this->excesos->autorizar($this->admin, $this->cliente->id, $total1, 'Venta de contado: el pago se registra al confirmar');

    // Vista previa (informativa): la autorizacion cubriria la venta, pero la decision sigue siendo del cerco.
    $servicio = app(CupoCarteraClienteService::class);
    $cupo = $servicio->delCliente((int) $this->cliente->id);
    $previa = $servicio->evaluar($cupo, $total1);
    expect($cupo->excesoVigente['id'])->toBe($auth['id'])->and($cupo->excesoVigente['exceso_autorizado'])->toBe($total1)
        ->and($previa->permitido)->toBeFalse()->and($previa->cubiertaPorExceso)->toBeTrue();

    expect(cupo_confirmar($this, $b1, $total1))->toBeNull();

    $pedido = Order::query()->where('cart_id', $b1->cart_id)->sole();
    $fila = Exceso::query()->findOrFail($auth['id']);

    expect($fila->estado)->toBe('consumida')->and($fila->order_id)->toBe((int) $pedido->id)
        ->and((float) $fila->exceso_autorizado)->toBe($total1)->and((float) $fila->exceso_consumido)->toBe($total1)
        ->and((float) $fila->nueva_obligacion)->toBe($total1)->and((float) $fila->exposicion_antes)->toBe(0.0)->and((float) $fila->cupo_al_consumir)->toBe(0.0)
        ->and($fila->motivo)->toBe('Venta de contado: el pago se registra al confirmar')
        ->and($fila->autorizado_por)->toBe((int) $this->admin->id)->and($fila->autorizado_nombre)->toBe($this->admin->name)
        ->and($fila->consumida_en)->not->toBeNull()->and($fila->vence_en)->not->toBeNull();

    // De un solo uso: la siguiente venta del mismo cliente vuelve a rechazarse.
    [$b2, $total2, $p2] = cupo_venta($this, $this->cliente, 30000);
    expect(cupo_confirmar($this, $b2, $total2)->codigo)->toBe(Rechazo::CUPO_SIN_ASIGNAR);
    cupo_sin_pedido_ni_reserva($b2, $p2);

    // El pedido creado con excepcion SI consume exposicion (hasta que se pague): no es un atajo para no deber.
    expect(cupo_de($this->cliente)->exposicion)->toBe($total1)->and(cupo_de($this->cliente)->excesoVigente)->toBeNull();

    // Pagarlo (flujo normal de Cartera) libera la exposicion.
    cupo_pagar($this->admin, (int) $pedido->id, (string) $total1);
    expect(cupo_de($this->cliente)->exposicion)->toBe(0.0);
});

test('EXC-04 el exceso autorizado debe ALCANZAR: una autorizacion menor no se consume y la venta se rechaza', function () {
    cupo_fijar($this->admin, $this->cliente, 100000);
    cupo_pedido($this->cliente, 90000);
    [$b, $total, $producto] = cupo_venta($this, $this->cliente, 50000);   // exceso real = 90.000 + 50.000 − 100.000 = 40.000

    $corta = $this->excesos->autorizar($this->admin, $this->cliente->id, '39999.99', 'Casi alcanza');
    $r = cupo_confirmar($this, $b, $total);

    expect($total)->toBe(50000.0)->and($r->codigo)->toBe(Rechazo::CUPO_EXCEDIDO)->and($r->detalles['exceso'])->toBe(40000.0)
        ->and(Exceso::query()->find($corta['id'])->estado)->toBe('vigente')->and(Exceso::query()->find($corta['id'])->order_id)->toBeNull();
    cupo_sin_pedido_ni_reserva($b, $producto);

    // Se anula (motivo) y se da una que alcance exactamente: pasa y deja al cliente EXCEDIDO por el exceso autorizado.
    $this->excesos->anular($this->admin, $corta['id'], 'Se corrige el monto');
    $justa = $this->excesos->autorizar($this->admin, $this->cliente->id, '40000', 'Monto correcto');

    expect(cupo_confirmar($this, $b, $total))->toBeNull();

    $c = cupo_de($this->cliente);
    expect(Exceso::query()->find($justa['id'])->estado)->toBe('consumida')
        ->and($c->exposicion)->toBe(140000.0)->and($c->estado)->toBe('excedido')->and($c->exceso)->toBe(40000.0)->and($c->disponible)->toBe(0.0);

    // Y ahora nadie mas pasa: sigue excedido y la autorizacion ya se uso.
    [$b2, $total2, $p2] = cupo_venta($this, $this->cliente, 1000);
    expect(cupo_confirmar($this, $b2, $total2)->codigo)->toBe(Rechazo::CUPO_EXCEDIDO);
    cupo_sin_pedido_ni_reserva($b2, $p2);
});

test('EXC-05 NUNCA anula un bloqueo: con el cliente bloqueado la venta se rechaza (cupo_bloqueado) y la autorizacion NO se consume', function () {
    cupo_fijar($this->admin, $this->cliente, 500000);
    $auth = $this->excesos->autorizar($this->admin, $this->cliente->id, 500000, 'Autorizada antes del bloqueo');
    cupo_fijar($this->admin, $this->cliente, 500000, 'bloqueado', 'Cheque devuelto');
    [$b, $total, $producto] = cupo_venta($this, $this->cliente, 40000);

    expect(cupo_confirmar($this, $b, $total)->codigo)->toBe(Rechazo::CUPO_BLOQUEADO)
        ->and(Exceso::query()->find($auth['id'])->estado)->toBe('vigente');
    cupo_sin_pedido_ni_reserva($b, $producto);
});

test('EXC-05b ni cubre «Cartera inactiva»: si el cupo no se puede verificar, la venta se rechaza y la autorizacion no se consume', function () {
    $auth = $this->excesos->autorizar($this->admin, $this->cliente->id, 500000, 'Autorizada');
    pagb_corte(null);
    [$b, $total, $producto] = cupo_venta($this, $this->cliente, 40000);

    expect(cupo_confirmar($this, $b, $total)->codigo)->toBe(Rechazo::CUPO_NO_VERIFICABLE)
        ->and(Exceso::query()->find($auth['id'])->estado)->toBe('vigente');
    cupo_sin_pedido_ni_reserva($b, $producto);
});

test('EXC-06 vencimiento: una autorizacion vencida no se consume; al autorizar otra, la vencida queda marcada y la nueva es la unica vigente', function () {
    $vieja = $this->excesos->autorizar($this->admin, $this->cliente->id, 500000, 'Se vence');
    DB::table('bylopez_va_clientes_cupo_cartera_excesos')->where('id', $vieja['id'])->update(['vence_en' => now()->subMinute()]);
    [$b, $total, $producto] = cupo_venta($this, $this->cliente, 40000);

    expect(cupo_de($this->cliente)->excesoVigente)->toBeNull()
        ->and(cupo_confirmar($this, $b, $total)->codigo)->toBe(Rechazo::CUPO_SIN_ASIGNAR)
        ->and(Exceso::query()->find($vieja['id'])->estado)->toBe('vigente'); // sigue «vigente» en base hasta que alguien la vence; la lectura la trata como vencida
    cupo_sin_pedido_ni_reserva($b, $producto);

    $nueva = $this->excesos->autorizar($this->admin, $this->cliente->id, 500000, 'Renovada');

    expect(Exceso::query()->find($vieja['id'])->estado)->toBe('vencida')->and(Exceso::query()->find($nueva['id'])->estado)->toBe('vigente')
        ->and(Exceso::query()->where('customer_id', $this->cliente->id)->where('estado', 'vigente')->count())->toBe(1)
        ->and(cupo_confirmar($this, $b, $total))->toBeNull();
});

test('EXC-07 anular: motivo obligatorio, solo la vigente; la consumida es historia y no se anula; una anulada no se usa', function () {
    $a = $this->excesos->autorizar($this->admin, $this->cliente->id, 500000, 'Se anula');

    expect(exceso_codigo(fn () => $this->excesos->anular($this->admin, $a['id'], '  ')))->toBe('cupo_motivo_requerido')
        ->and(exceso_codigo(fn () => $this->excesos->anular($this->admin, 999999999, 'x')))->toBe('cupo_exceso_inexistente');

    $r = $this->excesos->anular($this->admin, $a['id'], 'El cliente pago por adelantado');
    $fila = Exceso::query()->find($a['id']);

    expect($r['estado'])->toBe('anulada')->and($fila->anulada_motivo)->toBe('El cliente pago por adelantado')->and($fila->anulada_por)->toBe((int) $this->admin->id)
        ->and($fila->anulada_en)->not->toBeNull()
        ->and(exceso_codigo(fn () => $this->excesos->anular($this->admin, $a['id'], 'Otra vez')))->toBe('cupo_exceso_no_anulable');

    [$b, $total, $producto] = cupo_venta($this, $this->cliente, 40000);
    expect(cupo_confirmar($this, $b, $total)->codigo)->toBe(Rechazo::CUPO_SIN_ASIGNAR);
    cupo_sin_pedido_ni_reserva($b, $producto);

    // Una consumida no se anula.
    $c = $this->excesos->autorizar($this->admin, $this->cliente->id, 500000, 'Esta si se usa');
    expect(cupo_confirmar($this, $b, $total))->toBeNull()
        ->and(exceso_codigo(fn () => $this->excesos->anular($this->admin, $c['id'], 'Tarde')))->toBe('cupo_exceso_no_anulable')
        ->and(Exceso::query()->find($c['id'])->estado)->toBe('consumida');
});

test('EXC-08 ATOMICIDAD: si el pedido falla DESPUES del cerco, la autorizacion NO queda consumida (consumo y pedido se deshacen juntos)', function () {
    $auth = $this->excesos->autorizar($this->admin, $this->cliente->id, 500000, 'Debe sobrevivir al fallo');
    [$b, $total] = cupo_venta($this, $this->cliente, 40000);

    // Falla DENTRO de la transaccion del pedido, despues del cerco (que ya consumio la autorizacion).
    Event::listen('checkout.order.orderitem.save.before', function () {
        throw new RuntimeException('Fallo simulado tras consumir el exceso (EXC-08).');
    });

    $falla = null;

    try {
        f03f_sinCommitPrematuro(fn () => app(ConfirmarVentaService::class)->confirmar($b->id, $this->asesor, $total));
    } catch (RuntimeException $e) {
        $falla = $e;
    }

    $fila = Exceso::query()->find($auth['id']);

    expect($falla)->not->toBeNull()
        ->and($fila->estado)->toBe('vigente')->and($fila->order_id)->toBeNull()->and($fila->consumida_en)->toBeNull()->and($fila->exceso_consumido)->toBeNull()
        ->and(Order::query()->where('cart_id', $b->cart_id)->count())->toBe(0);
});

test('con el bloqueo apagado la autorizacion se puede dar pero no se consume (el cerco no consulta nada)', function () {
    config(['ventasasesor.cupo.activo' => false]);
    $auth = $this->excesos->autorizar($this->admin, $this->cliente->id, 500000, 'Sin bloqueo activo');
    [$b, $total] = cupo_venta($this, $this->cliente, 40000);

    expect(cupo_confirmar($this, $b, $total))->toBeNull()
        ->and(Exceso::query()->find($auth['id'])->estado)->toBe('vigente');
});

// ------------------------------------------------------------------ HTTP

test('HTTP: autorizar y anular — administrador 200; asesor 401/403; resumen e historial muestran la autorizacion y el pedido que la uso', function () {
    $urlAutorizar = route('admin.ventas_asesor.cartera.cupo.exceso', ['cliente' => $this->cliente->id]);
    $asesorVer = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_CUPO_VER]);
    $asesorConClave = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_CUPO_VER, AutorizacionVentasAsesor::PERMISO_CUPO_AUTORIZAR_EXCESO]);
    $bodegaAll = f03b_admin('all');
    $cuerpo = ['exceso' => '120000', 'motivo' => 'Venta de contado'];

    $this->actingAs($asesorVer, 'admin')->postJson($urlAutorizar, $cuerpo)->assertStatus(401);
    $this->actingAs($asesorConClave, 'admin')->postJson($urlAutorizar, $cuerpo)->assertStatus(403)->assertJsonPath('codigo', 'cupo_sin_permiso');
    $this->actingAs($bodegaAll, 'admin')->postJson($urlAutorizar, $cuerpo)->assertStatus(403)->assertJsonPath('codigo', 'cupo_sin_permiso');
    $this->actingAs($this->admin, 'admin')->postJson($urlAutorizar, ['exceso' => '120000'])->assertStatus(422);   // motivo obligatorio

    $r = $this->actingAs($this->admin, 'admin')->postJson($urlAutorizar, $cuerpo)->assertOk()
        ->assertJsonPath('exceso.estado', 'vigente')->assertJsonPath('exceso.exceso_autorizado', 120000)->assertJsonPath('exceso.autorizado_por', $this->admin->name);
    $id = $r->json('exceso.id');

    $this->actingAs($this->admin, 'admin')->postJson($urlAutorizar, $cuerpo)->assertStatus(422)->assertJsonPath('codigo', 'cupo_exceso_vigente_existente');

    // El resumen la muestra: completa al administrador; al asesor solo monto y vencimiento (sin motivo ni quien la dio).
    $resumen = route('admin.ventas_asesor.cartera.cupo.cliente', ['cliente' => $this->cliente->id]);
    $this->actingAs($this->admin, 'admin')->getJson($resumen)->assertOk()
        ->assertJsonPath('cupo.exceso_vigente.exceso_autorizado', 120000)->assertJsonPath('cupo.exceso_vigente.motivo', 'Venta de contado')->assertJsonPath('puede_exceso', true);
    $this->actingAs($asesorVer, 'admin')->getJson($resumen)->assertOk()
        ->assertJsonPath('cupo.exceso_vigente.exceso_autorizado', 120000)->assertJsonPath('cupo.exceso_vigente.motivo', null)->assertJsonPath('puede_exceso', false);

    // Anular: solo el administrador.
    $urlAnular = route('admin.ventas_asesor.cartera.cupo.exceso_anular', ['excepcion' => $id]);
    $this->actingAs($asesorConClave, 'admin')->postJson($urlAnular, ['motivo' => 'x'])->assertStatus(403);
    $this->actingAs($this->admin, 'admin')->postJson($urlAnular, [])->assertStatus(422);
    $this->actingAs($this->admin, 'admin')->postJson($urlAnular, ['motivo' => 'Ya pago'])->assertOk()->assertJsonPath('exceso.estado', 'anulada');
    $this->actingAs($this->admin, 'admin')->postJson($urlAnular, ['motivo' => 'Otra vez'])->assertStatus(422)->assertJsonPath('codigo', 'cupo_exceso_no_anulable');

    // Historial con las autorizaciones (y, tras usar una, el pedido que la consumio).
    $this->excesos->autorizar($this->admin, $this->cliente->id, 500000, 'La que se usa');
    [$b, $total] = cupo_venta($this, $this->cliente, 40000);
    expect(cupo_confirmar($this, $b, $total))->toBeNull();
    $pedido = Order::query()->where('cart_id', $b->cart_id)->sole();

    $h = $this->actingAs($this->admin, 'admin')->getJson(route('admin.ventas_asesor.cartera.cupo.historial', ['cliente' => $this->cliente->id]))->assertOk()->json('excesos');
    expect($h)->toHaveCount(2)->and($h[0])->toMatchArray(['estado' => 'consumida', 'order_id' => (int) $pedido->id, 'increment_id' => (string) $pedido->increment_id, 'exceso_consumido' => 40000, 'exposicion_antes' => 0])
        ->and($h[1]['estado'])->toBe('anulada');
});
