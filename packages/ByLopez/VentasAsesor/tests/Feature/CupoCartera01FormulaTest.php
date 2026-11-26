<?php

use ByLopez\VentasAsesor\DataObjects\CupoCartera;
use ByLopez\VentasAsesor\DataObjects\SaldoPedido;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Services\Cotizaciones\CotizacionService;
use ByLopez\VentasAsesor\Services\Cupo\CupoCarteraClienteService;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Services\Venta\NuevaVentaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../helpersCupo.php';

uses(TestCase::class);

/*
| Cupo de cartera (Doc. 35) — formula, consumo y liberacion. Solo lpz_saas_testing (o la base exclusiva de la rama).
| `exposicion_pedido = max(0, totalOperativo − verificado)`; el pago `sin_revisar` NO libera; nada se guarda salvo la politica.
*/

beforeEach(function () {
    ['admin' => $this->admin, 'cliente' => $this->cliente] = cupo_base();
});

// ------------------------------------------------------------------ CUPO-02 / 03 / 04: disponible y obligacion nueva

test('CUPO-02/03/04 cupo 2.000.000 y exposicion 1.300.000: disponible 700.000; 500.000 pasa; 800.000 excede en 100.000', function () {
    cupo_fijar($this->admin, $this->cliente, 2000000);
    cupo_pedido($this->cliente, 1300000);
    $servicio = app(CupoCarteraClienteService::class);
    $c = cupo_de($this->cliente);

    expect($c->autorizado)->toBe(2000000.0)->and($c->exposicion)->toBe(1300000.0)->and($c->disponible)->toBe(700000.0)
        ->and($c->exceso)->toBe(0.0)->and($c->utilizacionPct)->toBe(65.0)->and($c->estado)->toBe(CupoCartera::ACTIVO);

    $cabe = $servicio->evaluar($c, 500000);
    $excede = $servicio->evaluar($c, 800000);

    expect($cabe->permitido)->toBeTrue()->and($cabe->codigo)->toBeNull()->and($cabe->disponibleDespues)->toBe(200000.0)->and($cabe->exceso)->toBe(0.0)
        ->and($excede->permitido)->toBeFalse()->and($excede->codigo)->toBe('cupo_excedido')->and($excede->exceso)->toBe(100000.0)
        ->and($excede->disponibleDespues)->toBe(0.0)->and($excede->disponibleAntes)->toBe(700000.0);
});

test('CUPO-04 ejemplo del encargo: cupo 2.000.000, exposicion 1.350.000 → disponible 650.000; una obligacion de 800.000 «excede el cupo en 150.000»', function () {
    cupo_fijar($this->admin, $this->cliente, 2000000);
    cupo_pedido($this->cliente, 1000000);
    cupo_pedido($this->cliente, 350000);
    $c = cupo_de($this->cliente);
    $e = app(CupoCarteraClienteService::class)->evaluar($c, 800000);

    expect($c->exposicion)->toBe(1350000.0)->and($c->disponible)->toBe(650000.0)->and($c->pedidosQueConsumen)->toBe(2)
        ->and($e->permitido)->toBeFalse()->and($e->codigo)->toBe('cupo_excedido')->and($e->exceso)->toBe(150000.0);
});

test('un monto exacto al disponible cabe; un centavo mas excede (compara en centavos enteros)', function () {
    cupo_fijar($this->admin, $this->cliente, 1000000);
    cupo_pedido($this->cliente, 400000.55);
    $servicio = app(CupoCarteraClienteService::class);
    $c = cupo_de($this->cliente);

    expect($c->disponible)->toBe(599999.45)
        ->and($servicio->evaluar($c, 599999.45)->permitido)->toBeTrue()
        ->and($servicio->evaluar($c, 599999.46)->permitido)->toBeFalse()
        ->and($servicio->evaluar($c, 599999.46)->exceso)->toBe(0.01);
});

// ------------------------------------------------------------------ estados y utilizacion

