<?php

use ByLopez\VentasAsesor\DataObjects\CupoCartera;
use ByLopez\VentasAsesor\Enums\EstadoBorradorVenta as Estado;
use ByLopez\VentasAsesor\Exceptions\ConfirmacionVentaInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Exceptions\PedidoRechazadoPorCercoException as RechazoDelCerco;
use ByLopez\VentasAsesor\Listeners\CercoCreacionPedido;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Models\ClienteCupoCarteraHistorial;
use ByLopez\VentasAsesor\Services\Cupo\CupoCarteraClienteService;
use ByLopez\VentasAsesor\Services\Cupo\GestionCupoCarteraService;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Services\Venta\ConfirmarVentaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Product\Models\ProductOrderedInventory;
use Webkul\Sales\Models\Order;

require_once __DIR__.'/../helpersCupo.php';

uses(TestCase::class);

/*
| Cupo de cartera (Doc. 35) — BLOQUEO duro en la confirmacion (cerco de creacion de pedidos), gestion con historial, permisos y rutas.
| Solo lpz_saas_testing (o la base exclusiva de la rama). La carrera con procesos reales esta en tests/Concurrencia/CupoConcurrenteTest.php.
|
| Los rechazos DENTRO de OrderRepository se ejecutan con f03f_sinCommitPrematuro(): el core hace DB::commit() en el finally de cada
| intento y, sin esa defensa, confirmaria la transaccion de la prueba (Doc. 05 §37.1).
*/

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();
    ['admin' => $this->admin, 'cliente' => $this->cliente] = cupo_base();
    $this->asesor = f03b_admin('custom', [
        AutorizacionVentasAsesor::PERMISO_MODULO, AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR, BorradorVentaService::PERMISO,
    ]);
});

// ------------------------------------------------------------------ CUPO-01: sin cupo no hay credito

test('CUPO-01 un cliente SIN cupo no puede confirmar una venta (cupo_sin_asignar): sin pedido, sin reserva y la venta sigue activa', function () {
    [$borrador, $total, $producto] = cupo_venta($this, $this->cliente, 80000);

    $r = cupo_confirmar($this, $borrador, $total);

    expect($r)->toBeInstanceOf(Rechazo::class)->and($r->codigo)->toBe(Rechazo::CUPO_SIN_ASIGNAR)->and($r->detalles['fase'])->toBe('cerco')
        ->and($r->detalles['nueva_obligacion'])->toBe($total)->and($r->detalles['autorizado'])->toBe(0.0);
    cupo_sin_pedido_ni_reserva($borrador, $producto);
});

test('CUPO-03/04 en la confirmacion REAL: lo que cabe se confirma y consume; lo que excede se rechaza con las cifras y la venta queda intacta', function () {
    cupo_fijar($this->admin, $this->cliente, 2000000);
    cupo_pedido($this->cliente, 1300000);

    [$cabe, $totalCabe] = cupo_venta($this, $this->cliente, 500000);
    expect($totalCabe)->toBe(500000.0)->and(cupo_confirmar($this, $cabe, $totalCabe))->toBeNull();

    // Confirmada: el pedido nuevo YA consume (exposicion 1.300.000 + 500.000) y el disponible baja a 200.000.
    $c = cupo_de($this->cliente);
    expect($c->exposicion)->toBe(1800000.0)->and($c->disponible)->toBe(200000.0)->and($c->pedidosQueConsumen)->toBe(2);

    [$excede, $totalExcede, $producto] = cupo_venta($this, $this->cliente, 800000);
    $r = cupo_confirmar($this, $excede, $totalExcede);

    expect($r)->toBeInstanceOf(Rechazo::class)->and($r->codigo)->toBe(Rechazo::CUPO_EXCEDIDO)
        ->and($r->detalles)->toMatchArray(['autorizado' => 2000000.0, 'exposicion' => 1800000.0, 'disponible' => 200000.0, 'nueva_obligacion' => 800000.0, 'exceso' => 600000.0, 'fase' => 'cerco']);
    cupo_sin_pedido_ni_reserva($excede, $producto);
    expect(cupo_de($this->cliente)->exposicion)->toBe(1800000.0);
});

