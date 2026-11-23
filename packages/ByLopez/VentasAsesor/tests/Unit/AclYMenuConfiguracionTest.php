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

    expect(va01_clavesAcl())->toHaveCount(56)
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
        'ventas_asesor.pedidos.tipo_operacion',
        'ventas_asesor.pedidos.cambiar_grupo_temporal',
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
        'ventas_asesor.comisiones.calcular',
        'ventas_asesor.comisiones.ajustar',
        'ventas_asesor.comisiones.revertir',
        'ventas_asesor.comisiones.comprobantes',
        'ventas_asesor.rentabilidad',
        'ventas_asesor.rentabilidad.view',
        'ventas_asesor.rentabilidad.configurar',
        'ventas_asesor.cotizaciones_datos',
        'ventas_asesor.kpis',
        'ventas_asesor.kpis.view_own',
        'ventas_asesor.kpis.view_all',
        // PAG-B (D-59 / P-08): rama de Cartera.
        'ventas_asesor.cartera',
        'ventas_asesor.cartera.view_own',
        'ventas_asesor.cartera.view_all',
        'ventas_asesor.cartera.registrar',
        'ventas_asesor.cartera.abono_general',
        'ventas_asesor.cartera.revisar',
        'ventas_asesor.cartera.credito',
        'ventas_asesor.cartera.credito.ver',
        'ventas_asesor.cartera.credito.aplicar',
        'ventas_asesor.cartera.credito.reembolsar',
        'ventas_asesor.cartera.credito.reversar',
        'ventas_asesor.cartera.credito.ajustar',
        'ventas_asesor.cartera.reversar',
        // Doc. 33 P1: acceso de clientes al aplicativo (autoservicio).
        'ventas_asesor.clientes_acceso',
        'ventas_asesor.clientes_acceso.manage',
        // ASESOR-01: ficha de asesores.
        'ventas_asesor.asesores',
        'ventas_asesor.asesores.view_own',
        'ventas_asesor.asesores.view_all',
        'ventas_asesor.asesores.manage',
        // D-COST-04/05: Control de costos (solo administracion).
        'ventas_asesor.costos',
        'ventas_asesor.costos.actualizar',
        // Doc. 32: Precios por grupo, separado de costos.
        'ventas_asesor.precios_grupo',
        'ventas_asesor.precios_grupo.editar',
    ]);
});

test('la ruta de inicio la autoriza unicamente la clave raiz', function () {
    expect(acl()->getRoles()->get(va01_rutaInicio()))->toBe('ventas_asesor');

    $entradasRaiz = collect(config('acl'))->where('key', 'ventas_asesor');

    expect($entradasRaiz)->toHaveCount(1)
        ->and(Arr::wrap($entradasRaiz->first()['route']))->toBe([va01_rutaInicio()]);
});

