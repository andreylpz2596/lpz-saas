<?php

use ByLopez\VentasAsesor\Exceptions\MensajeCarteraNoDisponibleException as NoDisponible;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Services\Mensajes\MensajeCarteraClienteService;
use ByLopez\VentasAsesor\Support\Cartera\FechaCorteCartera;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../helpersPagB.php';

uses(TestCase::class);

/*
| Estado de cuenta de WhatsApp del cliente (Cartera › cliente, solo lectura). Los saldos son los de Cartera: se crean pagos y
| aplicaciones reales y se comprueba que el texto dice lo mismo que la pantalla. Solo lpz_saas_testing.
*/

const EC_TODOS = ['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.view_all'];
const EC_PROPIOS = ['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.view_own'];

function ec_corte(?string $fecha): void
{
    pagb_corte($fecha);
    app()->forgetInstance(FechaCorteCartera::class);
    app()->forgetInstance(CarteraConsultaService::class);
}

function ec_servicio(): MensajeCarteraClienteService
{
    return app(MensajeCarteraClienteService::class);
}

/** Cliente con: saldo con abono confirmado, saldo con abono sin confirmar, pagado, en revision y uno anterior al corte. */
function ec_escenario(): array
{
    ec_corte(now()->subDays(10)->toDateString());
    $cliente = pagb_cliente();
    DB::table('customers')->where('id', $cliente->id)->update(['first_name' => 'MARTA ISABEL', 'phone' => '+573001112233']);

    $conAbono = pagb_pedido($cliente, 100000.0, ['created_at' => now()->subDays(3)]);
    pagb_aplicar(pagb_pago($cliente, ['monto' => 40000, 'estado' => 'confirmado']), $conAbono, 40000.0);

    $enVerificacion = pagb_pedido($cliente, 50000.0, ['created_at' => now()->subDays(1)]);
    pagb_aplicar(pagb_pago($cliente, ['monto' => 20000, 'estado' => 'sin_revisar']), $enVerificacion, 20000.0);

    $pagado = pagb_pedido($cliente, 30000.0, ['created_at' => now()->subDays(1)]);
    pagb_aplicar(pagb_pago($cliente, ['monto' => 30000, 'estado' => 'confirmado']), $pagado, 30000.0);

    $revision = pagb_pedido($cliente, 10000.0, ['created_at' => now()->subDays(2)]);
    pagb_aplicar(pagb_pago($cliente, ['monto' => 15000, 'estado' => 'confirmado']), $revision, 15000.0);

    $anterior = pagb_pedido($cliente, 70000.0, ['created_at' => now()->subDays(20)]);

    return compact('cliente', 'conAbono', 'enVerificacion', 'pagado', 'revision', 'anterior');
}

test('el texto usa los saldos de Cartera: con saldo y sin revision; aclara lo que se verifica; avisa (solo a quien genera) lo que no entra', function () {
    $e = ec_escenario();
    $admin = f03b_admin('all');

    $m = ec_servicio()->generar((int) $e['cliente']->id, 'estado-cuenta', $admin);
    $t = $m['texto'];

    expect($t)->toStartWith('Hola Marta 👋🏻')
        ->toContain('#'.$e['conAbono']->increment_id.' · Saldo $ 60.000 (total $ 100.000, abonado $ 40.000)')
        ->toContain('#'.$e['enVerificacion']->increment_id.' · Saldo $ 30.000 (total $ 50.000, abonado $ 20.000)')
        ->toContain('💰 Total pendiente: $ 90.000 (2 pedidos)')
        ->toContain('Incluye abonos por $ 20.000 que estamos verificando.')
        ->not->toContain($e['pagado']->increment_id)
        ->not->toContain($e['revision']->increment_id)
        ->not->toContain($e['anterior']->increment_id)
        ->not->toContain('revisión')->not->toContain('corte');

    // Del mas antiguo al mas nuevo.
    expect(strpos($t, $e['conAbono']->increment_id))->toBeLessThan(strpos($t, $e['enVerificacion']->increment_id));

    expect($m['telefono'])->toBe('+573001112233')
        ->and($m['avisos'])->toHaveCount(2)
        ->and($m['avisos'][0])->toContain('1 pedido(s) con revisión pendiente')
        ->and($m['avisos'][1])->toContain('1 pedido(s) anteriores a la fecha de corte');

    // El total coincide con la deuda de la pantalla Cartera del cliente (misma fuente).
    expect(app(CarteraConsultaService::class)->carteraDelCliente((int) $e['cliente']->id, $admin)->carteraTotal)->toBe(90000.0);

    // Generar no escribe nada.
    expect(DB::table('bylopez_va_pagos')->where('customer_id', $e['cliente']->id)->count())->toBe(4);
});

