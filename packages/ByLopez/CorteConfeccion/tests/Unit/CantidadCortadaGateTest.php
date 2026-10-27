<?php

use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

function admiConRol(string $nombreRol, string $permissionType = 'custom'): Admin
{
    $role = new Role(['name' => $nombreRol, 'permission_type' => $permissionType]);

    $admin = new Admin;
    $admin->setRelation('role', $role);

    return $admin;
}

function ordenEnEstado(string $estado): OrdenCorte
{
    return new OrdenCorte(['estado' => $estado]);
}

test('un usuario con rol BODEGA puede modificar cantidad_cortada', function () {
    $admin = admiConRol('BODEGA');

    expect(CantidadCortadaGate::userCanEdit($admin))->toBeTrue();
});

test('un usuario con rol CORTADOR puede modificar cantidad_cortada', function () {
    $admin = admiConRol('CORTADOR');

    expect(CantidadCortadaGate::userCanEdit($admin))->toBeTrue();
});

test('un super-admin (permission_type=all) puede modificar cantidad_cortada', function () {
    $admin = admiConRol('Administrador', 'all');

    expect(CantidadCortadaGate::userCanEdit($admin))->toBeTrue();
});

test('un usuario sin rol Admin/CORTADOR/BODEGA no puede modificar cantidad_cortada', function () {
    $admin = admiConRol('ASESOR VENTAS');

    expect(CantidadCortadaGate::userCanEdit($admin))->toBeFalse();
});

test('sin usuario autenticado no se puede modificar cantidad_cortada', function () {
    expect(CantidadCortadaGate::userCanEdit(null))->toBeFalse();
});

test('con la orden cerrada, un rol BODEGA ya no puede modificar cantidad_cortada', function () {
    $admin = admiConRol('BODEGA');
    $orden = ordenEnEstado('cerrado');

    expect(CantidadCortadaGate::userCanEdit($admin, $orden))->toBeFalse();
});

test('con la orden cerrada, un super-admin si puede modificar cantidad_cortada', function () {
    $admin = admiConRol('Administrador', 'all');
    $orden = ordenEnEstado('cerrado');

    expect(CantidadCortadaGate::userCanEdit($admin, $orden))->toBeTrue();
});
