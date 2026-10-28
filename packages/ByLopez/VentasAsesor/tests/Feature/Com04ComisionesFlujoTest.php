<?php

use ByLopez\Logistica\Services\Despachos\CancelarPendienteService;
use ByLopez\Logistica\Services\Entregas\ValidarEntregaService;
use ByLopez\VentasAsesor\Models\ComisionAcuerdo;
use ByLopez\VentasAsesor\Models\ComisionLinea;
use ByLopez\VentasAsesor\Services\Comisiones\AcuerdosComisionService;
use ByLopez\VentasAsesor\Services\Comisiones\ConsultaComisionesService;
use ByLopez\VentasAsesor\Services\Comisiones\ElegibilidadComisionService;
use ByLopez\VentasAsesor\Services\Comisiones\EvaluarComisionesService;
use ByLopez\VentasAsesor\Services\Comisiones\LiquidacionesComisionService;
use ByLopez\VentasAsesor\Services\Venta\ConfirmarVentaService;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\Sales\Models\Order;

require_once __DIR__.'/../helpersCom4.php';

uses(TestCase::class);

/*
| COMISIONES de punta a punta (jornada 2026-10-28), con ventas REALES (Nueva venta → ConfirmarVentaService), pago por Cartera, entrega por
| Logistica y liquidacion/abonos al asesor. Solo lpz_saas_testing. Cada prueba arma su propia asesora, producto y acuerdo, asi que ninguna
| depende del total de la base ni de otra prueba. Resultados esperados calculados a mano.
|
| Lo unico sembrado como DATO (no como estado financiero): el acuerdo de comision (el servicio impide por diseno las vigencias
| retroactivas, y el ejemplo necesita UN solo umbral en el mes), el estado confirmado del costo de la referencia y el producto.
*/

test('COM4-1 ejemplo exacto 998 + 5: 2 unidades completan el umbral, 3 comisionan = 3 × (10.000 − 6.000) × 10 % = 1.200; habilita solo con entrega validada + cobro confirmado + dinero', function () {
    $e = com4_escenario();
    com4_vender($e, 998);                    // acumulado inicial del mes: 998
    $pedido = com4_vender($e, 5);

    $estimada = com4_pantalla($pedido);
    expect($estimada['linea']['estado'])->toBe('estimada')
        ->and($estimada['linea']['comisionables'])->toBe(3)
        ->and((float) $estimada['linea']['estimada'])->toBe(1200.0)
        ->and($estimada['condiciones'])->toContain('unidades_sin_despachar');

    // Pagado pero sin entrega: no se habilita.
    com4_pagar($pedido);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $pedido->id);
    expect(com4_linea($pedido)->estado)->toBe('estimada');

    // Entregado pero SIN validar: no se habilita.
    $envio = com4_entregar($pedido, validar: false);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $pedido->id);
    expect(com4_linea($pedido)->estado)->toBe('estimada')->and(com4_pantalla($pedido)['condiciones'])->toContain('envio_sin_validar');

    // Entrega validada + cobro confirmado + recogida (el pago confirmado es la prueba del dinero): habilitada.
    app(ValidarEntregaService::class)->validar(f03b_admin('all'), $envio, 'com4-val-'.Str::uuid(), null);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $pedido->id);
    $l = com4_linea($pedido);

    expect($l->estado)->toBe('habilitada')->and((int) $l->unidades_comisionables)->toBe(3)->and((float) $l->monto)->toBe(1200.0);
});

