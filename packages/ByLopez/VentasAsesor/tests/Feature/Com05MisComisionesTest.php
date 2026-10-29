<?php

use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException;
use ByLopez\VentasAsesor\Services\Comisiones\EvaluarComisionesService;
use ByLopez\VentasAsesor\Services\Comisiones\LiquidacionesComisionService;
use ByLopez\VentasAsesor\Services\Comisiones\MisComisionesService;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../helpersCom4.php';

uses(TestCase::class);

/*
| «MIS COMISIONES» (vista de la asesora, jornada 2026-10-28 tarde). Solo lpz_saas_testing; cada prueba arma su asesora, producto y
| acuerdo. Se demuestra: (1) las etapas son un REPARTO del mismo importe (no se sumaban dos veces: total = 1.200, no 2.400);
| (2) lo bloqueado trae su motivo comprensible; (3) abonos y saldo; (4) aislamiento en servidor: otra asesora no ve ni toca nada ajeno.
*/

/**
 * El arbol de Roles marca las claves agrupadoras (todos los prefijos de cada clave) al marcar una hoja; el menu de Bagisto las necesita para
 * armarse (sin ellas, `Undefined array key "name"` en el layout). Un rol real las tiene: se agregan como lo hace la pantalla de Roles.
 */
function mc_como_en_roles(array $e): array
{
    $rol = $e['asesor']->role;
    $todas = $rol->permissions;

    foreach ($rol->permissions as $clave) {
        $partes = explode('.', $clave);

        for ($i = 1; $i < count($partes); $i++) {
            $todas[] = implode('.', array_slice($partes, 0, $i));
        }
    }

    $rol->update(['permissions' => array_values(array_unique($todas))]);

    return $e;
}

function mc_resumen(array $e): array
{
    return app(MisComisionesService::class)->resumen($e['asesor'], MesComercial::de(now()));
}

test('MC-1 998 + 5: las etapas reparten UN mismo importe de $1.200 (bloqueada → habilitada → por pagar → pagada) y nunca se suman dos veces', function () {
    $e = com4_escenario();
    com4_vender($e, 998);
    $pedido = com4_vender($e, 5);

    $r = mc_resumen($e);
    expect($r['etapas'])->toBe(['bloqueada' => '1200.00', 'habilitada' => '0.00', 'por_pagar' => '0.00', 'pagada' => '0.00'])
        ->and($r['total_mes'])->toBe('1200.00');

    com4_pagar($pedido);
    com4_entregar($pedido);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $pedido->id);
    $r = mc_resumen($e);
    // Antes habria dos cifras (estimada 1.200 y habilitada 1.200): ahora el importe esta en UNA sola etapa.
    expect($r['etapas'])->toBe(['bloqueada' => '0.00', 'habilitada' => '1200.00', 'por_pagar' => '0.00', 'pagada' => '0.00'])
        ->and($r['total_mes'])->toBe('1200.00')
        ->and($r['habilitado_sin_liquidar'])->toBe('1200.00');

    $admin = f03b_admin('all');
    $servicio = app(LiquidacionesComisionService::class);
    $liq = $servicio->crearBorrador($admin, (int) $e['asesor']->id, now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString(), [], 'MC', 'mc-liq-'.Str::uuid());
    $liq = $servicio->aprobar($admin, (int) $liq->id);
    $servicio->registrarAbono($admin, (int) $liq->id, ['monto' => '500', 'fecha_pago' => now()->toDateString(), 'medio' => 'transferencia', 'referencia' => 'MC'], null, 'mc-ab-'.Str::uuid());

    $r = mc_resumen($e);
    expect($r['etapas'])->toBe(['bloqueada' => '0.00', 'habilitada' => '0.00', 'por_pagar' => '700.00', 'pagada' => '500.00'])
        ->and($r['total_mes'])->toBe('1200.00')
        ->and($r['saldo_por_pagar'])->toBe('700.00')
        ->and($r['liquidaciones'][0]['abonado'])->toBe('500.00')->and($r['liquidaciones'][0]['saldo'])->toBe('700.00')
        ->and($r['liquidaciones'][0]['abonos'])->toHaveCount(1);

    $servicio->registrarAbono($admin, (int) $liq->id, ['monto' => '700', 'fecha_pago' => now()->toDateString(), 'medio' => 'transferencia', 'referencia' => 'MC2'], null, 'mc-ab-'.Str::uuid());
    $r = mc_resumen($e);
    expect($r['etapas'])->toBe(['bloqueada' => '0.00', 'habilitada' => '0.00', 'por_pagar' => '0.00', 'pagada' => '1200.00'])
        ->and($r['total_mes'])->toBe('1200.00')->and($r['saldo_por_pagar'])->toBe('0.00');
});