test('un monto EXACTO al disponible se confirma y deja el cupo agotado', function () {
    cupo_fijar($this->admin, $this->cliente, 300000);
    [$b, $total] = cupo_venta($this, $this->cliente, 300000);

    expect(cupo_confirmar($this, $b, $total))->toBeNull()
        ->and(cupo_de($this->cliente)->estado)->toBe(CupoCartera::AGOTADO)->and(cupo_de($this->cliente)->disponible)->toBe(0.0);
});

test('CUPO-12 un cliente BLOQUEADO no recibe credito nuevo aunque tenga disponible de sobra', function () {
    cupo_fijar($this->admin, $this->cliente, 5000000, 'bloqueado', 'Cheque devuelto');
    [$b, $total, $producto] = cupo_venta($this, $this->cliente, 40000);

    $r = cupo_confirmar($this, $b, $total);

    expect(cupo_de($this->cliente)->disponible)->toBe(5000000.0)
        ->and($r)->toBeInstanceOf(Rechazo::class)->and($r->codigo)->toBe(Rechazo::CUPO_BLOQUEADO);
    cupo_sin_pedido_ni_reserva($b, $producto);

    // Desbloquear (nuevo cambio auditado) permite vender de nuevo.
    cupo_fijar($this->admin, $this->cliente, 5000000, 'activo', 'Se desbloquea tras el pago');
    expect(cupo_confirmar($this, $b, $total))->toBeNull();
});

test('con el bloqueo APAGADO (por defecto) el cupo no se consulta y la venta pasa como siempre', function () {
    config(['ventasasesor.cupo.activo' => false]);
    [$b, $total] = cupo_venta($this, $this->cliente, 40000);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $r = cupo_confirmar($this, $b, $total);
    $consultas = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'bylopez_va_clientes_cupo_cartera'))->count();
    DB::disableQueryLog();

    expect($r)->toBeNull()->and($consultas)->toBe(0);
});

test('Cartera inactiva con el bloqueo activo: la venta se rechaza (falla cerrado, cupo_no_verificable)', function () {
    cupo_fijar($this->admin, $this->cliente, 5000000);
    pagb_corte(null);
    [$b, $total, $producto] = cupo_venta($this, $this->cliente, 40000);

    $r = cupo_confirmar($this, $b, $total);

    expect($r)->toBeInstanceOf(Rechazo::class)->and($r->codigo)->toBe(Rechazo::CUPO_NO_VERIFICABLE);
    cupo_sin_pedido_ni_reserva($b, $producto);
});

test('confirmar de nuevo una venta YA confirmada devuelve el mismo pedido (idempotente) aunque el cupo se bloquee despues', function () {
    cupo_fijar($this->admin, $this->cliente, 5000000);
    [$b, $total] = cupo_venta($this, $this->cliente, 40000);

    $primero = f03f_sinCommitPrematuro(fn () => app(ConfirmarVentaService::class)->confirmar($b->id, $this->asesor, $total));
    cupo_fijar($this->admin, $this->cliente, 5000000, 'bloqueado', 'Se bloquea despues');
    $segundo = f03f_sinCommitPrematuro(fn () => app(ConfirmarVentaService::class)->confirmar($b->id, $this->asesor, $total));

    expect($primero->yaExistia)->toBeFalse()->and($segundo->yaExistia)->toBeTrue()->and($segundo->orderId)->toBe($primero->orderId)
        ->and(Order::query()->where('cart_id', $b->cart_id)->count())->toBe(1);
});

test('el cerco protege TODA creacion de pedidos (tienda, panel): un pedido nativo con cliente y sin cupo se rechaza igual', function () {
    $cerco = app(CercoCreacionPedido::class);
    $pedido = ['customer_id' => $this->cliente->id, 'base_grand_total' => 90000, 'cart_id' => 0, 'channel_id' => 1, 'items' => []];

    expect(fn () => $cerco->handle($pedido))->toThrow(RechazoDelCerco::class);

    try {
        $cerco->handle($pedido);
    } catch (RechazoDelCerco $e) {
        expect($e->codigo)->toBe(RechazoDelCerco::CUPO_SIN_ASIGNAR);
    }

    // Invitado (sin cliente): no hay cupo que limitar. Pedido de total 0 (muestra, garantia): nunca se rechaza.
    $cerco->handle(['customer_id' => null, 'base_grand_total' => 90000, 'cart_id' => 0, 'items' => []]);
    $cerco->handle(['customer_id' => $this->cliente->id, 'base_grand_total' => 0, 'cart_id' => 0, 'items' => []]);

    expect(true)->toBeTrue();
});