test('COM4-2 liquidacion y abonos: $500 (saldo $700), reintento sin duplicar, no se abona mas que el saldo, $700 (saldo $0)', function () {
    $e = com4_escenario();
    com4_vender($e, 998);
    $pedido = com4_vender($e, 5);
    com4_pagar($pedido);
    com4_entregar($pedido);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $pedido->id);
    expect(com4_linea($pedido)->estado)->toBe('habilitada');

    $admin = f03b_admin('all');
    $servicio = app(LiquidacionesComisionService::class);
    $liq = $servicio->crearBorrador($admin, (int) $e['asesor']->id, now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString(), [], 'COM4', 'com4-liq-'.Str::uuid());
    expect((float) $liq->total)->toBe(1200.0);

    $liq = $servicio->aprobar($admin, (int) $liq->id);
    $abonar = fn (string $monto, string $clave) => $servicio->registrarAbono($admin, (int) $liq->id, ['monto' => $monto, 'fecha_pago' => now()->toDateString(), 'medio' => 'transferencia', 'referencia' => 'COM4'], null, $clave);
    $saldo = fn () => (float) $servicio->saldo($liq->fresh())->toFloat();

    $k = 'com4-abono-'.Str::uuid();
    $abonar('500', $k);
    expect($saldo())->toBe(700.0);

    $abonar('500', $k);   // reintento con la MISMA clave
    expect(DB::table('bylopez_va_comision_abonos')->where('liquidacion_id', $liq->id)->where('tipo', 'abono')->count())->toBe(1)->and($saldo())->toBe(700.0);

    expect(com4_error(fn () => $abonar('800', 'com4-abono-'.Str::uuid())))->not->toBeNull()->and($saldo())->toBe(700.0);

    $abonar('700', 'com4-abono-'.Str::uuid());
    expect($saldo())->toBe(0.0)->and(com4_error(fn () => $abonar('1', 'com4-abono-'.Str::uuid())))->not->toBeNull();
});

test('COM4-3 independencia entre asesoras y meses; un cambio de porcentaje programado no toca lo ya fijado', function () {
    $a = com4_escenario('10', 10);
    $b = com4_escenario('10', 10);                 // otra asesora, otro producto, mismo mes

    com4_vender($a, 8);                             // A acumula 8 (umbral 10)
    $bPedido = com4_vender($b, 5);                  // B acumula 5: las ventas de A no la tocan
    $aPedido = com4_vender($a, 5);                  // A: 8 + 5 = 13 → 2 completan, 3 comisionan, pese a lo que vendio B

    expect(com4_pantalla($bPedido)['linea']['comisionables'])->toBe(0)
        ->and(com4_pantalla($aPedido)['linea']['comisionables'])->toBe(3)
        ->and((float) com4_pantalla($aPedido)['linea']['estimada'])->toBe(1200.0);

    // Cambio de porcentaje: se programa hacia ADELANTE (nunca retroactivo) y no modifica la linea ya fijada.
    expect(com4_linea($aPedido)->porcentaje)->toBe('10.0000');
    app(AcuerdosComisionService::class)->programar(f03b_admin('all'), (int) $a['asesor']->id, [
        'porcentaje' => '20', 'umbral' => '10', 'vigente_desde' => now()->addDay()->toDateTimeString(), 'motivo' => 'COM4 cambio hacia adelante',
    ]);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $aPedido->id);

    expect(com4_linea($aPedido)->porcentaje)->toBe('10.0000')
        ->and((float) com4_pantalla($aPedido)['linea']['estimada'])->toBe(1200.0)
        // La vigencia retroactiva se rechaza.
        ->and(com4_error(fn () => app(AcuerdosComisionService::class)->programar(f03b_admin('all'), (int) $a['asesor']->id, [
            'porcentaje' => '30', 'umbral' => '10', 'vigente_desde' => now()->subDay()->toDateTimeString(), 'motivo' => 'retroactivo',
        ])))->not->toBeNull();

    // Mes siguiente: el acumulado reinicia (politica vigente: el umbral es mensual).
    Carbon::setTestNow(now()->addMonthNoOverflow()->startOfMonth()->addDays(2)->setTime(10, 0));

    try {
        f03b_stock($a['variante'], 100);
        $nuevo = com4_vender($a, 5);
        expect(MesComercial::de((string) $nuevo->created_at))->toBe(now()->format('Y-m'))
            ->and(com4_pantalla($nuevo)['linea']['comisionables'])->toBe(0);   // 5 < 10 en el mes nuevo
    } finally {
        Carbon::setTestNow();
    }
});

