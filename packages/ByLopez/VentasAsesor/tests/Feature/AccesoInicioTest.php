<?php

use ByLopez\VentasAsesor\Providers\VentasAsesorServiceProvider;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Webkul\Core\Menu;

require_once __DIR__.'/../helpers.php';

uses(TestCase::class);

test('el provider del paquete esta cargado y registra la ruta de inicio', function () {
    expect(app()->getProviders(VentasAsesorServiceProvider::class))->not->toBeEmpty()
        ->and(Route::has(va01_rutaInicio()))->toBeTrue()
        ->and(route(va01_rutaInicio(), absolute: false))->toBe('/'.config('app.admin_url').'/ventas-asesor');
});

test('un usuario no autenticado es redirigido al login administrativo', function () {
    $this->get(route(va01_rutaInicio()))
        ->assertRedirect(route('admin.session.create'));
});

test('un administrador sin la clave del modulo recibe 401', function () {
    $this->actingAs(va01_admin(['dashboard']), 'admin')
        ->get(route(va01_rutaInicio()))
        ->assertUnauthorized();
});

test('la clave raiz basta para entrar sin permisos amplios de ventas', function () {
    $this->actingAs(va01_admin(['ventas_asesor']), 'admin')
        ->get(route(va01_rutaInicio()))
        ->assertOk()
        ->assertViewIs('ventasasesor::admin.inicio.index');
});

test('los 18 permisos actuales del rol ASESOR VENTAS no abren el modulo', function () {
    $this->actingAs(va01_admin(va01_permisosRolAsesorVentasActual(), 'custom', 'ASESOR VENTAS'), 'admin')
        ->get(route(va01_rutaInicio()))
        ->assertUnauthorized();
});

test('todas las claves hijas sin la clave raiz no abren la pantalla de inicio', function () {
    $hijas = array_values(array_filter(va01_clavesAcl(), fn ($clave) => $clave !== 'ventas_asesor'));

    $this->actingAs(va01_admin($hijas), 'admin')
        ->get(route(va01_rutaInicio()))
        ->assertUnauthorized();
});

test('un rol llamado ASESOR VENTAS sin el permiso marcador no se reconoce como asesor', function () {
    $this->actingAs(va01_admin(['ventas_asesor'], 'custom', 'ASESOR VENTAS'), 'admin')
        ->get(route(va01_rutaInicio()))
        ->assertOk()
        ->assertViewHas('esAsesor', false)
        ->assertSee('data-es-asesor="0"', false);
});

test('un rol con otro nombre y el permiso marcador si se reconoce como asesor', function () {
    $this->actingAs(va01_admin(['ventas_asesor', 'ventas_asesor.operar_como_asesor'], 'custom', 'Vendedora Zona Norte'), 'admin')
        ->get(route(va01_rutaInicio()))
        ->assertOk()
        ->assertViewHas('esAsesor', true)
        ->assertSee('data-es-asesor="1"', false);
});

test('permission_type all entra, se reconoce como asesor y ve todo, igual que en Bagisto', function () {
    $this->actingAs(va01_admin(null, 'all'), 'admin')
        ->get(route(va01_rutaInicio()))
        ->assertOk()
        ->assertSee('data-es-asesor="1"', false)
        ->assertSee('data-alcance="todos"', false);
});

test('la pantalla refleja alcances diferenciados de view_own y view_all', function (array $extra, string $alcance) {
    $this->actingAs(va01_admin(array_merge(['ventas_asesor'], $extra)), 'admin')
        ->get(route(va01_rutaInicio()))
        ->assertOk()
        ->assertSee('data-alcance="'.$alcance.'"', false);
})->with([
    'solo view_own' => [['ventas_asesor.pedidos.view_own'], 'propios'],
    'solo view_all' => [['ventas_asesor.pedidos.view_all'], 'todos'],
    'ambos' => [['ventas_asesor.pedidos.view_own', 'ventas_asesor.pedidos.view_all'], 'todos'],
    'ninguno' => [[], 'ninguno'],
]);

