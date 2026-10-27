<?php

use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor as Autz;
use Tests\TestCase;

require_once __DIR__.'/../helpersPagB.php';

uses(TestCase::class);

/*
| PAG-F · rol operativo inicial «#2 ASESOR VENTAS» (Doc. 05 §42.22-§42.24). Copia LITERAL de los 11 permisos aplicados en
| desarrollo el 2026-10-07: las claves del modulo primero (Bagisto redirige tras el login al primer permiso) y la consulta de
| catalogo. Sin ninguna clave `sales.*`: el asesor trabaja exclusivamente desde Ventas Asesor.
*/

const PAGF_ROL_ASESOR_VENTAS = [
    'ventas_asesor', 'ventas_asesor.operar_como_asesor',
    'ventas_asesor.pedidos', 'ventas_asesor.pedidos.create', 'ventas_asesor.pedidos.view_own',
    'ventas_asesor.cartera', 'ventas_asesor.cartera.view_own', 'ventas_asesor.cartera.registrar', 'ventas_asesor.cartera.abono_general',
    'ventas_asesor.asesores', 'ventas_asesor.asesores.view_own',
    'catalog', 'catalog.products',
];

test('rol ASESOR VENTAS: opera como asesor con alcance propio (pedidos y cartera), registra pagos y abonos, y no revisa, no reversa ni ve lo ajeno', function () {
    $asesor = pagb_asesor(PAGF_ROL_ASESOR_VENTAS);
    $autz = app(Autz::class);

    expect($autz->tienePermiso($asesor, Autz::PERMISO_MODULO))->toBeTrue()
        ->and($autz->esAsesor($asesor))->toBeTrue()
        ->and($autz->alcanceDeVisibilidad($asesor))->toBe(AlcanceVisibilidad::PROPIOS)
        ->and($autz->alcanceDeCartera($asesor))->toBe(AlcanceVisibilidad::PROPIOS)
        ->and($autz->tienePermiso($asesor, Autz::PERMISO_CARTERA_REGISTRAR))->toBeTrue()
        ->and($autz->tienePermiso($asesor, Autz::PERMISO_CARTERA_ABONO_GENERAL))->toBeTrue()
        ->and($autz->tienePermiso($asesor, Autz::PERMISO_PEDIDOS_VER_TODOS))->toBeFalse()
        ->and($autz->tienePermiso($asesor, Autz::PERMISO_CARTERA_VER_TODA))->toBeFalse()
        ->and($autz->tienePermiso($asesor, Autz::PERMISO_CARTERA_REVISAR))->toBeFalse()
        ->and($autz->tienePermiso($asesor, Autz::PERMISO_CARTERA_REVERSAR))->toBeFalse();

    // Pantallas del modulo: Inicio, Mis ventas, Nueva venta y la bandeja Cartera / Pagos (sin acciones de revision).
    $this->actingAs($asesor, 'admin')->get(route('admin.ventas_asesor.inicio.index'))->assertOk();
    $this->actingAs($asesor, 'admin')->get(route('admin.ventas_asesor.pedidos.index'))->assertOk()->assertViewHas('verTodos', false);
    $this->actingAs($asesor, 'admin')->get(route('admin.ventas_asesor.nueva_venta.pantalla'))->assertOk();
    $this->actingAs($asesor, 'admin')->get(route('admin.ventas_asesor.cartera.index'))->assertOk()
        ->assertViewHas('puedeRevisar', false)
        ->assertDontSee('data-revisar="confirmar"', false)
        ->assertDontSee('<v-revision-pago', false);

    // Confirmar / rechazar: el Bouncer corta (la ruta pertenece a la hoja `revisar`, que el rol no tiene).
    $this->actingAs($asesor, 'admin')->postJson(route('admin.ventas_asesor.cartera.pagos.confirmar', ['pago' => 1]))->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->postJson(route('admin.ventas_asesor.cartera.pagos.rechazar', ['pago' => 1]), ['categoria' => 'otro', 'motivo' => 'prueba'])->assertUnauthorized();
});

test('rol ASESOR VENTAS: sin modulo Sales nativo (pedidos, facturas, envios, refunds, Transactions); el menu muestra las 4 entradas del modulo y el Administrador sigue con todo', function () {
    $asesor = pagb_asesor(PAGF_ROL_ASESOR_VENTAS);

    expect(collect(PAGF_ROL_ASESOR_VENTAS)->filter(fn ($k) => $k === 'sales' || str_starts_with($k, 'sales.')))->toBeEmpty();

    // Pantallas nativas de Sales: el Bouncer corta (ninguna clave `sales.*` en el rol).
    $this->actingAs($asesor, 'admin')->get(route('admin.sales.orders.index'))->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->get(route('admin.sales.orders.view', ['id' => 1]))->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->get(route('admin.sales.orders.create', ['cartId' => 1]))->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->get(route('admin.sales.invoices.index'))->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->get(route('admin.sales.shipments.index'))->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->post(route('admin.sales.shipments.store', ['order_id' => 1]))->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->get(route('admin.sales.refunds.index'))->assertUnauthorized();

    $this->actingAs($asesor, 'admin')->get(route('admin.sales.transactions.index'))->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->postJson(route('admin.sales.transactions.store'), ['invoice_id' => 1, 'payment_method' => 'moneytransfer', 'amount' => 1])->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->get(route('admin.sales.transactions.view', ['id' => 1]))->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->post(route('admin.sales.invoices.store', ['order_id' => 1]))->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->post(route('admin.sales.refunds.store', ['order_id' => 1]))->assertUnauthorized();

    // Menu lateral (estando en el modulo): Inicio, Mis ventas / Pedidos, Nueva venta y Cartera / Pagos.
    $this->actingAs($asesor, 'admin')->get(route('admin.ventas_asesor.inicio.index'))->assertOk()
        ->assertSee(route('admin.ventas_asesor.pedidos.index'), false)
        ->assertSee(route('admin.ventas_asesor.nueva_venta.pantalla'), false)
        ->assertSee(route('admin.ventas_asesor.cartera.index'), false);

    // El Administrador (`all`) conserva las pantallas nativas y la revision de pagos.
    $admin = f03b_admin('all');

    $this->actingAs($admin, 'admin')->get(route('admin.sales.orders.index'))->assertOk();
    $this->actingAs($admin, 'admin')->get(route('admin.ventas_asesor.cartera.index'))->assertOk()->assertViewHas('puedeRevisar', true);
});