test('el cerco sigue siendo el PRIMER listener de checkout.order.save.before y el cupo toma el bloqueo del cliente tras el de products', function () {
    $oyentes = collect(app('events')->getListeners(CercoCreacionPedido::EVENTO));

    expect($oyentes->isNotEmpty())->toBeTrue();

    cupo_fijar($this->admin, $this->cliente, 1000000);
    $simple = f03f_simple(1000.0);
    f03b_stock($simple, 5);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $pedido = ['customer_id' => $this->cliente->id, 'base_grand_total' => 1000, 'cart_id' => 0, 'channel_id' => core()->getDefaultChannel()->id, 'items' => [['product_id' => $simple->id, 'type' => 'simple', 'qty_ordered' => 1]]];
    app(CercoCreacionPedido::class)->handle($pedido);
    $sql = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower($q))->values();
    DB::disableQueryLog();

    $iProducts = $sql->search(fn ($q) => str_contains($q, 'from `products`') && str_contains($q, 'for update'));
    $iCliente = $sql->search(fn ($q) => str_contains($q, 'from `customers`') && str_contains($q, 'for update'));
    $iPrimeraNoBloqueante = $sql->search(fn ($q) => ! str_contains($q, 'for update') && ! str_contains($q, 'lock in share mode'));

    expect($iProducts)->not->toBeFalse()->and($iCliente)->not->toBeFalse()
        ->and($iProducts)->toBeLessThan($iCliente)          // orden global: products antes que el cliente
        ->and($iCliente)->toBeLessThan($iPrimeraNoBloqueante); // la instantanea nace con el bloqueo ya tomado
});

// ------------------------------------------------------------------ CUPO-11: bajar el cupo por debajo de la deuda

test('CUPO-11 bajar el cupo por debajo de la deuda deja al cliente EXCEDIDO sin tocar ningun pedido y bloquea las ventas nuevas', function () {
    cupo_fijar($this->admin, $this->cliente, 2000000);
    $p = cupo_pedido($this->cliente, 1500000);
    $antesPedido = (array) DB::table('orders')->where('id', $p->id)->first();
    $antesSaldo = d10_saldo($p->id)->toArray();

    $r = cupo_fijar($this->admin, $this->cliente, 1000000, 'activo', 'Se reduce el riesgo');
    $c = cupo_de($this->cliente);

    expect($r['excede_exposicion'])->toBeTrue()->and($r['accion'])->toBe('cambio_cupo')
        ->and($c->estado)->toBe(CupoCartera::EXCEDIDO)->and($c->exceso)->toBe(500000.0)->and($c->disponible)->toBe(0.0)->and($c->exposicion)->toBe(1500000.0)
        ->and((array) DB::table('orders')->where('id', $p->id)->first())->toBe($antesPedido)
        ->and(d10_saldo($p->id)->toArray())->toBe($antesSaldo);

    [$b, $total, $producto] = cupo_venta($this, $this->cliente, 10000);
    expect(cupo_confirmar($this, $b, $total)->codigo)->toBe(Rechazo::CUPO_EXCEDIDO);
    cupo_sin_pedido_ni_reserva($b, $producto);
});

// ------------------------------------------------------------------ CUPO-15: administrar + historial

