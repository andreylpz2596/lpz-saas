<?php

require_once __DIR__.'/../Support/QaCartera.php';

use ByLopez\CorteConfeccion\Exceptions\CarteraConflictoException;
use ByLopez\CorteConfeccion\Models\CarteraAplicacion;
use ByLopez\CorteConfeccion\Services\CarteraLiquidacionService;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenTarifaService;
use ByLopez\CorteConfeccion\Support\CarteraConfig;
use ByLopez\CorteConfeccion\Support\Dinero;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Webkul\Product\Models\Product;

/*
|--------------------------------------------------------------------------
| Cartera de prestadores: cifras de control del plan (Jairo) y reglas de cuenta, abonos, anticipos y cortes.
|--------------------------------------------------------------------------
*/

test('formatos colombianos y aritmetica exacta (sin flotantes)', function () {
    expect(Dinero::parsear('$ 1.234.567,89'))->toBe('1234567.89')
        ->and(Dinero::parsear('1.500.000'))->toBe('1500000.00')
        ->and(Dinero::parsear('1,234,567.50'))->toBe('1234567.50')
        ->and(Dinero::parsear('20000'))->toBe('20000.00')
        ->and(Dinero::parsear('70.000'))->toBe('70000.00')
        ->and(Dinero::parsear('12,5'))->toBe('12.50')
        ->and(Dinero::parsear('abc'))->toBeNull()
        ->and(Dinero::parsear(''))->toBeNull()
        ->and(Dinero::por('1450.0000', 600))->toBe('870000.00')
        ->and(Dinero::por('0.1', 3))->toBe('0.30')
        ->and(Dinero::sumar('0.10', '0.20'))->toBe('0.30')
        ->and(Dinero::formatear('1015000.00'))->toBe('$ 1.015.000')
        ->and(Dinero::formatear('-200000.00'))->toBe('− $ 200.000');
});

test('cifras de control de Jairo: recibido, pendiente, estimado, abono y faltante', function () {
    $e = cc_escenario();
    $cuenta = cc_cuentas()->cuenta($e['jairo']);

    expect($cuenta['trabajo_reconocido'])->toBe('870000.00')
        ->and($cuenta['trabajo_pendiente'])->toBe('145000.00')
        ->and($cuenta['trabajo_estimado'])->toBe('1015000.00')
        ->and($cuenta['cantidad_reconocida'])->toBe(600)
        ->and($cuenta['cantidad_pendiente'])->toBe(100);

    cc_mov($e['jairo'], 'abono', 70000, ['aplicaciones' => [['destino' => 'orden', 'id' => $e['ordA']->id, 'valor' => '70000']]]);
    $cuenta = cc_cuentas()->cuenta($e['jairo']);

    expect($cuenta['total_abonado'])->toBe('70000.00')
        ->and($cuenta['por_pagar_recibido'])->toBe('800000.00')
        ->and($cuenta['por_pagar_estimado'])->toBe('945000.00')
        ->and($cuenta['a_favor_bylopez'])->toBeFalse();
});

