<?php

use ByLopez\Logistica\Contracts\GestionaCobroPedido;
use ByLopez\Logistica\Contracts\PagosPendientesDeConfirmar;
use ByLopez\Logistica\DataObjects\SolicitudEventoEnvio;
use ByLopez\Logistica\Enums\FuenteEventoLogistico as Fuente;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\ConciliacionMovimiento;
use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Models\LiquidacionTransportadora;
use ByLopez\Logistica\Models\MovimientoCuenta;
use ByLopez\Logistica\Services\Cuentas\LiquidarCuentaService;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use ByLopez\Logistica\Services\Entregas\CobrarEntregaService;
use ByLopez\Logistica\Services\Entregas\RegistrarEntregaService;
use ByLopez\VentasAsesor\Models\AplicacionPago;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Services\Cartera\CajaPedidoService;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Services\Comisiones\ElegibilidadComisionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../helpersDespacho.php';
require_once __DIR__.'/../helpersDomicilios.php';

uses(TestCase::class);

/*
| C6 — recaudo REAL → custodia del transportador → liquidacion → conciliacion con ByLopez (2026-10-26). El recaudo nace del COBRO
| (no del valor esperado); la liquidacion no crea pagos ni toca Cartera; efectivo y compensado se guardan por separado; una diferencia
| es del TRANSPORTADOR y nunca reabre la deuda del cliente. Solo lpz_saas_testing. Cada prueba usa su propia transportadora (Jeison:
| domicilio local de Bogota a 10.000 → cada entrega genera un servicio de −10.000).
*/

const C6_ADMIN = ['logistica', 'logistica.cuentas', 'logistica.cuentas.liquidar', 'logistica.entregas', 'logistica.entregas.registrar'];