test('estados derivados: sin_cupo, activo, cerca (≥80 %), agotado, excedido y bloqueado, con disponible nunca negativo', function () {
    $s = app(CupoCarteraClienteService::class);

    expect(cupo_de($this->cliente)->estado)->toBe(CupoCartera::SIN_CUPO)->and(cupo_de($this->cliente)->tienePolitica)->toBeFalse();

    cupo_fijar($this->admin, $this->cliente, 1000000);
    expect(cupo_de($this->cliente)->estado)->toBe(CupoCartera::ACTIVO);

    $a = cupo_pedido($this->cliente, 790000);
    expect(cupo_de($this->cliente)->estado)->toBe(CupoCartera::ACTIVO)->and(cupo_de($this->cliente)->utilizacionPct)->toBe(79.0);

    $b = cupo_pedido($this->cliente, 10000); // 800.000 = 80 % exacto
    $c = cupo_de($this->cliente);
    expect($c->estado)->toBe(CupoCartera::CERCA)->and($c->utilizacionPct)->toBe(80.0)->and($c->disponible)->toBe(200000.0);

    cupo_pedido($this->cliente, 200000); // 1.000.000 = 100 %
    $c = cupo_de($this->cliente);
    expect($c->estado)->toBe(CupoCartera::AGOTADO)->and($c->disponible)->toBe(0.0)->and($c->exceso)->toBe(0.0);

    cupo_pedido($this->cliente, 50000); // 1.050.000
    $c = cupo_de($this->cliente);
    expect($c->estado)->toBe(CupoCartera::EXCEDIDO)->and($c->disponible)->toBe(0.0)->and($c->exceso)->toBe(50000.0)->and($c->utilizacionPct)->toBe(105.0);

    cupo_fijar($this->admin, $this->cliente, 1000000, 'bloqueado', 'Cliente en mora');
    // BLOQUEADO manda sobre el monto: ni siquiera «excedido».
    expect(cupo_de($this->cliente)->estado)->toBe(CupoCartera::BLOQUEADO)->and(cupo_de($this->cliente)->bloqueado())->toBeTrue();
});

test('el umbral «cerca del limite» es configurable', function () {
    config(['ventasasesor.cupo.umbral_alerta_pct' => 50]);
    cupo_fijar($this->admin, $this->cliente, 1000000);
    cupo_pedido($this->cliente, 500000);

    expect(cupo_de($this->cliente)->estado)->toBe(CupoCartera::CERCA);
});

test('cupo 0 explicito: sin deuda es sin_cupo; con deuda es excedido (hay una politica y la deuda la supera)', function () {
    cupo_fijar($this->admin, $this->cliente, 5000);
    cupo_fijar($this->admin, $this->cliente, 0, 'activo', 'Se retira el cupo');

    expect(cupo_de($this->cliente)->estado)->toBe(CupoCartera::SIN_CUPO)->and(cupo_de($this->cliente)->utilizacionPct)->toBeNull();

    cupo_pedido($this->cliente, 30000);
    $c = cupo_de($this->cliente);

    expect($c->estado)->toBe(CupoCartera::EXCEDIDO)->and($c->exceso)->toBe(30000.0)->and($c->disponible)->toBe(0.0);
});

test('cliente sin politica pero con deuda: sigue «sin_cupo» (no hay limite que exceder) y la exposicion se muestra', function () {
    cupo_pedido($this->cliente, 120000);
    $c = cupo_de($this->cliente);

    expect($c->tienePolitica)->toBeFalse()->and($c->estado)->toBe(CupoCartera::SIN_CUPO)->and($c->exposicion)->toBe(120000.0)
        ->and($c->autorizado)->toBe(0.0)->and($c->disponible)->toBe(0.0);
});

// ------------------------------------------------------------------ CUPO-05 / 06 / 18: pagos

test('CUPO-05 un pago CONFIRMADO libera cupo', function () {
    cupo_fijar($this->admin, $this->cliente, 1000000);
    $p = cupo_pedido($this->cliente, 500000);
    expect(cupo_de($this->cliente)->disponible)->toBe(500000.0);

    cupo_pagar($this->admin, $p->id, '200000');

    $c = cupo_de($this->cliente);
    expect($c->exposicion)->toBe(300000.0)->and($c->disponible)->toBe(700000.0);

    cupo_pagar($this->admin, $p->id, '300000');
    expect(cupo_de($this->cliente)->exposicion)->toBe(0.0)->and(cupo_de($this->cliente)->pedidosQueConsumen)->toBe(0);
});