test('CUPO-15 el administrador asigna, cambia el cupo, bloquea y cambia ambos: cada paso deja su fila de historial completa', function () {
    $s = app(GestionCupoCarteraService::class);
    cupo_pedido($this->cliente, 120000);

    $a = $s->establecer($this->admin, $this->cliente->id, '1000000', 'activo', 'Cliente nuevo con referencias');
    $b = $s->establecer($this->admin, $this->cliente->id, '1500000.50', 'activo', 'Sube por buen historial');
    $c = $s->establecer($this->admin, $this->cliente->id, '1500000.50', 'bloqueado', 'Cheque devuelto');
    $d = $s->establecer($this->admin, $this->cliente->id, 800000, 'activo', 'Se reactiva con menos cupo');

    expect([$a['accion'], $b['accion'], $c['accion'], $d['accion']])->toBe(['asignacion', 'cambio_cupo', 'cambio_estado', 'cambio_cupo_estado']);

    $h = $s->historial($this->admin, $this->cliente->id);

    expect($h)->toHaveCount(4)
        ->and(array_column($h, 'accion'))->toBe(['cambio_cupo_estado', 'cambio_estado', 'cambio_cupo', 'asignacion']) // del mas reciente al mas antiguo
        ->and($h[3])->toMatchArray(['cupo_anterior' => null, 'cupo_nuevo' => 1000000.0, 'estado_anterior' => null, 'estado_nuevo' => 'activo', 'motivo' => 'Cliente nuevo con referencias', 'exposicion_al_cambiar' => 120000.0])
        ->and($h[2])->toMatchArray(['cupo_anterior' => 1000000.0, 'cupo_nuevo' => 1500000.5])
        ->and($h[1])->toMatchArray(['cupo_anterior' => 1500000.5, 'cupo_nuevo' => 1500000.5, 'estado_anterior' => 'activo', 'estado_nuevo' => 'bloqueado'])
        ->and($h[0])->toMatchArray(['cupo_anterior' => 1500000.5, 'cupo_nuevo' => 800000.0, 'estado_anterior' => 'bloqueado', 'estado_nuevo' => 'activo'])
        ->and(collect($h)->every(fn ($f) => $f['actor_admin_id'] === (int) $this->admin->id && $f['actor'] === $this->admin->name && $f['fecha'] !== null))->toBeTrue();

    $politica = DB::table('bylopez_va_clientes_cupo_cartera')->where('customer_id', $this->cliente->id)->first();
    expect((float) $politica->cupo_autorizado)->toBe(800000.0)->and($politica->estado)->toBe('activo')->and((int) $politica->creado_por)->toBe((int) $this->admin->id)
        ->and($politica->vigencia_desde)->not->toBeNull()->and($politica->motivo)->toBe('Se reactiva con menos cupo')
        ->and(DB::table('bylopez_va_clientes_cupo_cartera')->where('customer_id', $this->cliente->id)->count())->toBe(1);
});

test('el historial es de SOLO ADICION: ni se modifica ni se borra; y no hay columna de saldo utilizado', function () {
    cupo_fijar($this->admin, $this->cliente, 500000);
    $fila = ClienteCupoCarteraHistorial::query()->where('customer_id', $this->cliente->id)->firstOrFail();

    expect(fn () => $fila->update(['motivo' => 'otro']))->toThrow(\ByLopez\VentasAsesor\Exceptions\OperacionCupoInvalidaException::class)
        ->and(fn () => $fila->delete())->toThrow(\ByLopez\VentasAsesor\Exceptions\OperacionCupoInvalidaException::class)
        ->and(ClienteCupoCarteraHistorial::query()->where('customer_id', $this->cliente->id)->count())->toBe(1);

    $columnas = \Illuminate\Support\Facades\Schema::getColumnListing('bylopez_va_clientes_cupo_cartera');
    expect(collect($columnas)->contains(fn ($c) => str_contains($c, 'utiliz') || str_contains($c, 'usado') || str_contains($c, 'exposicion') || str_contains($c, 'saldo')))->toBeFalse();
});