test('prestamo de 1.500.000 y descuento de 500.000 en el corte: 300.000 / 445.000 / prestamo 1.000.000 y abonado sigue en 70.000', function () {
    $e = cc_escenario();
    cc_mov($e['jairo'], 'abono', 70000, ['aplicaciones' => [['destino' => 'orden', 'id' => $e['ordA']->id, 'valor' => '70000']]]);
    $prestamo = cc_mov($e['jairo'], 'prestamo', 1500000);
    $movsAntes = cc_count_movs();

    $previa = app(CarteraLiquidacionService::class)->previsualizar($e['jairo'], '500000', $prestamo->id);
    expect($previa['errores'])->toBe([])
        ->and($previa['queda_por_pagar'])->toBe('300000.00')
        ->and($previa['prestamo_pendiente_despues'])->toBe('1000000.00');

    $liq = app(CarteraLiquidacionService::class)->confirmar($e['jairo'], ['descuento' => '500000', 'prestamo_id' => $prestamo->id, 'observacion' => 'Acordado', 'idempotency_key' => (string) Str::uuid()]);
    $cuenta = cc_cuentas()->cuenta($e['jairo']);

    // Confirmar el corte NO crea dinero nuevo.
    expect(cc_count_movs())->toBe($movsAntes)
        ->and($liq->codigo)->toStartWith('LIQ-')
        ->and($cuenta['por_pagar_recibido'])->toBe('300000.00')
        ->and($cuenta['por_pagar_estimado'])->toBe('445000.00')
        ->and($cuenta['prestamo_pendiente'])->toBe('1000000.00')
        ->and($cuenta['total_prestado'])->toBe('1500000.00')
        ->and($cuenta['descontado'])->toBe('500000.00')
        ->and($cuenta['total_abonado'])->toBe('70000.00');

    // El descuento queda en ambos historiales: aplicacion ligada al prestamo, a la orden y a la liquidacion.
    $aplicacion = CarteraAplicacion::query()->where('liquidacion_id', $liq->id)->first();
    expect($aplicacion->movimiento_id)->toBe($prestamo->id)
        ->and($aplicacion->orden_confeccion_id)->toBe($e['ordA']->id)
        ->and($aplicacion->valor)->toBe('500000.00');
});