test('CUPO-06 un pago SIN REVISAR no libera cupo (Cartera lo resta del saldo; el cupo no) hasta que se confirma', function () {
    cupo_fijar($this->admin, $this->cliente, 1000000);
    $p = cupo_pedido($this->cliente, 500000);
    $pago = cupo_pagar($this->admin, $p->id, '200000', confirmar: false);

    $saldo = app(CarteraConsultaService::class)->saldosDePedidos([$p->id])[$p->id];
    $c = cupo_de($this->cliente);

    // Cartera: saldo 300.000 (descuenta lo sin revisar). Cupo: exposicion 500.000 (solo lo verificado libera).
    expect($saldo->saldo)->toBe(300000.0)->and($saldo->sinRevisar)->toBe(200000.0)->and($saldo->verificado)->toBe(0.0)
        ->and($c->exposicion)->toBe(500000.0)->and($c->disponible)->toBe(500000.0);

    app(\ByLopez\VentasAsesor\Services\Cartera\ConfirmarPagoService::class)->confirmar($this->admin, $pago);

    expect(cupo_de($this->cliente)->exposicion)->toBe(300000.0);
});

test('un pago RECHAZADO nunca libero cupo (ni mientras estaba sin revisar ni despues)', function () {
    cupo_fijar($this->admin, $this->cliente, 1000000);
    $p = cupo_pedido($this->cliente, 400000);

    $rechazado = cupo_pagar($this->admin, $p->id, '400000', confirmar: false);
    expect(cupo_de($this->cliente)->exposicion)->toBe(400000.0);

    app(\ByLopez\VentasAsesor\Services\Cartera\RechazarPagoService::class)->rechazar($this->admin, $rechazado, 'no_encontrado', 'Comprobante ilegible');
    expect(cupo_de($this->cliente)->exposicion)->toBe(400000.0);

    cupo_pagar($this->admin, $p->id, '400000');
    expect(cupo_de($this->cliente)->exposicion)->toBe(0.0);
});

test('CUPO-18 pago parcial: solo el saldo real pendiente consume (confirmado 400.000 + sin revisar 100.000 de 1.000.000 → exposicion 600.000)', function () {
    cupo_fijar($this->admin, $this->cliente, 2000000);
    $p = cupo_pedido($this->cliente, 1000000);
    cupo_pagar($this->admin, $p->id, '400000');
    cupo_pagar($this->admin, $p->id, '100000', confirmar: false);

    $c = cupo_de($this->cliente, detalle: true);

    expect($c->exposicion)->toBe(600000.0)->and($c->disponible)->toBe(1400000.0)
        ->and($c->pedidos)->toHaveCount(1)
        ->and($c->pedidos[0])->toMatchArray(['total' => 1000000.0, 'verificado' => 400000.0, 'sin_revisar' => 100000.0, 'exposicion' => 600000.0]);
});

test('CUPO-17 una venta pagada completa NO suma exposicion: 3 pedidos pagados por 2.000.000 dejan el disponible intacto', function () {
    cupo_fijar($this->admin, $this->cliente, 500000);

    foreach ([700000, 800000, 500000] as $total) {
        $p = cupo_pedido($this->cliente, $total);
        cupo_pagar($this->admin, $p->id, (string) $total);
    }

    $c = cupo_de($this->cliente);

    expect($c->exposicion)->toBe(0.0)->and($c->disponible)->toBe(500000.0)->and($c->estado)->toBe(CupoCartera::ACTIVO)->and($c->pedidosQueConsumen)->toBe(0)
        ->and(app(CupoCarteraClienteService::class)->evaluar($c, 500000)->permitido)->toBeTrue();
});

// ------------------------------------------------------------------ CUPO-07: D10

