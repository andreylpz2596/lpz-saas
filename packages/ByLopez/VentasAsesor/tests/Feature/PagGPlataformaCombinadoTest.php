<?php

use ByLopez\VentasAsesor\Exceptions\RegistroPagoInvalidoException as Rechazo;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarPagoService;
use Tests\TestCase;

require_once __DIR__.'/../helpersPagB.php';

uses(TestCase::class);

/*
| Plataforma de la transferencia (Nequi / Bancolombia / Davivienda) y pago combinado. Cada linea de un combinado
| es un Pago independiente con su propia referencia; comparten `grupo_pago`. Todo o nada.
*/

const PAGG_PERMISOS = ['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.registrar', 'ventas_asesor.cartera.view_all'];

beforeEach(function () {
    pagb_corte('2000-01-01');
});

function pagg_datos(array $extra = []): array
{
    return array_merge([
        'monto' => '100000', 'medio_cobro' => 'transferencia', 'plataforma' => 'nequi', 'fecha_pago' => now()->toDateString(), 'referencia' => 'TRX-1',
    ], $extra);
}

function pagg_codigo(callable $accion): ?string
{
    try {
        $accion();
    } catch (Rechazo $e) {
        return $e->codigo;
    }

    return null;
}

test('la transferencia exige una plataforma valida y la guarda; los demas medios no la admiten', function () {
    $pedido = pagb_pedido(pagb_cliente(), 500000);
    pagb_factura($pedido, 'pending');
    $actor = pagb_asesor(PAGG_PERMISOS);
    $servicio = app(RegistrarPagoService::class);

    expect(pagg_codigo(fn () => $servicio->registrar($actor, $pedido->id, pagg_datos(['plataforma' => '']), 'clave-plataforma-0001')))->toBe(Rechazo::PLATAFORMA_REQUERIDA)
        ->and(pagg_codigo(fn () => $servicio->registrar($actor, $pedido->id, pagg_datos(['plataforma' => 'paypal']), 'clave-plataforma-0002')))->toBe(Rechazo::PLATAFORMA_INVALIDA)
        ->and(pagg_codigo(fn () => $servicio->registrar($actor, $pedido->id, pagg_datos(['medio_cobro' => 'efectivo', 'referencia' => null]), 'clave-plataforma-0003')))->toBe(Rechazo::PLATAFORMA_INVALIDA)
        ->and(Pago::query()->count())->toBe(0);

    $ok = $servicio->registrar($actor, $pedido->id, pagg_datos(['plataforma' => 'bancolombia']), 'clave-plataforma-0004');

    expect(Pago::query()->findOrFail($ok->pagoId)->plataforma)->toBe('bancolombia');

    $efectivo = $servicio->registrar($actor, $pedido->id, pagg_datos(['medio_cobro' => 'efectivo', 'plataforma' => '', 'referencia' => null]), 'clave-plataforma-0005');

    expect(Pago::query()->findOrFail($efectivo->pagoId)->plataforma)->toBeNull();
});

test('pago combinado: una linea por plataforma, mismo grupo, saldo baja por la suma y cada linea queda sin revisar', function () {
    $pedido = pagb_pedido(pagb_cliente(), 200000);
    pagb_factura($pedido, 'pending');
    $actor = pagb_asesor(PAGG_PERMISOS);

    $r = app(RegistrarPagoService::class)->registrarCombinado($actor, $pedido->id, [
        ['monto' => '100000', 'plataforma' => 'nequi', 'referencia' => 'NEQ-1'],
        ['monto' => '100000', 'plataforma' => 'bancolombia', 'referencia' => 'BCO-1'],
    ], ['fecha_pago' => now()->toDateString()], 'clave-combinado-000001');

    $pagos = Pago::query()->where('grupo_pago', $r['grupo'])->orderBy('id')->get();

    expect($pagos)->toHaveCount(2)
        ->and($pagos->pluck('plataforma')->all())->toBe(['nequi', 'bancolombia'])
        ->and($pagos->pluck('referencia')->all())->toBe(['NEQ-1', 'BCO-1'])
        ->and($pagos->every(fn ($p) => $p->estado->value === 'sin_revisar' && $p->medio_cobro === 'transferencia'))->toBeTrue()
        ->and($r['pagos'][1]->saldo->saldo)->toBe(0.0);

    // Reintento con la misma clave: no duplica.
    app(RegistrarPagoService::class)->registrarCombinado($actor, $pedido->id, [
        ['monto' => '100000', 'plataforma' => 'nequi', 'referencia' => 'NEQ-1'],
        ['monto' => '100000', 'plataforma' => 'bancolombia', 'referencia' => 'BCO-1'],
    ], ['fecha_pago' => now()->toDateString()], 'clave-combinado-000001');

    expect(Pago::query()->where('grupo_pago', $r['grupo'])->count())->toBe(2);
});

test('pago combinado: todo o nada si la suma supera el saldo o una linea es invalida', function () {
    $pedido = pagb_pedido(pagb_cliente(), 150000);
    pagb_factura($pedido, 'pending');
    $actor = pagb_asesor(PAGG_PERMISOS);
    $servicio = app(RegistrarPagoService::class);
    $comunes = ['fecha_pago' => now()->toDateString()];

    expect(pagg_codigo(fn () => $servicio->registrarCombinado($actor, $pedido->id, [
        ['monto' => '100000', 'plataforma' => 'nequi', 'referencia' => 'A-1'],
        ['monto' => '100000', 'plataforma' => 'davivienda', 'referencia' => 'B-1'],
    ], $comunes, 'clave-combinado-000002')))->toBe(Rechazo::MONTO_EXCEDE_SALDO)
        ->and(pagg_codigo(fn () => $servicio->registrarCombinado($actor, $pedido->id, [
            ['monto' => '50000', 'plataforma' => 'nequi', 'referencia' => 'A-2'],
            ['monto' => '50000', 'plataforma' => '', 'referencia' => 'B-2'],
        ], $comunes, 'clave-combinado-000003')))->toBe(Rechazo::PLATAFORMA_REQUERIDA)
        ->and(pagg_codigo(fn () => $servicio->registrarCombinado($actor, $pedido->id, [
            ['monto' => '150000', 'plataforma' => 'nequi', 'referencia' => 'A-3'],
        ], $comunes, 'clave-combinado-000004')))->toBe(Rechazo::PAGO_COMBINADO_INVALIDO)
        ->and(Pago::query()->count())->toBe(0);
});