test('el descuento arranca en cero y no supera el prestamo pendiente ni el saldo ya recibido (nunca la estimacion pendiente)', function () {
    $e = cc_escenario();
    $prestamo = cc_mov($e['jairo'], 'prestamo', 2000000);
    $servicio = app(CarteraLiquidacionService::class);

    expect($servicio->previsualizar($e['jairo'])['descuento'])->toBe('0.00');

    // Saldo recibido 870.000; estimado total 1.015.000: no se puede descontar 900.000 aunque el estimado lo cubriera.
    $previa = $servicio->previsualizar($e['jairo'], '900000', $prestamo->id);
    expect($previa['errores'])->not->toBe([]);
    expect(fn () => $servicio->confirmar($e['jairo'], ['descuento' => '900000', 'prestamo_id' => $prestamo->id, 'idempotency_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);

    $corto = cc_mov($e['jairo'], 'prestamo', 100000);
    expect(fn () => $servicio->confirmar($e['jairo'], ['descuento' => '150000', 'prestamo_id' => $corto->id, 'idempotency_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
});

test('caso aparte: trabajo 2.000.000 y abonos 2.200.000 = 200.000 a favor de ByLopez', function () {
    $e = cc_escenario('2000', 1000, 0);
    $cuenta = cc_cuentas()->cuenta($e['jairo']);
    expect($cuenta['trabajo_reconocido'])->toBe('2000000.00');

    cc_mov($e['jairo'], 'abono', 2200000, ['aplicar_automatico' => true]);
    $cuenta = cc_cuentas()->cuenta($e['jairo']);

    expect($cuenta['por_pagar_estimado'])->toBe('-200000.00')
        ->and($cuenta['a_favor_bylopez'])->toBeTrue()
        ->and($cuenta['anticipo_disponible'])->toBe('200000.00');
});

test('abono antes de recibir: 215.000 abonados, OF-0012 mantiene 800.000 y OF-0015 queda cubierta con 100 prendas aun pendientes', function () {
    $e = cc_escenario();
    cc_mov($e['jairo'], 'abono', 215000, ['aplicaciones' => [
        ['destino' => 'orden', 'id' => $e['ordA']->id, 'valor' => '70000'],
        ['destino' => 'orden', 'id' => $e['ordB']->id, 'valor' => '145000'],
    ]]);
    $cuenta = cc_cuentas()->cuenta($e['jairo']);
    $a = collect($cuenta['ordenes'])->firstWhere('orden_id', $e['ordA']->id);
    $b = collect($cuenta['ordenes'])->firstWhere('orden_id', $e['ordB']->id);

    expect($cuenta['total_abonado'])->toBe('215000.00')
        ->and($a['saldo_recibido'])->toBe('800000.00')
        ->and($b['anticipo_ligado_pendiente'])->toBe('145000.00')
        ->and($b['saldo_estimado'])->toBe('0.00')
        ->and($b['cantidad_pendiente'])->toBe(100)
        ->and($cuenta['por_pagar_recibido'])->toBe('800000.00')   // no resta el anticipo reservado a otra orden
        ->and($cuenta['por_pagar_estimado'])->toBe('800000.00');

    // Al recibir las 100: se reconoce el trabajo y el anticipo se concilia UNA sola vez; la cuenta no cambia.
    cc_recibir($e['ordB']);
    $cuenta = cc_cuentas()->cuenta($e['jairo']);
    $b = collect($cuenta['ordenes'])->firstWhere('orden_id', $e['ordB']->id);

    expect($b['cantidad_pendiente'])->toBe(0)
        ->and($b['valor_reconocido'])->toBe('145000.00')
        ->and($b['saldo_recibido'])->toBe('0.00')
        ->and($b['estado_pago'])->toBe('pagada')
        ->and($cuenta['por_pagar_recibido'])->toBe('800000.00')
        ->and($cuenta['por_pagar_estimado'])->toBe('800000.00')
        ->and($cuenta['total_abonado'])->toBe('215000.00');
});

test('si bodega acepta menos de lo estimado, el credito se muestra en esa orden y no se traslada solo a otra', function () {
    $e = cc_escenario();
    cc_mov($e['jairo'], 'abono', 145000, ['aplicaciones' => [['destino' => 'orden', 'id' => $e['ordB']->id, 'valor' => '145000']]]);

    // Bodega recibe 0 buenas (las 100 salieron defectuosas): el trabajo reconocido de OF-B es 0.
    cc_recibir($e['ordB'], 0);
    $cuenta = cc_cuentas()->cuenta($e['jairo']);
    $b = collect($cuenta['ordenes'])->firstWhere('orden_id', $e['ordB']->id);
    $a = collect($cuenta['ordenes'])->firstWhere('orden_id', $e['ordA']->id);

    expect($b['credito'])->toBe('145000.00')
        ->and($a['aplicado'])->toBe('0.00')                 // no se aplico a la otra orden
        ->and($a['saldo_recibido'])->toBe('870000.00')
        ->and($cuenta['creditos'])->toBe('145000.00')
        ->and($cuenta['por_pagar_recibido'])->toBe('725000.00'); // 870.000 - 145.000 de credito, visible y explicado
});

test('un anticipo se aplica despues sin dinero nuevo y conserva el vinculo al abono original', function () {
    $e = cc_escenario();
    $abono = cc_mov($e['jairo'], 'abono', 300000);   // sin aplicar: queda como anticipo
    $movsAntes = cc_count_movs();

    expect(cc_cuentas()->cuenta($e['jairo'])['anticipo_disponible'])->toBe('300000.00');

    cc_movs()->aplicar($abono, [['destino' => 'orden', 'id' => $e['ordA']->id, 'valor' => '300000']]);
    $cuenta = cc_cuentas()->cuenta($e['jairo']);

    expect(cc_count_movs())->toBe($movsAntes)                       // $0 de dinero nuevo
        ->and($cuenta['anticipo_disponible'])->toBe('0.00')
        ->and($cuenta['total_abonado'])->toBe('300000.00')           // no aumenta al aplicar
        ->and(CarteraAplicacion::query()->where('movimiento_id', $abono->id)->count())->toBe(1);

    expect(fn () => cc_movs()->aplicar($abono, [['destino' => 'orden', 'id' => $e['ordA']->id, 'valor' => '600000']]))->toThrow(ValidationException::class);
});

test('una referencia Sin tarifa no vale cero: el corte la excluye, la lista y se marca incompleto', function () {
    $e = cc_escenario();
    DB::table('bylopez_cc_orden_tarifas')->where('orden_confeccion_id', $e['ordB']->id)->update(['tarifa_unitaria' => null]);

    $cuenta = cc_cuentas()->cuenta($e['jairo']);
    expect($cuenta['sin_tarifa'])->not->toBe([])
        ->and($cuenta['trabajo_pendiente'])->toBe('0.00');

    cc_recibir($e['ordB']);
    $previa = app(CarteraLiquidacionService::class)->previsualizar(cc_escenario_taller($e));

    expect($previa['completo'])->toBeFalse()
        ->and(collect($previa['sin_tarifa'])->pluck('codigo')->all())->toContain($e['ordB']->codigo)
        ->and(collect($previa['incluidas'])->pluck('codigo')->all())->not->toContain($e['ordB']->codigo);
});

function cc_escenario_taller(array $e)
{
    return $e['jairo'];
}

test('arranque: lo recibido antes del arranque lo cubre el saldo inicial y lo posterior de la misma orden si cuenta, sin doble conteo', function () {
    $admin = qa_admin('Admin Arranque '.uniqid());
    $p = Product::factory()->simple()->create();
    $corte = qa_orden_corte_recibida([['product' => $p, 'cantidad_recibida' => 400, 'cantidad_defectuosa' => 0]]);
    Auth::guard('admin')->setUser($admin);
    $jairo = qa_taller();
    $servicio = app(OrdenConfeccionService::class);
    $orden = $servicio->create(['orden_corte_id' => $corte->id, 'taller_id' => $jairo->id, 'detalles' => [['product_id' => $p->id, 'talla' => '', 'cantidad_entregada' => 300]]]);
    $combo = app(OrdenTarifaService::class)->combinaciones($orden->fresh())['lineas'][0];
    app(OrdenTarifaService::class)->pactar($orden->fresh(), $combo['tipo_tela_id'], $combo['attribute_family_id'], '1000', 'Prueba', $admin->id);
    $det = $orden->detalles()->first();

    // Entrega 1 (100): recibida hace 5 dias, ANTES del arranque. Entrega 2 (100): recibida hoy, DESPUES.
    $servicio->reportarTaller($orden->fresh(), ['detalles' => [['id' => $det->id, 'cantidad' => 100]]]);
    cc_recibir($orden);
    DB::table('bylopez_cc_ordenes_confeccion_retornos')->where('orden_confeccion_id', $orden->id)->update(['fecha_recepcion_bodega' => now()->subDays(5)]);
    $servicio->reportarTaller($orden->fresh(), ['detalles' => [['id' => $det->id, 'cantidad' => 100]]]);
    cc_recibir($orden);

    Auth::guard('admin')->setUser($admin);
    CarteraConfig::establecerFechaArranque(now()->subDays(2)->toDateString());

    // Deuda previa por trabajo cargada UNA vez como saldo inicial (cubre la entrega 1).
    cc_mov($jairo, 'saldo_inicial_trabajo', 100000, ['fecha_efectiva' => now()->subDays(3)->toDateString()]);
    $cuenta = cc_cuentas()->cuenta($jairo);

    expect($cuenta['trabajo_reconocido'])->toBe('100000.00')        // solo la entrega 2: nada desaparece ni se cuenta dos veces
        ->and($cuenta['deuda_previa_pendiente'])->toBe('100000.00')
        ->and($cuenta['por_pagar_recibido'])->toBe('200000.00');    // 100.000 previa + 100.000 posterior

    // Un abono posterior se aplica a la deuda previa primero.
    cc_mov($jairo, 'abono', 100000, ['aplicar_automatico' => true]);
    $cuenta = cc_cuentas()->cuenta($jairo);
    expect($cuenta['deuda_previa_pendiente'])->toBe('0.00')->and($cuenta['por_pagar_recibido'])->toBe('100000.00');

    // Una segunda deuda previa vigente se rechaza (no se vuelve a cargar lo ya incluido).
    expect(fn () => cc_mov($jairo, 'saldo_inicial_trabajo', 5000, ['fecha_efectiva' => now()->subDays(3)->toDateString()]))->toThrow(ValidationException::class);
    // Un abono anterior al arranque no se acepta como transferencia nueva.
    expect(fn () => cc_mov($jairo, 'abono', 5000, ['fecha_efectiva' => now()->subDays(4)->toDateString()]))->toThrow(ValidationException::class);
});

test('sin fecha de arranque no se registran movimientos', function () {
    $e = cc_escenario('1450', 600, 100, false);
    expect(fn () => cc_mov($e['jairo'], 'abono', 1000))->toThrow(ValidationException::class);
});

test('idempotencia: misma llave y mismo contenido devuelve el mismo movimiento; contenido distinto es conflicto', function () {
    $e = cc_escenario();
    $llave = (string) Str::uuid();

    $a = cc_mov($e['jairo'], 'abono', 50000, ['idempotency_key' => $llave]);
    $b = cc_mov($e['jairo'], 'abono', 50000, ['idempotency_key' => $llave]);

    expect($b->id)->toBe($a->id);
    expect(DB::table('bylopez_cc_cartera_movimientos')->where('idempotency_key', $llave)->count())->toBe(1);
    expect(fn () => cc_mov($e['jairo'], 'abono', 60000, ['idempotency_key' => $llave]))->toThrow(CarteraConflictoException::class);
});

test('reverso conserva la operacion original con motivo y responsable; un prestamo con descuento no se reversa solo', function () {
    $e = cc_escenario();
    $abono = cc_mov($e['jairo'], 'abono', 70000, ['aplicaciones' => [['destino' => 'orden', 'id' => $e['ordA']->id, 'valor' => '70000']]]);

    cc_movs()->reversar($abono, 'Error de digitacion');
    $abono = $abono->fresh();

    expect($abono->estado)->toBe('reversado')
        ->and($abono->motivo_reverso)->toBe('Error de digitacion')
        ->and($abono->reversado_por)->toBe($e['admin']->id)
        ->and(cc_cuentas()->cuenta($e['jairo'])['total_abonado'])->toBe('0.00')
        ->and(cc_cuentas()->cuenta($e['jairo'])['por_pagar_recibido'])->toBe('870000.00');

    expect(fn () => cc_movs()->reversar($abono, 'otra vez'))->toThrow(ValidationException::class);

    $prestamo = cc_mov($e['jairo'], 'prestamo', 1000000);
    app(CarteraLiquidacionService::class)->confirmar($e['jairo'], ['descuento' => '100000', 'prestamo_id' => $prestamo->id, 'idempotency_key' => (string) Str::uuid()]);
    expect(fn () => cc_movs()->reversar($prestamo, 'no'))->toThrow(ValidationException::class);
});

test('reversar un corte devuelve el valor al prestamo y al saldo de trabajo', function () {
    $e = cc_escenario();
    $prestamo = cc_mov($e['jairo'], 'prestamo', 1500000);
    $liq = app(CarteraLiquidacionService::class)->confirmar($e['jairo'], ['descuento' => '500000', 'prestamo_id' => $prestamo->id, 'idempotency_key' => (string) Str::uuid()]);
    expect(cc_cuentas()->cuenta($e['jairo'])['prestamo_pendiente'])->toBe('1000000.00');

    app(CarteraLiquidacionService::class)->reversar($liq, 'Se acordo otro monto');

    $cuenta = cc_cuentas()->cuenta($e['jairo']);
    expect($cuenta['prestamo_pendiente'])->toBe('1500000.00')
        ->and($cuenta['por_pagar_recibido'])->toBe('870000.00')
        ->and($liq->fresh()->estado)->toBe('reversado');
});

test('estado de produccion y de pago son independientes: orden cerrada con saldo sigue visible; cerrada y pagada al completar', function () {
    $e = cc_escenario();
    $a = fn () => collect(cc_cuentas()->cuenta($e['jairo'])['ordenes'])->firstWhere('orden_id', $e['ordA']->id);

    DB::table('bylopez_cc_ordenes_confeccion')->where('id', $e['ordA']->id)->update(['estado' => 'cerrada']);
    expect($a()['cerrada_y_pagada'])->toBeFalse()->and($a()['saldo_recibido'])->toBe('870000.00')->and($a()['estado_pago'])->toBe('pendiente');

    cc_mov($e['jairo'], 'abono', 870000, ['aplicaciones' => [['destino' => 'orden', 'id' => $e['ordA']->id, 'valor' => '870000']]]);
    expect($a()['estado_pago'])->toBe('pagada')->and($a()['cerrada_y_pagada'])->toBeTrue();
});