test('CUPO-07 D10: el credito DISPONIBLE no libera cupo; al APLICARLO baja la exposicion del pedido destino', function () {
    $e = d10_escenario(); // A pagado 70.000 con flete condonado de 20.000 → credito 20.000; B por cobrar 50.000
    cupo_fijar($e['admin'], $e['cliente'], 1000000);

    $antes = cupo_de($e['cliente'], detalle: true);
    expect($antes->exposicion)->toBe(50000.0)->and($antes->pedidos)->toHaveCount(1)->and($antes->pedidos[0]['order_id'])->toBe((int) $e['b']->id);

    $mov = d10_aplicar($e); // aplica 20.000 de credito a B

    $despues = cupo_de($e['cliente'], detalle: true);
    expect($despues->exposicion)->toBe(30000.0)->and($despues->pedidos[0]['verificado'])->toBe(20000.0)
        ->and(d10_saldo($e['b']->id)->creditoAplicado)->toBe(20000.0);

    // Reversar la aplicacion (PAG-I) devuelve la obligacion: el cupo vuelve a consumirse porque lo derivado cambia solo.
    app(\ByLopez\VentasAsesor\Services\Credito\ReversarAplicacionCreditoService::class)->reversar($e['admin'], $mov['movimiento_id'], 'Se reversa la prueba', 'cupo-rev-'.uniqid());

    expect(cupo_de($e['cliente'])->exposicion)->toBe(50000.0);
});

test('D10: la condonacion de flete (ajuste autorizado) libera cupo porque reduce la obligacion', function () {
    cupo_fijar($this->admin, $this->cliente, 1000000);
    $p = d10_pedido($this->cliente, 70000, 20000);
    expect(cupo_de($this->cliente)->exposicion)->toBe(70000.0);

    app(\ByLopez\VentasAsesor\Services\Credito\AjusteObligacionService::class)->condonarFlete(
        $this->admin, $p->id, '20000', \ByLopez\VentasAsesor\Models\AjusteObligacion::CAUSA_ERROR_BYLOPEZ, 'Error de despacho de ByLopez', 'cupo-aju-'.uniqid(),
    );

    expect(cupo_de($this->cliente)->exposicion)->toBe(50000.0);
});

// ------------------------------------------------------------------ CUPO-08: cancelaciones

test('CUPO-08 una cancelacion valida deja de consumir: pedido cancelado → 0; cancelacion parcial de Logistica → solo el resto', function () {
    cupo_fijar($this->admin, $this->cliente, 1000000);
    $entero = cupo_pedido($this->cliente, 300000);
    $parcial = cupo_pedido($this->cliente, 500000);
    expect(cupo_de($this->cliente)->exposicion)->toBe(800000.0);

    DB::table('orders')->where('id', $entero->id)->update(['status' => 'canceled']);
    expect(cupo_de($this->cliente)->exposicion)->toBe(500000.0);

    cupo_cancelar_parte($parcial, 200000);
    expect(cupo_de($this->cliente)->exposicion)->toBe(300000.0);
});

// ------------------------------------------------------------------ quien NO consume

test('no consumen: pedidos anteriores a la fecha de corte, cerrados, fraude, ni los de otro cliente', function () {
    cupo_fijar($this->admin, $this->cliente, 1000000);
    pagb_corte('2026-01-01');

    cupo_pedido($this->cliente, 111000, ['created_at' => '2025-12-31 23:00:00']);   // historico: fuera de Cartera
    cupo_pedido($this->cliente, 222000, ['status' => 'closed', 'created_at' => '2026-02-01 10:00:00']);
    cupo_pedido($this->cliente, 333000, ['status' => 'fraud', 'created_at' => '2026-02-01 10:00:00']);
    cupo_pedido(pagb_cliente(), 444000, ['created_at' => '2026-02-01 10:00:00']);   // de otro cliente
    $cuenta = cupo_pedido($this->cliente, 55000, ['created_at' => '2026-02-01 10:00:00']);

    $c = cupo_de($this->cliente, detalle: true);

    expect($c->exposicion)->toBe(55000.0)->and($c->pedidosQueConsumen)->toBe(1)->and($c->pedidos[0]['order_id'])->toBe((int) $cuenta->id);
});

test('Cartera inactiva: la exposicion no es calculable (0 con cartera_activa = false) y evaluar falla CERRADO si hay obligacion nueva', function () {
    cupo_fijar($this->admin, $this->cliente, 1000000);
    cupo_pedido($this->cliente, 400000);
    pagb_corte(null);

    $s = app(CupoCarteraClienteService::class);
    $c = cupo_de($this->cliente);

    expect($c->carteraActiva)->toBeFalse()->and($c->exposicion)->toBe(0.0)
        ->and($s->evaluar($c, 100)->codigo)->toBe('cupo_no_verificable')
        ->and($s->evaluar($c, 0)->permitido)->toBeTrue(); // sin obligacion nueva no hay nada que verificar
});