test('validaciones: monto negativo, con signo, notacion cientifica, +2 decimales o texto; motivo obligatorio; estado y cliente validos; sin cambios', function () {
    $s = app(GestionCupoCarteraService::class);
    $e = fn (string|int|float $monto, string $estado = 'activo', string $motivo = 'Motivo', ?int $cliente = null) => cupo_error(
        fn () => $s->establecer($this->admin, $cliente ?? $this->cliente->id, $monto, $estado, $motivo));

    expect($e('-5'))->toBe('cupo_monto_invalido')
        ->and($e('+500000'))->toBe('cupo_monto_invalido')
        ->and($e('1e6'))->toBe('cupo_monto_invalido')
        ->and($e('12.345'))->toBe('cupo_monto_invalido')
        ->and($e('1.000.000'))->toBe('cupo_monto_invalido')
        ->and($e('abc'))->toBe('cupo_monto_invalido')
        ->and($e(''))->toBe('cupo_monto_invalido')
        ->and($e(-1))->toBe('cupo_monto_invalido')
        ->and($e('9999999999999'))->toBe('cupo_monto_invalido')
        ->and($e('500000', 'activo', '   '))->toBe('cupo_motivo_requerido')
        ->and($e('500000', 'activo', str_repeat('x', 501)))->toBe('cupo_motivo_requerido')
        ->and($e('500000', 'suspendido'))->toBe('cupo_estado_invalido')
        ->and($e('500000', 'activo', 'Motivo', 999999999))->toBe('cupo_cliente_inexistente')
        ->and($e('0'))->toBe('cupo_sin_cambios')           // sin politica equivale a «cupo 0 y activo»
        ->and(DB::table('bylopez_va_clientes_cupo_cartera_historial')->where('customer_id', $this->cliente->id)->count())->toBe(0);

    cupo_fijar($this->admin, $this->cliente, 500000);
    expect($e('500000'))->toBe('cupo_sin_cambios')
        ->and($e('500000.00'))->toBe('cupo_sin_cambios')
        ->and(DB::table('bylopez_va_clientes_cupo_cartera_historial')->where('customer_id', $this->cliente->id)->count())->toBe(1);
});

test('control optimista: si otra persona cambio el cupo mientras se editaba, se rechaza y no se pisa su cambio', function () {
    $s = app(GestionCupoCarteraService::class);
    cupo_fijar($this->admin, $this->cliente, 500000);
    // Otra persona lo sube a 900.000 mientras esta pantalla seguia mostrando 500.000.
    cupo_fijar($this->admin, $this->cliente, 900000, 'activo', 'Otro administrador lo sube');

    $codigo = cupo_error(fn () => $s->establecer($this->admin, $this->cliente->id, '700000', 'activo', 'Mi cambio', '500000', 'activo'));

    expect($codigo)->toBe('cupo_cambio_concurrente')
        ->and((float) DB::table('bylopez_va_clientes_cupo_cartera')->where('customer_id', $this->cliente->id)->value('cupo_autorizado'))->toBe(900000.0)
        ->and(DB::table('bylopez_va_clientes_cupo_cartera_historial')->where('customer_id', $this->cliente->id)->count())->toBe(2);

    // Con los valores vigentes si se aplica.
    expect(cupo_error(fn () => $s->establecer($this->admin, $this->cliente->id, '700000', 'activo', 'Mi cambio', '900000', 'activo')))->toBeNull();
});

test('cupo y estado se pueden quitar sin borrar historia: cupo 0 / bloqueado; la fila de politica y el historial permanecen', function () {
    cupo_fijar($this->admin, $this->cliente, 500000);
    cupo_fijar($this->admin, $this->cliente, 0, 'activo', 'Se retira el cupo');
    cupo_fijar($this->admin, $this->cliente, 0, 'bloqueado', 'Ademas se bloquea');

    expect(cupo_de($this->cliente)->estado)->toBe(CupoCartera::BLOQUEADO)->and(cupo_de($this->cliente)->autorizado)->toBe(0.0)
        ->and(DB::table('bylopez_va_clientes_cupo_cartera')->where('customer_id', $this->cliente->id)->count())->toBe(1)
        ->and(DB::table('bylopez_va_clientes_cupo_cartera_historial')->where('customer_id', $this->cliente->id)->count())->toBe(3);
});

// ------------------------------------------------------------------ CUPO-13 / 14: permisos (servicio)