test('COM4-4 pedido mixto que cruza el umbral con bases distintas: politica D3 pendiente → bloqueo explicito, nunca se inventa el reparto', function () {
    $a = com4_escenario('10', 10);
    com4_vender($a, 8);                             // acumulado 8
    $dama = f03b_configurable(12000.0);
    $varDama = $dama->variants()->first();
    f03b_stock($varDama, 50);
    com_costo($varDama, 6000.0);                    // base 6.000 (la otra referencia tiene base 4.000)

    $borrador = f03f_borradorListo($a['cliente']->id, $a['asesor'], [[$a['configurable'], $a['variante'], 3], [$dama, $varDama, 3]]);
    $r = f03f_sinCommitPrematuro(fn () => app(ConfirmarVentaService::class)->confirmar($borrador->id, $a['asesor'], f03f_total($borrador)));
    $pedido = Order::query()->findOrFail($r->orderId);
    DB::table('bylopez_va_order_attributions')->where('order_id', $pedido->id)->update(['commercial_owner_type' => 'advisor', 'advisor_id' => $a['asesor']->id]);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $pedido->id);

    com4_pagar($pedido);
    com4_entregar($pedido);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $pedido->id);

    // Cumple entrega, cobro y dinero, pero el reparto del cruce entre bases distintas NO esta aprobado: queda estimada y marcada.
    $lineas = ComisionLinea::query()->where('order_id', $pedido->id)->get();

    expect($lineas)->toHaveCount(2)
        ->and($lineas->pluck('estado')->unique()->all())->toBe(['estimada'])
        ->and($lineas->pluck('alerta')->unique()->all())->toBe(['politica_pendiente_pedido_mixto'])
        ->and(app(ElegibilidadComisionService::class)->dePedido((int) $pedido->id)['motivos'])->toBe([]);
});

test('COM4-5 costo sin confirmar, sin fecha de inicio o venta anterior al inicio: no se habilita', function () {
    // Costo sin estado confirmado al vender: la linea queda estimada con «costo_sin_estado» aunque todo lo demas se cumpla.
    $a = com4_escenario('10', 10, costoConfirmado: false);
    $pedido = com4_vender($a, 5);
    com4_pagar($pedido);
    com4_entregar($pedido);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $pedido->id);

    expect(com4_linea($pedido)->estado)->toBe('estimada')->and(com4_linea($pedido)->alerta)->toBe('costo_sin_estado');

    // Sin fecha de inicio configurada: ninguna comision se habilita (nada retroactivo ni por defecto).
    $b = com4_escenario('10', 10);
    $pedidoB = com4_vender($b, 5);
    com4_pagar($pedidoB);
    com4_entregar($pedidoB);
    config(['ventasasesor.comisiones.fecha_inicio' => null]);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $pedidoB->id);

    expect(com4_linea($pedidoB)->estado)->toBe('estimada')
        ->and(app(ElegibilidadComisionService::class)->dePedido((int) $pedidoB->id)['motivos'])->toContain('sin_fecha_inicio');

    // Fecha de inicio POSTERIOR a la venta: tampoco.
    config(['ventasasesor.comisiones.fecha_inicio' => now()->addDays(5)->toDateString()]);
    expect(app(ElegibilidadComisionService::class)->dePedido((int) $pedidoB->id)['motivos'])->toContain('anterior_a_inicio');

    // Con la fecha correcta, se habilita.
    config(['ventasasesor.comisiones.fecha_inicio' => '2000-01-01']);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $pedidoB->id);
    expect(com4_linea($pedidoB)->estado)->toBe('habilitada');
});

test('COM4-6 cancelar el pendiente de un pedido anterior desplaza el tramo: la linea ya habilitada no se recalcula sola (D5 pendiente)', function () {
    $a = com4_escenario('10', 10);
    $primero = com4_vender($a, 8);                  // acumulado 8, nada entregado todavia
    $segundo = com4_vender($a, 5);                  // 3 comisionables
    com4_pagar($segundo);
    com4_entregar($segundo);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $segundo->id);
    $antes = com4_linea($segundo);

    expect($antes->estado)->toBe('habilitada')->and((int) $antes->unidades_comisionables)->toBe(3)->and((float) $antes->monto)->toBe(1200.0);

    // Se cancela todo el pendiente del PRIMER pedido (8 unidades): el acumulado baja y el tramo del segundo se desplaza.
    app(CancelarPendienteService::class)->cancelar(f03b_admin('all'), (int) $primero->id, 'COM4 cancelacion que desplaza el tramo');
    app(EvaluarComisionesService::class)->evaluarPedido((int) $primero->id);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $segundo->id);
    $despues = com4_linea($segundo)->fresh();

    // Nada se recalcula ni se descuenta automaticamente: conserva lo congelado y queda marcada en revision.
    expect((int) $despues->unidades_comisionables)->toBe(3)->and((float) $despues->monto)->toBe(1200.0)
        ->and($despues->alerta)->toBe('politica_pendiente_desplazamiento');
});