test('un pedido que requiere revision (Refund nativo) SI consume y se marca: ante la duda no se libera credito', function () {
    cupo_fijar($this->admin, $this->cliente, 1000000);
    cupo_pedido($this->cliente, 250000, ['base_grand_total_refunded' => 10000]);

    $c = cupo_de($this->cliente, detalle: true);

    expect($c->exposicion)->toBe(250000.0)->and($c->pedidosEnRevision)->toBe(1)
        ->and($c->pedidos[0]['requiere_revision'])->toBeTrue()->and($c->pedidos[0]['motivos_revision'])->toContain(SaldoPedido::REVISAR_REEMBOLSO);
});

test('una cotizacion NO consume ni reserva cupo (no es un pedido)', function () {
    f03f_configurarBase();
    cupo_fijar($this->admin, $this->cliente, 1000000);
    $antes = cupo_de($this->cliente);

    // Una venta en curso real (borrador con prendas) y su cotizacion por el servicio de verdad.
    $asesor = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_MODULO, BorradorVentaService::PERMISO]);
    $configurable = f03b_configurable(10000.0);

    foreach ($configurable->variants()->get() as $variante) {
        f03b_stock($variante, 50);
    }

    [$m] = $configurable->variants()->get()->groupBy(fn ($v) => (int) $v->color)->first(fn ($g) => $g->count() >= 2)->take(1)->values()->all();
    $nv = app(NuevaVentaService::class);
    $borrador = $nv->iniciar($this->cliente->id, $asesor, 'tienda_fisica')->borrador->id;
    $nv->aplicarTallas($borrador, $configurable->id, [$m->id => 3], $asesor);
    $cotizacion = app(CotizacionService::class)->crear($borrador, $asesor);

    $despues = cupo_de($this->cliente);

    expect($cotizacion->id)->toBeInt()
        ->and($despues->exposicion)->toBe($antes->exposicion)->and($despues->disponible)->toBe(1000000.0)->and($despues->pedidosQueConsumen)->toBe(0)
        ->and(DB::table('orders')->where('customer_id', $this->cliente->id)->count())->toBe(0);
});

// ------------------------------------------------------------------ componentes de Cartera (devuelto, cancelado, ajustado)

test('la exposicion usa el totalOperativo de Cartera: devuelto, cancelado y ajustado reducen; el exceso de pago nunca da exposicion negativa', function () {
    $saldos = [
        1 => new SaldoPedido(1, 9, 'A', 'pending', 1000000.0, true, null, 0.0, 0.0, 0.0, 0.0, 1000000.0, false, [], 200000.0, 2, 800000.0, 0.0, 0.0, 0.0, 0.0),   // devuelto 200.000
        2 => new SaldoPedido(2, 9, 'B', 'pending', 500000.0, true, null, 0.0, 0.0, 0.0, 0.0, 500000.0, false, [], 0.0, 0, 350000.0, 0.0, 150000.0, 0.0, 0.0),     // cancelado 150.000
        3 => new SaldoPedido(3, 9, 'C', 'pending', 300000.0, true, null, 120000.0, 120000.0, 0.0, 0.0, 130000.0, false, [], 0.0, 0, 250000.0, 0.0, 0.0, 50000.0, 0.0), // ajustado 50.000, verificado 120.000
        4 => new SaldoPedido(4, 9, 'D', 'pending', 100000.0, true, null, 150000.0, 150000.0, 0.0, 0.0, 0.0, false, [], 0.0, 0, 100000.0, 0.0, 0.0, 0.0, 0.0),       // pagado de mas
        5 => SaldoPedido::fuera(5, 9, 'E', 'canceled', 700000.0, SaldoPedido::FUERA_ESTADO_NO_APLICA),
    ];

    // Los ids 1..5 del doble se reasignan a pedidos reales del cliente (el servicio lee `orders` para saber QUE pedidos tiene).
    $semilla =collect([1, 2, 3, 4, 5])->map(fn ($i) => cupo_pedido($this->cliente, 1000.0 * $i))->all();
    $mapa = [];

    foreach ($semilla as $i => $pedido) {
        $mapa[(int) $pedido->id] = $saldos[$i + 1];
    }

    app()->instance(CarteraConsultaService::class, new class($mapa) extends CarteraConsultaService
    {
        public function __construct(private array $mapa) {}

        public function saldosDePedidos(array $orderIds, bool $lecturaActual = false): array
        {
            return array_intersect_key($this->mapa, array_flip($orderIds));
        }
    });

    $c = app(CupoCarteraClienteService::class)->delCliente((int) $this->cliente->id, true);

    // A 800.000 + B 350.000 + C (250.000 − 120.000 = 130.000) + D 0 (sobrepagado: nunca negativo) + E fuera de Cartera.
    expect($c->exposicion)->toBe(1280000.0)->and($c->pedidosQueConsumen)->toBe(3)
        ->and(array_sum(array_column($c->pedidos, 'exposicion')))->toBe($c->exposicion);
});