test('CUPO-13/14 matriz de permisos: solo el administrador autorizado edita; un rol `all` cualquiera (BODEGA) y un asesor NO; ni con la clave asignada', function () {
    $aut = app(AutorizacionVentasAsesor::class);
    $bodegaAll = f03b_admin('all');                                                         // rol `all` que NO es el administrador autorizado
    $asesorVer = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_CUPO_VER]);          // asesor con solo ver
    $asesorConClaveEditar = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_CUPO_VER, AutorizacionVentasAsesor::PERMISO_CUPO_EDITAR, AutorizacionVentasAsesor::PERMISO_CUPO_AUTORIZAR_EXCESO]);
    $soloHistorial = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_CUPO_HISTORIAL]);
    $bodegaSinHojas = f03b_admin('custom', ['ventas_asesor.logistica.view']);
    $adminInactivo = d10_admin();
    $adminInactivo->forceFill(['status' => 0])->save();

    $matriz = fn ($a) => [
        $aut->puedeCupo($a, AutorizacionVentasAsesor::PERMISO_CUPO_VER), $aut->puedeCupo($a, AutorizacionVentasAsesor::PERMISO_CUPO_EDITAR),
        $aut->puedeCupo($a, AutorizacionVentasAsesor::PERMISO_CUPO_HISTORIAL), $aut->puedeCupo($a, AutorizacionVentasAsesor::PERMISO_CUPO_AUTORIZAR_EXCESO),
    ];

    expect($matriz($this->admin))->toBe([true, true, true, true])
        ->and($matriz($bodegaAll))->toBe([false, false, false, false])
        ->and($matriz($asesorVer))->toBe([true, false, false, false])
        ->and($matriz($asesorConClaveEditar))->toBe([true, false, false, false])   // la clave sola no basta: no es el administrador autorizado
        ->and($matriz($soloHistorial))->toBe([false, false, true, false])
        ->and($matriz($bodegaSinHojas))->toBe([false, false, false, false])
        ->and($matriz($adminInactivo))->toBe([false, false, false, false]);

    $s = app(GestionCupoCarteraService::class);

    foreach ([$bodegaAll, $asesorVer, $asesorConClaveEditar, $bodegaSinHojas, $adminInactivo] as $actor) {
        expect(cupo_error(fn () => $s->establecer($actor, $this->cliente->id, '999999', 'activo', 'Intento')))->toBe('cupo_sin_permiso');
    }

    cupo_fijar($this->admin, $this->cliente, 100000);
    expect(cupo_error(fn () => $s->historial($bodegaAll, $this->cliente->id)))->toBe('cupo_sin_permiso')
        ->and(cupo_error(fn () => $s->historial($asesorVer, $this->cliente->id)))->toBe('cupo_sin_permiso')
        ->and(cupo_error(fn () => $s->historial($soloHistorial, $this->cliente->id)))->toBeNull()
        ->and(DB::table('bylopez_va_clientes_cupo_cartera')->where('customer_id', $this->cliente->id)->count())->toBe(1)
        ->and(DB::table('bylopez_va_clientes_cupo_cartera_historial')->where('customer_id', $this->cliente->id)->count())->toBe(1);
});

// ------------------------------------------------------------------ HTTP: rutas y Bouncer real

test('HTTP: resumen del cupo — administrador ve todo; asesor con «ver» ve cifras sin auditoria; sin la clave 401; rol `all` ajeno recibe visible:false', function () {
    cupo_fijar($this->admin, $this->cliente, 2000000, 'activo', 'Cliente con referencias');
    cupo_pedido($this->cliente, 1350000);
    $url = route('admin.ventas_asesor.cartera.cupo.cliente', ['cliente' => $this->cliente->id]);
    $asesorVer = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_CUPO_VER]);
    $bodegaAll = f03b_admin('all');
    $sinClave = f03b_admin('custom', ['ventas_asesor.logistica.view']);

    $this->actingAs($this->admin, 'admin')->getJson($url.'?monto=800000')->assertOk()
        ->assertJsonPath('visible', true)->assertJsonPath('bloqueo_activo', true)
        ->assertJsonPath('cupo.autorizado', 2000000)->assertJsonPath('cupo.exposicion', 1350000)->assertJsonPath('cupo.disponible', 650000)
        ->assertJsonPath('cupo.estado', 'activo')->assertJsonPath('cupo.motivo', 'Cliente con referencias')->assertJsonPath('cupo.actualizado_por', $this->admin->name)
        ->assertJsonPath('evaluacion.permitido', false)->assertJsonPath('evaluacion.codigo', 'cupo_excedido')->assertJsonPath('evaluacion.exceso', 150000)
        ->assertJsonPath('puede_editar', true)->assertJsonPath('puede_historial', true);

    $this->actingAs($asesorVer, 'admin')->getJson($url)->assertOk()
        ->assertJsonPath('visible', true)->assertJsonPath('cupo.disponible', 650000)->assertJsonPath('cupo.motivo', null)->assertJsonPath('cupo.actualizado_por', null)
        ->assertJsonPath('cupo.pedidos', null)->assertJsonPath('puede_editar', false)->assertJsonPath('puede_historial', false);

    $this->actingAs($sinClave, 'admin')->getJson($url)->assertStatus(401);
    $this->actingAs($bodegaAll, 'admin')->getJson($url)->assertOk()->assertExactJson(['visible' => false]);
});