test('COM4-7 la asesora ve solo sus comisiones y sin costos; las rutas administrativas le estan cerradas', function () {
    $a = com4_escenario('10', 10);
    $b = com4_escenario('10', 10);
    $pedidoA = com4_vender($a, 5);
    $pedidoB = com4_vender($b, 5);
    $consulta = app(ConsultaComisionesService::class);

    $propio = $consulta->detallePedido($a['asesor'], (int) $pedidoA->id);

    expect($propio)->not->toBeNull()
        ->and($propio['ve_costos'])->toBeFalse()
        ->and($propio['lineas'][0])->not->toHaveKey('costo')->and($propio['lineas'][0])->not->toHaveKey('base')->and($propio['lineas'][0])->not->toHaveKey('porcentaje')
        // El pedido de OTRA asesora no se revela.
        ->and($consulta->detallePedido($a['asesor'], (int) $pedidoB->id))->toBeNull()
        ->and($consulta->alcance($a['asesor']))->toBe((int) $a['asesor']->id);

    // Rutas administrativas: la asesora (sin las claves) recibe 401 del Bouncer y no se escribe nada.
    $this->actingAs($a['asesor'], 'admin')->post(route('admin.ventas_asesor.comisiones.evaluar'))->assertStatus(401);
    $this->actingAs($a['asesor'], 'admin')->post(route('admin.ventas_asesor.comisiones.liquidaciones.store'), ['asesor_id' => $a['asesor']->id])->assertStatus(401);
    $this->actingAs($a['asesor'], 'admin')->post(route('admin.ventas_asesor.comisiones.acuerdos.store', $a['asesor']->id), ['porcentaje' => 99])->assertStatus(401);
    expect(ComisionAcuerdo::query()->where('asesor_admin_id', $a['asesor']->id)->count())->toBe(1);
});

test('COM4-8 la reevaluacion es AUTOMATICA: cada hecho (cobro, entrega validada) marca el pedido y la linea se habilita SIN pulsar «evaluar»', function () {
    $a = com4_escenario('10', 10);
    $pedido = com4_vender($a, 5);
    DB::table('bylopez_va_comision_reevaluaciones')->where('order_id', $pedido->id)->delete();
    $marca = fn () => DB::table('bylopez_va_comision_reevaluaciones')->where('order_id', $pedido->id)->first();

    com4_pagar($pedido);                                    // hecho 1: pago confirmado
    $tras1 = $marca();
    expect($tras1)->not->toBeNull()->and(com4_linea($pedido)->estado)->toBe('estimada');

    $envio = com4_entregar($pedido, validar: false);       // hecho 2: entrega registrada (aun sin validar)
    $tras2 = $marca();
    expect((int) $tras2->version)->toBeGreaterThan((int) $tras1->version)->and(com4_linea($pedido)->estado)->toBe('estimada');

    app(ValidarEntregaService::class)->validar(f03b_admin('all'), $envio, 'com4-val-'.Str::uuid(), null);   // hecho 3: validacion
    $tras3 = $marca();

    // La bandeja registro el hecho y la reevaluacion ya se proceso (cola sync tras el commit): habilitada sin accion manual.
    expect((int) $tras3->version)->toBeGreaterThan((int) $tras2->version)
        ->and((int) $tras3->version_procesada)->toBe((int) $tras3->version)
        ->and($tras3->origen)->toBe('validacion_entrega')
        ->and(com4_linea($pedido)->estado)->toBe('habilitada');
});