/** Cartera activa, recaudo-desde-cobro activo y un catalogo propio de la prueba. @return array{jeison: mixed} */
function c6_inicio(): array
{
    config(['logistica.cuentas.recaudo_desde_cobro_desde' => '2000-01-01']);
    pagb_corte('2000-01-01');
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

/** Pedido contra entrega de `$total`, despachado con la transportadora (una guia C6) y, si `$entregar`, entregado completo. */
function c6_pedido(array $c, float $total, bool $entregar = false): array
{
    $e = log2_pedido(1, ['envio' => 'domicilio_local', 'pago' => 'cashondelivery', 'total' => $total, 'stock' => 30]);
    pagb_factura($e['pedido'], 'pending', $total);
    $guia = app(DespachoService::class)->despachar(log2_bodega(), log2_s(['pedido' => $e['pedido']], [
        'transportadora' => $c['jeison']->codigo, 'fletePagador' => '', 'unidades' => [$e['item']->id => 1],
    ]))->guia;

    if ($entregar) {
        $items = DB::table('shipment_items')->where('shipment_id', $guia->shipment_id)->whereNotNull('order_item_id')->where('qty', '>', 0)->pluck('qty', 'id')->map(fn ($q) => (int) $q)->all();
        app(RegistrarEntregaService::class)->registrar(f03b_admin('custom', C6_ADMIN), new SolicitudEventoEnvio(
            shipmentId: (int) $guia->shipment_id, orderId: (int) $guia->order_id, fecha: now()->toDateString(), items: $items,
            fuente: Fuente::SUPERVISOR_MANUAL, idempotencyKey: 'c6-ent-'.Str::uuid(),
        ));
    }

    return ['pedido' => $e['pedido'], 'guia' => $guia];
}

/** Cobro del transportador por la via oficial (adaptador de Logistica a Cartera): registra y CONFIRMA el pago, ligado a la guia. */
function c6_cobrar(array $p, string $monto): int
{
    $r = app(GestionaCobroPedido::class)->cobrar(
        f03b_admin('all'), (int) $p['pedido']->id, $monto, 'contraentrega_recaudo', null, now()->toDateString(), 'c6-cobro-'.Str::uuid(), (int) $p['guia']->id,
    );

    return $r['pago_id'];
}

function c6_abiertos(array $c): array
{
    return MovimientoCuenta::query()->where('transportadora_id', $c['jeison']->id)->where('estado', 'abierto')->orderBy('id')->pluck('id')->all();
}

function c6_liquidar(array $c, string $valor, array $extra = [], ?array $ids = null): LiquidacionTransportadora
{
    return app(LiquidarCuentaService::class)->liquidar(f03b_admin('custom', C6_ADMIN), (int) $c['jeison']->id, $extra + [
        'movimientos' => $ids ?? c6_abiertos($c), 'valor' => $valor, 'idempotency_key' => 'c6-liq-'.Str::uuid(),
    ]);
}

function c6_caja(array $p): array
{
    return app(CajaPedidoService::class)->dePedidos([(int) $p['pedido']->id])[(int) $p['pedido']->id];
}

function c6_saldo(array $p): float
{
    return app(CarteraConsultaService::class)->saldosDePedidos([(int) $p['pedido']->id])[(int) $p['pedido']->id]->saldo;
}

/** Motivos financieros de comision que C6 gobierna. */
function c6_motivos(array $p): array
{
    return array_values(array_filter(
        app(ElegibilidadComisionService::class)->dePedido((int) $p['pedido']->id)['motivos'],
        fn ($m) => str_starts_with($m, 'recaudo_') || str_contains($m, 'custodia') || str_contains($m, 'liquidacion'),
    ));
}

/** Lo que Cartera cuenta del dinero: pagos, aplicaciones y transacciones del pedido. */
function c6_dinero_cartera(array $p): array
{
    $id = (int) $p['pedido']->id;

    return [
        AplicacionPago::query()->where('order_id', $id)->whereNull('anulada_en')->count(),
        DB::table('order_transactions')->where('order_id', $id)->count(),
        (float) AplicacionPago::query()->where('order_id', $id)->whereNull('anulada_en')->sum('monto'),
    ];
}

test('C6-01 cobro real + liquidacion completa: el recaudo nace de lo COBRADO, queda en custodia y se concilia sin segundo pago en Cartera', function () {
    $c = c6_inicio();
    $p = c6_pedido($c, 59000.0);

    // Lo esperado es una expectativa, no dinero: sin cobro no hay recaudo en la cuenta ni custodia.
    expect((float) $p['guia']->recaudo_esperado)->toBe(59000.0)
        ->and(MovimientoCuenta::query()->where('guia_id', $p['guia']->id)->where('concepto', 'recaudo')->count())->toBe(0)
        ->and(c6_caja($p)['aplica'])->toBeFalse()
        ->and(c6_saldo($p))->toBe(59000.0);

    $pagoId = c6_cobrar($p, '59000');
    $pago = Pago::query()->findOrFail($pagoId);
    $recaudo = MovimientoCuenta::query()->where('guia_id', $p['guia']->id)->where('concepto', 'recaudo')->sole();
    [$aplicaciones, $transacciones] = c6_dinero_cartera($p);

    // El pago queda ligado a la guia y el recaudo nace de ESE cobro (idempotente por su clave).
    expect($pago->guia_id)->toBe((int) $p['guia']->id)
        ->and((float) $recaudo->importe)->toBe(59000.0)
        ->and($recaudo->origen_tipo)->toBe('cobro')
        ->and($recaudo->origen_id)->toBe($pagoId)
        ->and($recaudo->idempotency_key)->toBe('cobro:'.$pagoId.':recaudo')
        ->and($recaudo->order_id)->toBe((int) $p['pedido']->id);

    // Cartera: el cliente ya no debe. Caja: el dinero esta EN CUSTODIA del transportador, aun sin liquidar.
    $antes = c6_caja($p);

    expect(c6_saldo($p))->toBe(0.0)
        ->and([$antes['cobrado'], $antes['en_custodia'], $antes['conciliado'], $antes['efectivo'], $antes['compensado']])->toBe([59000.0, 59000.0, 0.0, 0.0, 0.0])
        ->and(c6_motivos($p))->toContain('recaudo_en_custodia');

    // Liquidar: el transportador entrega los 59.000.
    $liq = c6_liquidar($c, '59000');
    $despues = c6_caja($p);

    expect((float) $liq->diferencia)->toBe(0.0)
        ->and([$despues['cobrado'], $despues['efectivo'], $despues['compensado'], $despues['conciliado'], $despues['en_custodia'], $despues['pendiente_transportador']])
        ->toBe([59000.0, 59000.0, 0.0, 59000.0, 0.0, 0.0])
        ->and($despues['liquidaciones'])->toBe([$liq->numero()])
        ->and(c6_motivos($p))->toBe([]);

    // Liquidar NO creo otro pago, aplicacion ni transaccion, y Cartera sigue igual.
    expect(c6_dinero_cartera($p))->toBe([$aplicaciones, $transacciones, 59000.0])
        ->and(Pago::query()->where('customer_id', $pago->customer_id)->count())->toBe(1)
        ->and(c6_saldo($p))->toBe(0.0);
});

test('C6-02 compensacion: 59.000 cobrados − 10.000 de servicio = 49.000 de efectivo; conciliado 59.000, caja sin inflar y el servicio sigue siendo gasto', function () {
    $c = c6_inicio();
    $p = c6_pedido($c, 59000.0, entregar: true);

    // El servicio del domicilio (−10.000) nace de la entrega; el recaudo, solo del cobro. Aqui se cobra por el drawer de Entregas
    // (CobrarEntregaService), que conoce el envio: la guia viaja hasta el pago.
    expect(MovimientoCuenta::query()->where('guia_id', $p['guia']->id)->where('concepto', 'recaudo')->count())->toBe(0);

    $r = app(CobrarEntregaService::class)->cobrar(f03b_admin('all'), (int) $p['guia']->shipment_id, '59000', 'contraentrega_recaudo', null, null, 'c6-drawer-'.Str::uuid());
    $servicio = MovimientoCuenta::query()->where('guia_id', $p['guia']->id)->where('concepto', 'servicio')->sole();
    $recaudo = MovimientoCuenta::query()->where('guia_id', $p['guia']->id)->where('concepto', 'recaudo')->sole();

    expect(Pago::query()->findOrFail($r['pago_id'])->guia_id)->toBe((int) $p['guia']->id)
        ->and((float) $servicio->importe)->toBe(-10000.0)
        ->and((float) $recaudo->importe)->toBe(59000.0)
        ->and(c6_motivos($p))->toContain('recaudo_en_custodia');

    // El sistema PROPONE: efectivo sugerido 49.000 (59.000 − 10.000); el administrador entrega 49.000.
    $liq = c6_liquidar($c, '49000');
    $caja = c6_caja($p);

    expect((float) $liq->saldo_esperado)->toBe(49000.0)
        ->and((float) $liq->diferencia)->toBe(0.0)
        ->and($caja['cobrado'])->toBe(59000.0)
        ->and($caja['efectivo'])->toBe(49000.0)
        ->and($caja['compensado'])->toBe(10000.0)
        ->and($caja['conciliado'])->toBe(59000.0)
        ->and($caja['en_custodia'])->toBe(0.0)
        ->and($caja['pendiente_transportador'])->toBe(0.0);

    // Atribucion por movimiento: el recaudo se satisface con 49.000 de efectivo + 10.000 compensados; el servicio, con 10.000 compensados.
    $filas = ConciliacionMovimiento::query()->where('liquidacion_id', $liq->id)->get()->keyBy('movimiento_id');

    expect([(float) $filas[$recaudo->id]->efectivo, (float) $filas[$recaudo->id]->compensado])->toBe([49000.0, 10000.0])
        ->and([(float) $filas[$servicio->id]->efectivo, (float) $filas[$servicio->id]->compensado])->toBe([0.0, 10000.0])
        ->and($filas[$recaudo->id]->origen)->toBe('propuesta');

    // Caja REAL = lo entregado (49.000), nunca 59.000: la compensacion no se suma. El libro registra solo el pago de 49.000 y suma 0.
    $libro = MovimientoCuenta::query()->where('liquidacion_id', $liq->id)->get();

    expect((float) $libro->where('concepto', 'pago_liquidacion')->sole()->importe)->toBe(-49000.0)
        ->and(round((float) $libro->sum('importe'), 2))->toBe(0.0)
        ->and((float) ConciliacionMovimiento::query()->where('liquidacion_id', $liq->id)->sum('efectivo'))->toBe(49000.0)
        ->and(c6_motivos($p))->toBe([])      // conciliado = cobrado con efectivo + compensacion → la condicion financiera se cumple
        ->and(c6_saldo($p))->toBe(0.0);
});

test('C6-03 diferencia del transportador: 129.000 cobrados, 120.000 entregados → 9.000 de Brian, atribuidos a C, sin reabrir Cartera; A y B habilitables', function () {
    $c = c6_inicio();
    $a = c6_pedido($c, 59000.0);
    $b = c6_pedido($c, 40000.0);
    $d = c6_pedido($c, 30000.0);

    foreach ([$a, $b, $d] as $p) {
        c6_cobrar($p, (string) (int) $p['pedido']->base_grand_total);
    }

    $antes = [c6_dinero_cartera($a), c6_dinero_cartera($b), c6_dinero_cartera($d)];

    // Los clientes pagaron todo: Cartera en 0 y 129.000 en custodia ANTES de liquidar.
    expect([c6_saldo($a), c6_saldo($b), c6_saldo($d)])->toBe([0.0, 0.0, 0.0])
        ->and(c6_caja($d)['en_custodia'])->toBe(30000.0);

    // La observacion es obligatoria cuando hay diferencia.
    expect(fn () => c6_liquidar($c, '120000'))->toThrow(Invalida::class);

    $liq = c6_liquidar($c, '120000', ['observacion' => 'Entrego 9.000 menos']);

    expect((float) $liq->saldo_esperado)->toBe(129000.0)
        ->and((float) $liq->valor_liquidado)->toBe(120000.0)
        ->and((float) $liq->diferencia)->toBe(9000.0)
        ->and($liq->resumen['conciliacion'])->toMatchArray(['efectivo' => 120000.0, 'conciliado' => 120000.0, 'pendiente_transportador' => 9000.0, 'atribucion' => 'propuesta']);

    // FIFO (atribucion TECNICA): A y B conciliados por completo; C conciliado 21.000 y 9.000 pendientes del TRANSPORTADOR.
    [$cajaA, $cajaB, $cajaC] = [c6_caja($a), c6_caja($b), c6_caja($d)];

    expect([$cajaA['conciliado'], $cajaA['pendiente_transportador']])->toBe([59000.0, 0.0])
        ->and([$cajaB['conciliado'], $cajaB['pendiente_transportador']])->toBe([40000.0, 0.0])
        ->and([$cajaC['cobrado'], $cajaC['efectivo'], $cajaC['conciliado'], $cajaC['en_custodia'], $cajaC['pendiente_transportador']])->toBe([30000.0, 21000.0, 21000.0, 9000.0, 9000.0]);

    // Los clientes NO vuelven a deber nada: Cartera igual y ninguna aplicacion anulada; la diferencia vive en la cuenta del transportador.
    $apertura = MovimientoCuenta::query()->where('transportadora_id', $c['jeison']->id)->where('idempotency_key', 'liquidacion:'.$liq->id.':diferencia:apertura')->sole();

    expect([c6_saldo($a), c6_saldo($b), c6_saldo($d)])->toBe([0.0, 0.0, 0.0])
        ->and([c6_dinero_cartera($a), c6_dinero_cartera($b), c6_dinero_cartera($d)])->toBe($antes)
        ->and(AplicacionPago::query()->whereIn('order_id', [$a['pedido']->id, $b['pedido']->id, $d['pedido']->id])->whereNotNull('anulada_en')->count())->toBe(0)
        ->and((float) $apertura->importe)->toBe(9000.0)
        ->and($apertura->estado->value)->toBe('abierto')
        ->and($apertura->order_id)->toBeNull();

    // Comision: la diferencia bloquea SOLO a C, y lo dice como pendiente de conciliar (no como saldo del cliente).
    expect(c6_motivos($a))->toBe([])
        ->and(c6_motivos($b))->toBe([])
        ->and(c6_motivos($d))->toBe(['recaudo_pendiente_de_conciliar']);

    // Cuando el transportador entrega los 9.000 en otra liquidacion, la diferencia se concilia con SU recaudo de origen (C), no con otro.
    $liq2 = c6_liquidar($c, '9000');
    $despues = c6_caja($d);

    expect((float) $liq2->diferencia)->toBe(0.0)
        ->and([$despues['conciliado'], $despues['efectivo'], $despues['en_custodia'], $despues['pendiente_transportador']])->toBe([30000.0, 30000.0, 0.0, 0.0])
        ->and(c6_motivos($d))->toBe([])
        ->and(ConciliacionMovimiento::query()->whereIn('liquidacion_id', [$liq->id, $liq2->id])->get()->groupBy('movimiento_id')->map->count()->max())->toBe(2); // C se concilio en DOS liquidaciones
});

test('C6-04 varios pedidos + servicios + diferencia + idempotencia + reasignacion manual + cobro parcial', function () {
    $c = c6_inicio();
    $a = c6_pedido($c, 59000.0, entregar: true);
    $b = c6_pedido($c, 40000.0, entregar: true);
    $d = c6_pedido($c, 30000.0);

    foreach ([$a, $b, $d] as $p) {
        c6_cobrar($p, (string) (int) $p['pedido']->base_grand_total);
    }

    // 129.000 de recaudos − 20.000 de servicios = 109.000 sugeridos; el transportador entrega 100.000.
    $ids = c6_abiertos($c);
    $clave = 'c6-liq-fija-'.Str::uuid();
    $liq = c6_liquidar($c, '100000', ['observacion' => 'Entrego 9.000 menos', 'idempotency_key' => $clave], $ids);
    $filas = ConciliacionMovimiento::query()->where('liquidacion_id', $liq->id)->count();

    expect((float) $liq->saldo_esperado)->toBe(109000.0)
        ->and((float) $liq->diferencia)->toBe(9000.0)
        ->and($liq->resumen['conciliacion'])->toMatchArray(['servicios_a_compensar' => 20000.0, 'compensado' => 20000.0, 'efectivo' => 100000.0, 'conciliado' => 120000.0, 'pendiente_transportador' => 9000.0]);

    // Servicios compensados primero contra SU propio recaudo (misma guia); el efectivo, FIFO: A 49.000, B 30.000, C 21.000.
    [$cajaA, $cajaB, $cajaC] = [c6_caja($a), c6_caja($b), c6_caja($d)];

    expect([$cajaA['efectivo'], $cajaA['compensado'], $cajaA['conciliado']])->toBe([49000.0, 10000.0, 59000.0])
        ->and([$cajaB['efectivo'], $cajaB['compensado'], $cajaB['conciliado']])->toBe([30000.0, 10000.0, 40000.0])
        ->and([$cajaC['efectivo'], $cajaC['compensado'], $cajaC['conciliado'], $cajaC['pendiente_transportador']])->toBe([21000.0, 0.0, 21000.0, 9000.0])
        ->and([c6_saldo($a), c6_saldo($b), c6_saldo($d)])->toBe([0.0, 0.0, 0.0])
        ->and([c6_motivos($a), c6_motivos($b), c6_motivos($d)])->toBe([[], [], ['recaudo_pendiente_de_conciliar']]);

    // Idempotencia: el mismo envio devuelve la MISMA liquidacion sin filas nuevas; otro envio con los mismos movimientos se rechaza.
    $otra = c6_liquidar($c, '100000', ['observacion' => 'Entrego 9.000 menos', 'idempotency_key' => $clave], $ids);

    expect($otra->id)->toBe($liq->id)
        ->and(ConciliacionMovimiento::query()->where('liquidacion_id', $liq->id)->count())->toBe($filas)
        ->and(LiquidacionTransportadora::query()->where('transportadora_id', $c['jeison']->id)->count())->toBe(1);

    $codigo = null;

    try {
        c6_liquidar($c, '100000', ['observacion' => 'x'], $ids);
    } catch (Invalida $e) {
        $codigo = $e->codigo;
    }

    expect($codigo)->toBe(Invalida::MOVIMIENTOS_YA_LIQUIDADOS);

    // Conciliar otra vez lo ya conciliado en la MISMA liquidacion es imposible (UNIQUE) y nunca supera el importe del movimiento.
    $fila = ConciliacionMovimiento::query()->where('liquidacion_id', $liq->id)->firstOrFail();
    $duplicado = false;

    try {
        ConciliacionMovimiento::query()->create(['liquidacion_id' => $liq->id, 'movimiento_id' => $fila->movimiento_id, 'efectivo' => 1, 'compensado' => 0, 'origen' => 'propuesta', 'actor_admin_id' => 1]);
    } catch (QueryException) {
        $duplicado = true;
    }

    $excede = DB::table('bylopez_lg_conciliaciones_movimiento as k')->join('bylopez_lg_cuenta_movimientos as m', 'm.id', '=', 'k.movimiento_id')
        ->where('m.transportadora_id', $c['jeison']->id)->groupBy('k.movimiento_id', 'm.importe')->havingRaw('SUM(k.efectivo + k.compensado) > ABS(m.importe)')->exists();

    expect($duplicado)->toBeTrue()->and($excede)->toBeFalse();

    // ---- Reasignacion manual (antes de confirmar): motivo obligatorio y los MISMOS totales ----
    $c2 = c6_inicio();
    $x = c6_pedido($c2, 20000.0);
    $y = c6_pedido($c2, 20000.0);
    c6_cobrar($x, '20000');
    c6_cobrar($y, '20000');
    [$mx, $my] = MovimientoCuenta::query()->where('transportadora_id', $c2['jeison']->id)->where('concepto', 'recaudo')->orderBy('id')->pluck('id')->all();
    $manual = [['movimiento_id' => $mx, 'efectivo' => '10000'], ['movimiento_id' => $my, 'efectivo' => '20000']];   // la propuesta era X 20.000 / Y 10.000

    $err = fn (array $extra) => (function () use ($c2, $extra) {
        try {
            c6_liquidar($c2, '30000', ['observacion' => 'Entrego 10.000 menos'] + $extra);
        } catch (Invalida $e) {
            return $e->codigo;
        }
    })();

    expect($err(['atribucion' => $manual]))->toBe(Invalida::OBSERVACION_REQUERIDA)                                                            // sin motivo
        ->and($err(['atribucion' => [['movimiento_id' => $mx, 'efectivo' => '5000'], ['movimiento_id' => $my, 'efectivo' => '20000']], 'motivo_atribucion' => 'x']))->toBe(Invalida::DATOS_INVALIDOS)   // crea/destruye dinero
        ->and(MovimientoCuenta::query()->where('transportadora_id', $c2['jeison']->id)->where('estado', 'liquidado')->count())->toBe(0);          // nada se escribio

    $liq2 = c6_liquidar($c2, '30000', ['observacion' => 'Entrego 10.000 menos', 'atribucion' => $manual, 'motivo_atribucion' => 'Brian dijo que pago primero la guia de Y']);
    $x1 = c6_caja($x);
    $y1 = c6_caja($y);

    expect($liq2->resumen['conciliacion']['atribucion'])->toBe('manual')
        ->and([$x1['conciliado'], $x1['pendiente_transportador'], $y1['conciliado'], $y1['pendiente_transportador']])->toBe([10000.0, 10000.0, 20000.0, 0.0])
        ->and(ConciliacionMovimiento::query()->where('liquidacion_id', $liq2->id)->pluck('origen')->unique()->all())->toBe(['manual'])
        ->and(ConciliacionMovimiento::query()->where('liquidacion_id', $liq2->id)->value('motivo'))->toContain('Brian dijo')
        ->and((float) ConciliacionMovimiento::query()->where('liquidacion_id', $liq2->id)->sum('efectivo'))->toBe(30000.0)
        ->and([c6_saldo($x), c6_saldo($y)])->toBe([0.0, 0.0]);

    // ---- Cobro PARCIAL: esperado 59.000, el cliente paga 30.000 → recaudo 30.000 (no 59.000), custodia 30.000 y el cliente aun debe 29.000 ----
    $c3 = c6_inicio();
    $f = c6_pedido($c3, 59000.0);
    c6_cobrar($f, '30000');
    $recaudo = MovimientoCuenta::query()->where('guia_id', $f['guia']->id)->where('concepto', 'recaudo')->sole();
    $cajaF = c6_caja($f);

    expect((float) $f['guia']->recaudo_esperado)->toBe(59000.0)
        ->and((float) $recaudo->importe)->toBe(30000.0)
        ->and([$cajaF['cobrado'], $cajaF['en_custodia']])->toBe([30000.0, 30000.0])
        ->and(c6_saldo($f))->toBe(29000.0);
});