test('"Mis ventas / Pedidos": su ruta la autoriza la clave agrupadora pedidos, como string', function () {
    expect(acl()->getRoles()->get('admin.ventas_asesor.pedidos.index'))->toBe('ventas_asesor.pedidos');

    // String y no arreglo: puede ser la primera clave con punto de un rol (regla 3 de acl.php).
    expect(collect(config('acl'))->firstWhere('key', 'ventas_asesor.pedidos')['route'])
        ->toBe('admin.ventas_asesor.pedidos.index');
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

test('el menu del paquete tiene un nodo raiz y solo hijos con clave ACL existente y ruta GET real, sin enlaces muertos', function () {
    $menu = collect(config('menu.admin'))
        ->filter(fn ($item) => str_starts_with($item['key'], 'ventas_asesor'));

    expect($menu->pluck('key')->all())->toBe([
        'ventas_asesor',
        'ventas_asesor.operar_como_asesor',
        'ventas_asesor.pedidos',
        'ventas_asesor.cartera',
        'ventas_asesor.asesores',
        'ventas_asesor.comisiones',
        'ventas_asesor.pedidos.create',
    ])
        ->and($menu->first()['route'])->toBe(va01_rutaInicio())
        ->and(collect(config('menu.admin'))->pluck('key')->all())->toContain('dashboard');

    foreach ($menu as $item) {
        $ruta = Route::getRoutes()->getByName($item['route']);

        expect(va01_clavesAcl())->toContain($item['key'])
            ->and($ruta)->not->toBeNull($item['key'])
            ->and($ruta->methods())->toContain('GET')
            ->and($ruta->parameterNames())->toBe([])
            ->and(trans($item['name']))->not->toBe($item['name']);
    }

    // PAG-E: Cartera / Pagos ya tiene su bandeja; la entrada de menu abre la agrupadora (una ruta real, sin parametros).
    expect($menu->firstWhere('key', 'ventas_asesor.cartera')['route'])->toBe('admin.ventas_asesor.cartera.index');
});

test('el menu «Administracion» agrupa costos, rentabilidad y datos de cotizacion con rutas GET reales y claves fuera de la ACL', function () {
    $menu = collect(config('menu.admin'))->filter(fn ($item) => str_starts_with($item['key'], 'administracion'));

    expect($menu->pluck('route', 'key')->all())->toBe([
        'administracion' => 'admin.ventas_asesor.costos.index',
        'administracion.costos' => 'admin.ventas_asesor.costos.index',
        'administracion.rentabilidad' => 'admin.ventas_asesor.rentabilidad.index',
        'administracion.cotizaciones_datos' => 'admin.ventas_asesor.nueva_venta.cotizaciones.datos',
    ]);

    foreach ($menu as $item) {
        $ruta = Route::getRoutes()->getByName($item['route']);

        // Fuera de la ACL a proposito: ningun rol `custom` puede recibir el menu desde Roles.
        expect(collect(config('acl'))->pluck('key')->all())->not->toContain($item['key'])
            ->and($ruta)->not->toBeNull($item['key'])
            ->and($ruta->methods())->toContain('GET')
            ->and($ruta->parameterNames())->toBe([])
            ->and(trans($item['name']))->not->toBe($item['name']);
    }
});

test('"Ver pedido" de la venta creada va al detalle del modulo y no a una ruta nativa inexistente o ajena a su ACL', function () {
    $vista = file_get_contents(__DIR__.'/../../src/Resources/views/admin/nueva-venta/index.blade.php');

    expect($vista)->toContain("route('admin.ventas_asesor.pedidos.index')")
        ->and($vista)->toContain('?pedido=')
        ->and($vista)->not->toContain("/sales/orders') }}/'");
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
            'ventas_asesor.cartera',
            'ventas_asesor.asesores',
            'ventas_asesor.costos',
            'ventas_asesor.precios_grupo',
            'ventas_asesor.rentabilidad',
            'ventas_asesor.cotizaciones_datos',
            'ventas_asesor.clientes_acceso',
        ]);

    $pedidos = $raiz->children->first(fn ($item) => $item->key === 'ventas_asesor.pedidos');

    expect($pedidos->children->pluck('key')->all())->toBe([
        'ventas_asesor.pedidos.create',
        'ventas_asesor.pedidos.view_own',
        'ventas_asesor.pedidos.view_all',
        'ventas_asesor.pedidos.assign',
        'ventas_asesor.pedidos.reassign',
        'ventas_asesor.pedidos.tipo_operacion',
        'ventas_asesor.pedidos.cambiar_grupo_temporal',
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
        'admin.ventas_asesor.nueva_venta.pantalla',
        'admin.ventas_asesor.nueva_venta.en_curso',
        'admin.ventas_asesor.nueva_venta.clientes.direcciones',
        'admin.ventas_asesor.nueva_venta.eliminar',
        'admin.ventas_asesor.nueva_venta.direcciones.agregar',
        'admin.ventas_asesor.ubicaciones.buscar',
    ] as $nombre) {
        expect($roles->get($nombre))->toBe(BorradorVentaService::PERMISO, $nombre);
    }

    // No se duplico ninguna clave del modulo para lograrlo (las pruebas de conteo/arbol arriba lo exigen).
    expect(va01_clavesAcl())->toHaveCount(56);
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