// ------------------------------------------------------------------ conciliacion del detalle con el total

test('el detalle por pedido concilia EXACTAMENTE con la exposicion y con Cartera (saldo + sin revisar)', function () {
    cupo_fijar($this->admin, $this->cliente, 5000000);
    $a = cupo_pedido($this->cliente, 700000);
    $b = cupo_pedido($this->cliente, 450000);
    $c = cupo_pedido($this->cliente, 120000);
    cupo_pagar($this->admin, $a->id, '300000');
    cupo_pagar($this->admin, $b->id, '100000', confirmar: false);
    cupo_pagar($this->admin, $c->id, '120000');

    $cupo = cupo_de($this->cliente, detalle: true);
    $saldos = app(CarteraConsultaService::class)->saldosDePedidos([$a->id, $b->id, $c->id]);

    $exposicion = 0.0;

    foreach ($cupo->pedidos as $fila) {
        $s = $saldos[$fila['order_id']];
        // exposicion = saldo de Cartera + la parte «sin revisar» que Cartera ya descontaba (unica diferencia declarada).
        expect($fila['exposicion'])->toBe(round($s->saldo + min($s->sinRevisar, max(0.0, $s->totalOperativo - $s->verificado)), 2));
        $exposicion += $fila['exposicion'];
    }

    expect(round($exposicion, 2))->toBe($cupo->exposicion)->and($cupo->exposicion)->toBe(400000.0 + 450000.0)
        ->and($cupo->pedidosQueConsumen)->toBe(2) // C quedo pagado: no aparece
        ->and(array_column($cupo->pedidos, 'order_id'))->toBe([(int) $a->id, (int) $b->id]);
});

test('lote: deClientes devuelve el cupo de varios clientes con el MISMO numero de consultas que uno solo (sin N+1)', function () {
    $clientes = [$this->cliente, pagb_cliente(), pagb_cliente(), pagb_cliente()];

    foreach ($clientes as $i => $cliente) {
        cupo_pedido($cliente, 100000 * ($i + 1));
        cupo_pedido($cliente, 50000);
    }

    $ids = array_map(fn ($c) => (int) $c->id, $clientes);
    $servicio = app(CupoCarteraClienteService::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $servicio->deClientes([$ids[0]]);
    $uno = count(DB::getQueryLog());

    DB::flushQueryLog();
    $todos = $servicio->deClientes($ids);
    $varios = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect(array_map(fn ($c) => $c->exposicion, $todos))->toBe([
        $ids[0] => 150000.0, $ids[1] => 250000.0, $ids[2] => 350000.0, $ids[3] => 450000.0,
    ])->and($varios)->toBe($uno);
});

test('el servicio es de solo lectura: consultar el cupo no escribe nada', function () {
    cupo_fijar($this->admin, $this->cliente, 1000000);
    cupo_pedido($this->cliente, 100000);

    $tablas = ['bylopez_va_clientes_cupo_cartera', 'bylopez_va_clientes_cupo_cartera_historial', 'orders', 'bylopez_va_pagos', 'bylopez_va_aplicaciones_pago'];
    $antes = array_map(fn ($t) => DB::table($t)->count(), $tablas);

    cupo_de($this->cliente, detalle: true);
    app(CupoCarteraClienteService::class)->puedeAprobar((int) $this->cliente->id, 900000);

    expect(array_map(fn ($t) => DB::table($t)->count(), $tablas))->toBe($antes);
});