test('HTTP: el detalle por pedido solo se entrega con alcance de Cartera «todos» y concilia con la exposicion', function () {
    cupo_fijar($this->admin, $this->cliente, 2000000);
    cupo_pedido($this->cliente, 400000);
    cupo_pedido($this->cliente, 250000);
    $url = route('admin.ventas_asesor.cartera.cupo.cliente', ['cliente' => $this->cliente->id]).'?detalle=1';
    $conVerPropios = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_CUPO_VER, AutorizacionVentasAsesor::PERMISO_CARTERA_VER_PROPIA]);
    $conVerTodos = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_CUPO_VER, AutorizacionVentasAsesor::PERMISO_CARTERA_VER_TODA]);

    $r = $this->actingAs($conVerTodos, 'admin')->getJson($url)->assertOk()->json();
    expect($r['cupo']['pedidos'])->toHaveCount(2)->and(array_sum(array_column($r['cupo']['pedidos'], 'exposicion')))->toBe(650000)->and($r['cupo']['exposicion'])->toBe(650000);

    $this->actingAs($conVerPropios, 'admin')->getJson($url)->assertOk()->assertJsonPath('cupo.pedidos', null)->assertJsonPath('cupo.exposicion', 650000);
});

test('HTTP: Nueva venta pregunta por el cupo sin provocar 403: sin «ver» responde visible:false; con «ver» las cifras', function () {
    cupo_fijar($this->admin, $this->cliente, 1000000);
    $url = route('admin.ventas_asesor.nueva_venta.cupo', ['cliente' => $this->cliente->id]);
    $vendedor = f03b_admin('custom', [BorradorVentaService::PERMISO, AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR]);
    $vendedorConVer = f03b_admin('custom', [BorradorVentaService::PERMISO, AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR, AutorizacionVentasAsesor::PERMISO_CUPO_VER]);

    $this->actingAs($vendedor, 'admin')->getJson($url)->assertOk()->assertExactJson(['visible' => false]);
    $this->actingAs($vendedorConVer, 'admin')->getJson($url.'?monto=1500000')->assertOk()
        ->assertJsonPath('visible', true)->assertJsonPath('cupo.disponible', 1000000)->assertJsonPath('evaluacion.exceso', 500000);
});