test('manipular la URL no altera la autorizacion del servidor', function () {
    $propios = va01_admin(['ventas_asesor', 'ventas_asesor.pedidos.view_own']);

    $this->actingAs($propios, 'admin')
        ->get(route(va01_rutaInicio(), ['alcance' => 'todos', 'view_all' => 1, 'advisor_id' => 1]))
        ->assertOk()
        ->assertSee('data-alcance="propios"', false);

    $this->actingAs(va01_admin(['dashboard']), 'admin')
        ->get(route(va01_rutaInicio(), ['permiso' => 'ventas_asesor']))
        ->assertUnauthorized();

    $this->actingAs($propios, 'admin')
        ->get('/'.config('app.admin_url').'/ventas-asesor/pedidos/1')
        ->assertNotFound();
});

test('un administrador con rol huerfano es rechazado por el middleware de Bagisto antes del modulo', function () {
    $espia = $this->spy(AutorizacionVentasAsesor::class);

    $this->actingAs(va01_adminConRolHuerfano(), 'admin')
        ->get(route(va01_rutaInicio()))
        ->assertUnauthorized();

    $espia->shouldNotHaveReceived('esAsesor');
    $espia->shouldNotHaveReceived('alcanceDeVisibilidad');
});

test('un administrador inactivo es desconectado y redirigido al login', function () {
    $this->actingAs(va01_admin(['ventas_asesor'], 'custom', 'VA01 Rol', ['status' => 0]), 'admin')
        ->get(route(va01_rutaInicio()))
        ->assertRedirect(route('admin.session.create'));

    expect(Auth::guard('admin')->check())->toBeFalse();
});

test('un rol personalizado sin permisos es desconectado por Bagisto', function () {
    $this->actingAs(va01_admin([]), 'admin')
        ->get(route(va01_rutaInicio()))
        ->assertRedirect(route('admin.session.create'));

    expect(Auth::guard('admin')->check())->toBeFalse();
});

test('la pantalla inicial no escribe ni consulta tablas propias, pedidos ni RMA', function () {
    $consultas = [];

    DB::listen(function ($consulta) use (&$consultas) {
        $consultas[] = strtolower($consulta->sql);
    });

    $this->actingAs(va01_admin(['ventas_asesor']), 'admin')
        ->get(route(va01_rutaInicio()))
        ->assertOk();

    $sql = implode("\n", $consultas);

    expect($sql)->not->toContain('bylopez_va_')
        ->and($sql)->not->toMatch('/\b(from|join)\s+`?orders`?\b/')
        ->and($sql)->not->toMatch('/\b(from|join)\s+`?rma/')
        ->and($sql)->not->toMatch('/^\s*(insert|update|delete|replace)\b/m');
});

test('el paquete no depende de RMA ni del nombre de ningun rol', function () {
    $fuentes = collect(File::allFiles(__DIR__.'/../../src'))
        ->map(fn ($archivo) => $archivo->getContents())
        ->implode("\n");

    expect($fuentes)->not->toContain('Webkul\\RMA')
        ->and($fuentes)->not->toContain('ASESOR VENTAS');

    $this->actingAs(va01_admin(['ventas_asesor']), 'admin')
        ->get(route(va01_rutaInicio()))
        ->assertOk();
});

test('un rol con permission_type desconocido pasa el Bouncer de Bagisto pero el modulo lo rechaza', function () {
    $desconocido = va01_admin(['ventas_asesor'], 'otro');

    // Con el servicio sustituido por un espia se demuestra que el middleware de Bagisto
    // deja llegar la peticion al controlador.
    $espia = $this->spy(AutorizacionVentasAsesor::class);

    $this->actingAs($desconocido, 'admin')->get(route(va01_rutaInicio()));

    $espia->shouldHaveReceived('tienePermiso');

    // Con el servicio real, el modulo la rechaza.
    $this->app->forgetInstance(AutorizacionVentasAsesor::class);

    $this->actingAs($desconocido, 'admin')
        ->get(route(va01_rutaInicio()))
        ->assertUnauthorized();
});

test('el menu muestra el modulo solo a quien tiene la clave raiz', function () {
    $ve = function ($admin) {
        Auth::guard('admin')->setUser($admin);

        return (new Menu)->getItems('admin')->contains(fn ($item) => $item->key === 'ventas_asesor');
    };

    expect($ve(va01_admin(['ventas_asesor'])))->toBeTrue()
        ->and($ve(va01_admin(['ventas_asesor.operar_como_asesor'])))->toBeFalse()
        ->and($ve(va01_admin(['dashboard'])))->toBeFalse()
        ->and($ve(va01_admin(null, 'all')))->toBeTrue();
});