test('MC-2 lo bloqueado trae su motivo (codigo con texto comprensible) y nunca costo, base ni margen', function () {
    $e = com4_escenario();
    com4_vender($e, 998);
    $pedido = com4_vender($e, 5);
    $r = mc_resumen($e);
    $linea = $r['pedidos'][1]['lineas'][0];

    expect($linea['etapa'])->toBe('bloqueada')->and($linea['motivos'])->toContain('unidades_sin_despachar');

    foreach ($linea['motivos'] as $m) {
        expect(trans()->has('ventasasesor::comisiones.motivos.'.$m))->toBeTrue("sin texto para el motivo {$m}");
    }

    // La primera venta (998) queda dentro del umbral: bloqueada con la razon del umbral, no con un motivo tecnico.
    expect($r['pedidos'][0]['lineas'][0]['motivos'])->toContain('dentro_del_umbral');
    expect(json_encode($r))->not->toContain('costo')->not->toContain('base_unitaria')->not->toContain('margen');
    expect((int) $pedido->id)->toBeGreaterThan(0);
});

test('MC-3 aislamiento en servidor: otra asesora no ve ni toca lo ajeno; los parametros no cambian a quien se consulta', function () {
    $this->withoutVite();   // el layout de Bagisto exige el manifiesto de Vite; aqui solo importa el HTML de la vista
    $a = mc_como_en_roles(com4_escenario());
    $b = mc_como_en_roles(com4_escenario());
    com4_vender($a, 998);
    $pedidoA = com4_vender($a, 5);
    com4_vender($b, 3);

    $verB = mc_resumen($b);
    expect(collect($verB['pedidos'])->pluck('order_id')->all())->not->toContain((int) $pedidoA->id)
        ->and($verB['total_mes'])->toBe('0.00');

    // HTTP: la vista toma SIEMPRE a la autenticada; un ?asesor= ajeno se ignora.
    $mias = route('admin.ventas_asesor.comisiones.mias', ['asesor' => $a['asesor']->id]);
    $html = $this->actingAs($b['asesor'], 'admin')->get($mias)->assertOk()->getContent();
    expect($html)->not->toContain('#'.$pedidoA->increment_id);
    $this->actingAs($a['asesor'], 'admin')->get(route('admin.ventas_asesor.comisiones.mias'))->assertOk()->assertSee('#'.$pedidoA->increment_id, false)->assertSee('1.200', false);

    // El detalle de un pedido ajeno no existe para ella (404) y no puede aprobar, liquidar, abonar ni reevaluar (403).
    $this->actingAs($b['asesor'], 'admin')->get(route('admin.ventas_asesor.comisiones.index', ['pedido' => $pedidoA->id]))->assertNotFound();

    $escrituras = fn () => [DB::table('bylopez_va_comision_liquidaciones')->count(), DB::table('bylopez_va_comision_abonos')->count(), DB::table('bylopez_va_comision_acuerdos')->count()];
    $antes = $escrituras();

    foreach ([
        ['post', route('admin.ventas_asesor.comisiones.evaluar')],
        ['post', route('admin.ventas_asesor.comisiones.liquidaciones.store')],
        ['post', route('admin.ventas_asesor.comisiones.liquidaciones.aprobar', 1)],
        ['post', route('admin.ventas_asesor.comisiones.abonos.store', 1)],
        ['post', route('admin.ventas_asesor.comisiones.acuerdos.store', $a['asesor']->id)],
    ] as [$metodo, $url]) {
        // El Bouncer de Bagisto deniega con 401; el servicio, con 403: ambos son rechazo y no deben escribir nada.
        expect($this->actingAs($b['asesor'], 'admin')->{$metodo}($url, ['monto' => '1'])->getStatusCode())->toBeIn([401, 403]);
    }

    expect($escrituras())->toBe($antes);

    // Sin el permiso view_own no hay vista (ni el servicio responde).
    $sin = f03b_admin('custom', ['ventas_asesor.operar_como_asesor']);
    expect($this->actingAs($sin, 'admin')->get(route('admin.ventas_asesor.comisiones.mias'))->getStatusCode())->toBeIn([401, 403]);
    expect(fn () => app(MisComisionesService::class)->resumen($sin, MesComercial::de(now())))->toThrow(OperacionComisionInvalidaException::class);
});