test('HTTP CUPO-13/14: editar el cupo — administrador 200 con historial; asesor y bodega no pueden (401/403) y no se escribe nada', function () {
    $url = route('admin.ventas_asesor.cartera.cupo.editar', ['cliente' => $this->cliente->id]);
    $cuerpo = ['cupo' => '750000', 'estado' => 'activo', 'motivo' => 'Cliente nuevo', 'cupo_esperado' => '0', 'estado_esperado' => 'activo'];
    $asesor = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_CUPO_VER]);
    $asesorConEditar = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_CUPO_VER, AutorizacionVentasAsesor::PERMISO_CUPO_EDITAR]);
    $bodegaAll = f03b_admin('all');

    $this->actingAs($asesor, 'admin')->postJson($url, $cuerpo)->assertStatus(401);
    $this->actingAs($asesorConEditar, 'admin')->postJson($url, $cuerpo)->assertStatus(403)->assertJsonPath('codigo', 'cupo_sin_permiso');
    $this->actingAs($bodegaAll, 'admin')->postJson($url, $cuerpo)->assertStatus(403)->assertJsonPath('codigo', 'cupo_sin_permiso');
    expect(DB::table('bylopez_va_clientes_cupo_cartera')->where('customer_id', $this->cliente->id)->count())->toBe(0);

    $this->actingAs($this->admin, 'admin')->postJson($url, $cuerpo)->assertOk()
        ->assertJsonPath('accion', 'asignacion')->assertJsonPath('cupo.autorizado', 750000)->assertJsonPath('cupo.estado', 'activo');

    $this->actingAs($this->admin, 'admin')->postJson($url, ['cupo' => '750000', 'estado' => 'activo', 'motivo' => 'Igual'])->assertStatus(409)->assertJsonPath('codigo', 'cupo_sin_cambios');
    $this->actingAs($this->admin, 'admin')->postJson($url, ['cupo' => '-1', 'estado' => 'activo', 'motivo' => 'Negativo'])->assertStatus(422)->assertJsonPath('codigo', 'cupo_monto_invalido');
    $this->actingAs($this->admin, 'admin')->postJson($url, ['cupo' => '10', 'estado' => 'activo'])->assertStatus(422);     // motivo obligatorio (validacion de la peticion)
    $this->actingAs($this->admin, 'admin')->postJson($url, ['cupo' => '10', 'estado' => 'raro', 'motivo' => 'x'])->assertStatus(422);
    $this->actingAs($this->admin, 'admin')->postJson($url, $cuerpo)->assertStatus(409)->assertJsonPath('codigo', 'cupo_cambio_concurrente');

    expect(DB::table('bylopez_va_clientes_cupo_cartera_historial')->where('customer_id', $this->cliente->id)->count())->toBe(1);
});

test('HTTP: el historial solo lo lee quien tiene la hoja `historial` (o el administrador)', function () {
    cupo_fijar($this->admin, $this->cliente, 300000, 'activo', 'Alta');
    $url = route('admin.ventas_asesor.cartera.cupo.historial', ['cliente' => $this->cliente->id]);
    $conHistorial = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_CUPO_HISTORIAL]);
    $soloVer = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_CUPO_VER]);

    $this->actingAs($this->admin, 'admin')->getJson($url)->assertOk()->assertJsonPath('historial.0.motivo', 'Alta');
    $this->actingAs($conHistorial, 'admin')->getJson($url)->assertOk()->assertJsonPath('historial.0.cupo_nuevo', 300000);
    $this->actingAs($soloVer, 'admin')->getJson($url)->assertStatus(401);
});

test('HTTP: el rechazo por cupo en la confirmacion trae mensaje legible; las cifras solo para quien puede ver el cupo (y nunca para el resto)', function () {
    cupo_fijar($this->admin, $this->cliente, 500000);
    cupo_pedido($this->cliente, 450000);
    [$borrador, $total, $producto] = cupo_venta($this, $this->cliente, 100000);
    $url = route('admin.ventas_asesor.nueva_venta.confirmar', ['borrador' => $borrador->id]);
    $veedor = f03b_admin('custom', [BorradorVentaService::PERMISO, AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR, AutorizacionVentasAsesor::PERMISO_CUPO_VER]);

    // El borrador es del `asesor` (propietario): el que confirma por HTTP sin la hoja de cupo no recibe cifras.
    $r = f03f_sinCommitPrematuro(fn () => $this->actingAs($this->asesor, 'admin')->postJson($url, ['total_esperado' => $total]));
    $r->assertStatus(422)->assertJsonPath('codigo', 'cupo_excedido');
    expect($r->json('mensaje'))->toBe(trans('ventasasesor::cupo.rechazo.cupo_excedido'))->and($r->json())->not->toHaveKey('detalles')
        ->and($r->getContent())->not->toContain('450000')->not->toContain('500000');
    cupo_sin_pedido_ni_reserva($borrador, $producto);

    // Con la hoja de ver cupo (en el mismo borrador, via propietario): mensaje con cifras y detalles.
    BorradorVenta::query()->whereKey($borrador->id)->update(['propietario_admin_id' => $veedor->id]);
    $r2 = f03f_sinCommitPrematuro(fn () => $this->actingAs($veedor, 'admin')->postJson($url, ['total_esperado' => $total]));
    $r2->assertStatus(422)->assertJsonPath('codigo', 'cupo_excedido')->assertJsonPath('detalles.exceso', 50000);
    expect($r2->json('mensaje'))->toContain('$ 50.000');
});
