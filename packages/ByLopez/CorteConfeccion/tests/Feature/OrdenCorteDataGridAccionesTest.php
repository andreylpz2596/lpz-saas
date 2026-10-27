<?php

use ByLopez\CorteConfeccion\DataGrids\OrdenCorteDataGrid;
use Illuminate\Support\Facades\Auth;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
|--------------------------------------------------------------------------
| El listado de Ordenes de corte no tenia boton "Editar" (solo "Ver"), lo
| que hacia parecer que no habia forma de continuar el flujo desde ahi. La
| orden no tiene una pantalla de edicion separada: "Editar" y el codigo
| clicable apuntan a la misma vista de detalle, donde ocurre todo el flujo
| (entregar peso, marcar en corte, reportar resultado, confirmar recepcion).
|--------------------------------------------------------------------------
*/

function f17CrearAdminConPermisoOrdenesCorte(): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_corte'],
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

test('el listado de ordenes de corte incluye una accion Editar que apunta al detalle', function () {
    Auth::guard('admin')->setUser(f17CrearAdminConPermisoOrdenesCorte());

    $grid = app(OrdenCorteDataGrid::class);
    $grid->prepareActions();

    $acciones = collect($grid->getActions());

    expect($acciones->firstWhere('title', 'Ver'))->not->toBeNull()
        ->and($acciones->firstWhere('title', 'Editar'))->not->toBeNull();

    $accionEditar = $acciones->firstWhere('title', 'Editar');
    $accionVer = $acciones->firstWhere('title', 'Ver');

    // Ambas apuntan al mismo lugar: no existe una pantalla de edicion separada.
    expect(($accionEditar->url)((object) ['id' => 42]))
        ->toBe(($accionVer->url)((object) ['id' => 42]))
        ->toBe(route('admin.corte_confeccion.ordenes_corte.show', 42));
});

test('sin el permiso corte_confeccion.ordenes_corte no se agregan acciones Ver ni Editar', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']]);
    Auth::guard('admin')->setUser(Admin::factory()->create(['role_id' => $role->id]));

    $grid = app(OrdenCorteDataGrid::class);
    $grid->prepareActions();

    expect($grid->getActions())->toBe([]);
});

test('la columna codigo es un enlace clicable con permiso, y texto plano sin permiso', function () {
    Auth::guard('admin')->setUser(f17CrearAdminConPermisoOrdenesCorte());

    $metodo = new ReflectionMethod(OrdenCorteDataGrid::class, 'codigoEnlace');
    $metodo->setAccessible(true);
    $grid = app(OrdenCorteDataGrid::class);

    $fila = (object) ['id' => 169, 'codigo' => 'OC-WIL-000003'];

    $htmlConPermiso = $metodo->invoke($grid, $fila);
    expect($htmlConPermiso)->toContain(route('admin.corte_confeccion.ordenes_corte.show', 169))
        ->and($htmlConPermiso)->toContain('OC-WIL-000003');

    $roleSinPermiso = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']]);
    Auth::guard('admin')->setUser(Admin::factory()->create(['role_id' => $roleSinPermiso->id]));

    expect(trim($metodo->invoke($grid, $fila)))->toBe('OC-WIL-000003');
});
