<?php

use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

require_once __DIR__.'/../helpers.php';

uses(TestCase::class);

// Usuarios en memoria (no se guardan): estas pruebas no escriben en la base.

test('un rol sin permiso dashboard que abre el dashboard va a su primera pantalla, no a 401', function () {
    $this->actingAs(va01_admin(['ventas_asesor', 'ventas_asesor.pedidos', 'ventas_asesor.pedidos.view_own']), 'admin')
        ->get(route('admin.dashboard.index'))
        ->assertRedirect();

    $destino = $this->actingAs(va01_admin(['ventas_asesor']), 'admin')->get(route('admin.dashboard.index'));

    $destino->assertRedirect(route(va01_rutaInicio()));
});

test('el dashboard sigue igual para quien tiene el permiso o permission_type all', function () {
    $this->actingAs(va01_admin(['dashboard']), 'admin')
        ->get(route('admin.dashboard.index'))
        ->assertOk();

    $this->actingAs(va01_admin(null, 'all'), 'admin')
        ->get(route('admin.dashboard.index'))
        ->assertOk();
});

test('las peticiones JSON y las demas rutas sin permiso conservan el 401 de Bagisto', function () {
    $this->actingAs(va01_admin(['ventas_asesor']), 'admin')
        ->getJson(route('admin.dashboard.index'))
        ->assertUnauthorized();

    $this->actingAs(va01_admin(['ventas_asesor']), 'admin')
        ->get(route('admin.customers.customers.index'))
        ->assertUnauthorized();
});

test('sin sesion el dashboard sigue llevando al login', function () {
    $this->get(route('admin.dashboard.index'))
        ->assertRedirect(route('admin.session.create'));
});

test('el login con sesion abierta termina en la primera pantalla del rol, no en 401', function () {
    $this->actingAs(va01_admin(['ventas_asesor']), 'admin')
        ->get(route('admin.session.create'))
        ->assertRedirect(route('admin.dashboard.index'));

    $this->actingAs(va01_admin(['ventas_asesor']), 'admin')
        ->get(route('admin.dashboard.index'))
        ->assertRedirect(route(va01_rutaInicio()));
});

test('un token vencido en un formulario del panel vuelve con aviso en vez de 500', function () {
    Route::middleware('web')->post(config('app.admin_url').'/qa-token-vencido', fn () => throw new TokenMismatchException('CSRF token mismatch.'));

    $this->from('/'.config('app.admin_url').'/ventas-asesor')
        ->post('/'.config('app.admin_url').'/qa-token-vencido')
        ->assertRedirect('/'.config('app.admin_url').'/ventas-asesor')
        ->assertSessionHas('warning', trans('ventasasesor::app.sesion.pagina-expirada'));
});

test('un token vencido por JSON o fuera del panel no cambia', function () {
    Route::middleware('web')->post(config('app.admin_url').'/qa-token-vencido-json', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
    Route::middleware('web')->post('qa-token-vencido-tienda', fn () => throw new TokenMismatchException('CSRF token mismatch.'));

    expect($this->postJson('/'.config('app.admin_url').'/qa-token-vencido-json')->getStatusCode())->not->toBe(302)
        ->and($this->post('/qa-token-vencido-tienda')->getStatusCode())->not->toBe(302);
});

test('la guardia del doble envio solo se imprime en la pagina de login', function () {
    $this->get(route('admin.session.create'))
        ->assertOk()
        ->assertSee('data-venta="guardia-login"', false);

    $this->actingAs(va01_admin(['ventas_asesor']), 'admin')
        ->get(route(va01_rutaInicio()))
        ->assertOk()
        ->assertDontSee('data-venta="guardia-login"', false);
});
