<?php

use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

require_once __DIR__.'/../helpers.php';

uses(TestCase::class);

beforeEach(function () {
    $this->autorizacion = new AutorizacionVentasAsesor;
});

test('reconoce al asesor por el permiso marcador y nunca por el nombre del rol', function () {
    $mismoNombreSinMarcador = va01_admin(['ventas_asesor', 'ventas_asesor.pedidos.view_own'], 'custom', 'ASESOR VENTAS');
    $otroNombreConMarcador = va01_admin(['ventas_asesor.operar_como_asesor'], 'custom', 'Vendedora Zona Norte');

    expect($this->autorizacion->esAsesor($mismoNombreSinMarcador))->toBeFalse()
        ->and($this->autorizacion->esAsesor($otroNombreConMarcador))->toBeTrue();
});

test('decide igual que el bouncer de Bagisto en roles personalizados y en permission_type all', function () {
    $claves = [
        AutorizacionVentasAsesor::PERMISO_MODULO,
        AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR,
        AutorizacionVentasAsesor::PERMISO_PEDIDOS_VER_PROPIOS,
        AutorizacionVentasAsesor::PERMISO_PEDIDOS_VER_TODOS,
        'sales.orders.cancel',
        'dashboard',
    ];

    $administradores = [
        'personalizado del modulo' => va01_admin(['ventas_asesor', 'ventas_asesor.pedidos.view_own']),
        'personalizado de ventas' => va01_admin(['sales', 'sales.orders.cancel']),
        'permission_type all' => va01_admin(null, 'all'),
    ];

    foreach ($administradores as $caso => $admin) {
        Auth::guard('admin')->setUser($admin);

        foreach ($claves as $clave) {
            expect($this->autorizacion->tienePermiso($admin, $clave))
                ->toBe(bouncer()->hasPermission($clave), "{$caso} / {$clave}");
        }
    }
});

test('solo permission_type all o custom pueden conceder; el resto falla cerrado aunque el permiso coincida', function (?string $tipo, mixed $permisos, bool $esperado) {
    expect($this->autorizacion->tienePermiso(va01_admin($permisos, $tipo), 'ventas_asesor'))->toBe($esperado);
})->with([
    'custom con el permiso' => ['custom', ['ventas_asesor'], true],
    'custom sin el permiso' => ['custom', ['dashboard'], false],
    'all' => ['all', null, true],
    'tipo desconocido con el permiso' => ['otro', ['ventas_asesor'], false],
    'tipo vacio con el permiso' => ['', ['ventas_asesor'], false],
    'tipo nulo con el permiso' => [null, ['ventas_asesor'], false],
    'custom con permisos nulos' => ['custom', null, false],
    'custom con permisos que no son arreglo' => ['custom', 'ventas_asesor', false],
    'custom con un elemento no cadena que iguala por comparacion laxa' => ['custom', [true], false],
    'custom con una cadena valida mezclada con un no cadena' => ['custom', ['ventas_asesor', 1], false],
]);

test('Admin::hasPermission de Bagisto concede en casos que el servicio rechaza', function () {
    expect(va01_admin(['ventas_asesor'], 'otro')->hasPermission('ventas_asesor'))->toBeTrue()
        ->and(va01_admin([true])->hasPermission('ventas_asesor'))->toBeTrue()
        ->and($this->autorizacion->tienePermiso(va01_admin(['ventas_asesor'], 'otro'), 'ventas_asesor'))->toBeFalse()
        ->and($this->autorizacion->tienePermiso(va01_admin([true]), 'ventas_asesor'))->toBeFalse();
});

test('falla cerrado sin administrador o con rol nulo', function () {
    $casos = [
        'sin administrador' => null,
        'rol huerfano' => va01_adminConRolHuerfano(),
    ];

    foreach ($casos as $caso => $admin) {
        expect($this->autorizacion->tienePermiso($admin, 'ventas_asesor'))->toBeFalse($caso)
            ->and($this->autorizacion->esAsesor($admin))->toBeFalse($caso)
            ->and($this->autorizacion->alcanceDeVisibilidad($admin))->toBe(AlcanceVisibilidad::NINGUNO, $caso)
            ->and($this->autorizacion->puedeVerRegistroDe($admin, 1))->toBeFalse($caso);
    }
});

test('con rol nulo el bouncer de Bagisto lanza error y el servicio del modulo niega sin fallar', function () {
    $huerfano = va01_adminConRolHuerfano();

    Auth::guard('admin')->setUser($huerfano);

    expect(fn () => bouncer()->hasPermission('dashboard'))->toThrow(ErrorException::class)
        ->and($this->autorizacion->tienePermiso($huerfano, 'dashboard'))->toBeFalse();
});