test('cliente al dia; Cartera inactiva no ofrece el mensaje; clave desconocida 409', function () {
    ec_corte(now()->subDays(10)->toDateString());
    $cliente = pagb_cliente();
    $pagado = pagb_pedido($cliente, 30000.0);
    pagb_aplicar(pagb_pago($cliente, ['monto' => 30000, 'estado' => 'confirmado']), $pagado, 30000.0);
    $admin = f03b_admin('all');

    expect(ec_servicio()->generar((int) $cliente->id, 'estado-cuenta', $admin)['texto'])->toContain('¡Estás al día!')->not->toContain('Puedes pagar');
    expect(fn () => ec_servicio()->generar((int) $cliente->id, 'otra', $admin))->toThrow(NoDisponible::class);

    ec_corte(null);
    expect(ec_servicio()->opciones((int) $cliente->id, $admin))->toBe([]);
    expect(fn () => ec_servicio()->generar((int) $cliente->id, 'estado-cuenta', $admin))->toThrow(NoDisponible::class);
});

test('permisos: solo alcance «todos»; «ver propios» no (ni revela si el cliente existe); HTTP con su ACL; el boton solo con alcance «todos»', function () {
    $e = ec_escenario();
    $todos = pagb_asesor(EC_TODOS);
    $propia = pagb_asesor(EC_PROPIOS);
    pagb_atribuir($e['conAbono'], $propia);
    $id = (int) $e['cliente']->id;

    expect(array_column(ec_servicio()->opciones($id, $todos), 'clave'))->toBe(['estado-cuenta']);

    foreach ([$id, 999999999] as $cliente) {
        try {
            ec_servicio()->opciones($cliente, $propia);
            $this->fail('view_own no debe generar el estado de cuenta');
        } catch (NoDisponible $x) {
            expect($x->codigo)->toBe(NoDisponible::SIN_PERMISO);
        }
    }

    try {
        ec_servicio()->opciones(999999999, $todos);
        $this->fail('cliente inexistente');
    } catch (NoDisponible $x) {
        expect($x->codigo)->toBe(NoDisponible::CLIENTE_INEXISTENTE);
    }

    $opciones = $this->actingAs($todos, 'admin')->getJson(route('admin.ventas_asesor.cartera.mensajes.opciones', $id))
        ->assertOk()->assertJsonPath('opciones.0.clave', 'estado-cuenta');
    expect($opciones->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $this->actingAs($todos, 'admin')->getJson(route('admin.ventas_asesor.cartera.mensajes.mensaje', [$id, 'estado-cuenta']))
        ->assertOk()->assertJsonPath('tipo', 'estado_cuenta')->assertJsonCount(2, 'avisos');
    $this->actingAs($propia, 'admin')->getJson(route('admin.ventas_asesor.cartera.mensajes.opciones', $id))->assertStatus(401);

    // Pantalla Cartera del cliente: el boton y el componente solo con alcance «todos».
    $this->actingAs($todos, 'admin')->get(route('admin.ventas_asesor.cartera.index', ['cliente' => $id]))
        ->assertOk()->assertSee('data-estado-cuenta', false)->assertSee('data-whatsapp-pedido="'.$id.'"', false)
        ->assertSee(str_replace('/', '\/', route('admin.ventas_asesor.cartera.mensajes.opciones', ['cliente' => '__ID__'])), false);
    $this->actingAs($propia, 'admin')->get(route('admin.ventas_asesor.cartera.index', ['cliente' => $id]))
        ->assertOk()->assertDontSee('data-estado-cuenta', false);
});
