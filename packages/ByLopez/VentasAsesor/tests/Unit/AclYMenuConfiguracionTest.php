<?php

use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

require_once __DIR__.'/../helpers.php';

uses(TestCase::class);

test('la ACL del paquete se fusiona sin reemplazar la de Bagisto ni la de otros paquetes', function () {
    $claves = collect(config('acl'))->pluck('key')->all();

    expect(va01_clavesAcl())->toHaveCount(23)
        ->and($claves)->toContain('dashboard')
        ->and($claves)->toContain('sales.orders.view')
        ->and($claves)->toContain('corte_confeccion');
});

test('el arbol ACL del modulo es exactamente el definido para VentasAsesor', function () {
    expect(va01_clavesAcl())->toEqualCanonicalizing([
        'ventas_asesor',
        'ventas_asesor.operar_como_asesor',
        'ventas_asesor.pedidos',
        'ventas_asesor.pedidos.create',
        'ventas_asesor.pedidos.view_own',
        'ventas_asesor.pedidos.view_all',
        'ventas_asesor.pedidos.assign',
        'ventas_asesor.pedidos.reassign',
        'ventas_asesor.logistica',
        'ventas_asesor.logistica.view',
        'ventas_asesor.logistica.manage',
        'ventas_asesor.evidencias',
        'ventas_asesor.evidencias.create',
        'ventas_asesor.evidencias.delete',
        'ventas_asesor.comisiones',
        'ventas_asesor.comisiones.view_own',
        'ventas_asesor.comisiones.view_all',
        'ventas_asesor.comisiones.manage',
        'ventas_asesor.comisiones.approve',
        'ventas_asesor.comisiones.pay',
        'ventas_asesor.kpis',
        'ventas_asesor.kpis.view_own',
        'ventas_asesor.kpis.view_all',
    ]);
});

test('la ruta de inicio la autoriza unicamente la clave raiz', function () {
    expect(acl()->getRoles()->get(va01_rutaInicio()))->toBe('ventas_asesor');

    $entradasRaiz = collect(config('acl'))->where('key', 'ventas_asesor');

    expect($entradasRaiz)->toHaveCount(1)
        ->and(Arr::wrap($entradasRaiz->first()['route']))->toBe([va01_rutaInicio()]);
});

test('toda ruta del paquete exige el middleware admin y esta mapeada en la ACL', function () {
    $rutas = collect(Route::getRoutes()->getRoutesByName())
        ->filter(fn ($ruta, $nombre) => str_starts_with($nombre, 'admin.ventas_asesor.'));

    expect($rutas)->not->toBeEmpty();

    foreach ($rutas as $nombre => $ruta) {
        expect($ruta->gatherMiddleware())->toContain('admin')
            ->and(acl()->getRoles()->has($nombre))->toBeTrue($nombre);
    }
});

test('toda clave ACL del modulo apunta a una ruta GET existente y sin parametros', function () {
    // SessionController::redirectToFirstAccessibleRoute() redirige a esta ruta tras el login.
    $entradas = collect(config('acl'))
        ->filter(fn ($item) => $item['key'] === 'ventas_asesor' || str_starts_with($item['key'], 'ventas_asesor.'));

    foreach ($entradas as $item) {
        $ruta = Route::getRoutes()->getByName(Arr::wrap($item['route'])[0] ?? '');

        expect($ruta)->not->toBeNull($item['key'])
            ->and($ruta->methods())->toContain('GET')
            ->and($ruta->parameterNames())->toBe([]);
    }
});

test('el menu del paquete tiene un unico nodo y su clave existe identica en la ACL', function () {
    $menu = collect(config('menu.admin'))
        ->filter(fn ($item) => str_starts_with($item['key'], 'ventas_asesor'));

    expect($menu->pluck('key')->all())->toBe(['ventas_asesor'])
        ->and(va01_clavesAcl())->toContain('ventas_asesor')
        ->and($menu->first()['route'])->toBe(va01_rutaInicio())
        ->and(collect(config('menu.admin'))->pluck('key')->all())->toContain('dashboard');
});

test('el arbol ACL se construye completo para la pantalla de roles de Bagisto', function () {
    $raiz = acl()->getItems()->first(fn ($item) => $item->key === 'ventas_asesor');

    expect($raiz)->not->toBeNull()
        ->and($raiz->children->pluck('key')->all())->toBe([
            'ventas_asesor.operar_como_asesor',
            'ventas_asesor.pedidos',
            'ventas_asesor.logistica',
            'ventas_asesor.evidencias',
            'ventas_asesor.comisiones',
            'ventas_asesor.kpis',
        ]);

    $pedidos = $raiz->children->first(fn ($item) => $item->key === 'ventas_asesor.pedidos');

    expect($pedidos->children->pluck('key')->all())->toBe([
        'ventas_asesor.pedidos.create',
        'ventas_asesor.pedidos.view_own',
        'ventas_asesor.pedidos.view_all',
        'ventas_asesor.pedidos.assign',
        'ventas_asesor.pedidos.reassign',
    ]);
});

test('03G-C: la API de "nueva venta rapida" la autoriza el mismo permiso que BorradorVentaService::PERMISO, sin clave nueva ni duplicada', function () {
    $roles = acl()->getRoles();

    foreach ([
        'admin.ventas_asesor.nueva_venta.clientes.buscar',
        'admin.ventas_asesor.nueva_venta.clientes.crear',
        'admin.ventas_asesor.nueva_venta.iniciar',
        'admin.ventas_asesor.nueva_venta.iniciar_cliente_nuevo',
        'admin.ventas_asesor.nueva_venta.lineas.colores',
        'admin.ventas_asesor.nueva_venta.productos.matriz',
        'admin.ventas_asesor.nueva_venta.estado_inicial',
        'admin.ventas_asesor.nueva_venta.estado',
        'admin.ventas_asesor.nueva_venta.tallas',
        'admin.ventas_asesor.nueva_venta.entrega',
        'admin.ventas_asesor.nueva_venta.pago',
        'admin.ventas_asesor.nueva_venta.confirmar',
    ] as $nombre) {
        expect($roles->get($nombre))->toBe(BorradorVentaService::PERMISO, $nombre);
    }

    // No se duplico ninguna clave del modulo para lograrlo (las pruebas de conteo/arbol arriba lo exigen).
    expect(va01_clavesAcl())->toHaveCount(23);
});

test('las claves del servicio existen en la ACL y ninguna clave usa guiones', function () {
    $claves = va01_clavesAcl();

    foreach ([
        AutorizacionVentasAsesor::PERMISO_MODULO,
        AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR,
        AutorizacionVentasAsesor::PERMISO_PEDIDOS_VER_PROPIOS,
        AutorizacionVentasAsesor::PERMISO_PEDIDOS_VER_TODOS,
    ] as $clave) {
        expect($claves)->toContain($clave);
    }

    foreach ($claves as $clave) {
        expect($clave)->not->toContain('-');
    }
});