test('los permisos son planos: el padre no concede hijas y la hija no concede al padre', function () {
    $soloRaiz = va01_admin(['ventas_asesor']);
    $soloAgrupador = va01_admin(['ventas_asesor.pedidos']);
    $soloHoja = va01_admin(['ventas_asesor.pedidos.view_own']);

    expect($this->autorizacion->tienePermiso($soloRaiz, 'ventas_asesor.pedidos.view_own'))->toBeFalse()
        ->and($this->autorizacion->tienePermiso($soloAgrupador, 'ventas_asesor.pedidos.view_all'))->toBeFalse()
        ->and($this->autorizacion->alcanceDeVisibilidad($soloAgrupador))->toBe(AlcanceVisibilidad::NINGUNO)
        ->and($this->autorizacion->tienePermiso($soloHoja, 'ventas_asesor'))->toBeFalse()
        ->and($this->autorizacion->tienePermiso($soloHoja, 'ventas_asesor.pedidos'))->toBeFalse();
});

test('view_own y view_all producen alcances diferenciados', function (array $permisos, AlcanceVisibilidad $esperado) {
    expect($this->autorizacion->alcanceDeVisibilidad(va01_admin($permisos)))->toBe($esperado);
})->with([
    'solo view_own' => [['ventas_asesor.pedidos.view_own'], AlcanceVisibilidad::PROPIOS],
    'solo view_all' => [['ventas_asesor.pedidos.view_all'], AlcanceVisibilidad::TODOS],
    'ambos' => [['ventas_asesor.pedidos.view_own', 'ventas_asesor.pedidos.view_all'], AlcanceVisibilidad::TODOS],
    'solo la clave raiz' => [['ventas_asesor'], AlcanceVisibilidad::NINGUNO],
    'solo el marcador de asesor' => [['ventas_asesor.operar_como_asesor'], AlcanceVisibilidad::NINGUNO],
    'view_own de comisiones no es view_own de pedidos' => [['ventas_asesor.comisiones.view_own'], AlcanceVisibilidad::NINGUNO],
]);

test('los permisos amplios de ventas de Bagisto no conceden nada del modulo', function () {
    $rolActual = va01_admin(va01_permisosRolAsesorVentasActual(), 'custom', 'ASESOR VENTAS');

    expect($this->autorizacion->esAsesor($rolActual))->toBeFalse()
        ->and($this->autorizacion->tienePermiso($rolActual, 'ventas_asesor'))->toBeFalse()
        ->and($this->autorizacion->alcanceDeVisibilidad($rolActual))->toBe(AlcanceVisibilidad::NINGUNO)
        ->and($this->autorizacion->puedeVerRegistroDe($rolActual, 5))->toBeFalse();
});

test('permission_type all sigue el comportamiento oficial de Bagisto y concede todo', function () {
    $total = va01_admin(null, 'all');

    expect($this->autorizacion->esAsesor($total))->toBeTrue()
        ->and($this->autorizacion->alcanceDeVisibilidad($total))->toBe(AlcanceVisibilidad::TODOS)
        ->and($this->autorizacion->puedeVerRegistroDe($total, null))->toBeTrue();
});

test('puedeVerRegistroDe falla cerrado sin atribucion valida y sin view_all', function (array $permisos, ?int $idAdmin, ?int $advisorId, bool $esperado) {
    $admin = va01_admin($permisos, 'custom', 'VA01 Rol', $idAdmin === null ? [] : ['id' => $idAdmin]);

    expect($this->autorizacion->puedeVerRegistroDe($admin, $advisorId))->toBe($esperado);
})->with([
    'view_all ve un registro ajeno' => [['ventas_asesor.pedidos.view_all'], 900001, 900002, true],
    'view_all ve un registro sin atribucion' => [['ventas_asesor.pedidos.view_all'], 900001, null, true],
    'view_own ve su propio registro' => [['ventas_asesor.pedidos.view_own'], 900001, 900001, true],
    'view_own no ve un registro ajeno' => [['ventas_asesor.pedidos.view_own'], 900001, 900002, false],
    'view_own no ve un registro sin atribucion' => [['ventas_asesor.pedidos.view_own'], 900001, null, false],
    'view_own sin id de administrador no ve nada' => [['ventas_asesor.pedidos.view_own'], null, 900001, false],
    'sin permiso de visibilidad no ve ni lo propio' => [['ventas_asesor'], 900001, 900001, false],
    'el marcador de asesor no basta' => [['ventas_asesor.operar_como_asesor'], 900001, 900001, false],
]);
