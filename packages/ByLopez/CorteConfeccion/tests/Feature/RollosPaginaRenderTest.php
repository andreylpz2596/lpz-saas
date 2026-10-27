<?php

use Illuminate\Support\Facades\Route;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

test('la pagina de rollos renderiza con las cards y el datagrid', function () {
    $role = Role::factory()->create(['permission_type' => 'all']);
    $admin = Admin::factory()->create(['role_id' => $role->id]);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.rollos.index'));

    $response->assertOk();
    $response->assertSee('Total inventario');
    $response->assertSee('Disponibles');
    $response->assertSee('Reservados en corte');
    $response->assertSee('En uso / en corte');
    $response->assertSee('Agotados / cerrados');
    $response->assertDontSee('Crear rollo');
    $response->assertSee('id="v-rollo-edit-modal-template"', false);
    $response->assertSee('Editar rollo');
});

test('la ruta de crear rollo no esta disponible', function () {
    $role = Role::factory()->create(['permission_type' => 'all']);
    $admin = Admin::factory()->create(['role_id' => $role->id]);

    expect(Route::has('admin.corte_confeccion.rollos.create'))->toBeFalse();
    expect(Route::has('admin.corte_confeccion.rollos.store'))->toBeFalse();

    $this->actingAs($admin, 'admin')
        ->get('/'.config('app.admin_url').'/corte-confeccion/rollos/create')
        ->assertNotFound();
});

test('la pagina get de editar rollo no esta disponible', function () {
    $role = Role::factory()->create(['permission_type' => 'all']);
    $admin = Admin::factory()->create(['role_id' => $role->id]);

    expect(Route::has('admin.corte_confeccion.rollos.edit'))->toBeFalse();
    expect(Route::has('admin.corte_confeccion.rollos.update'))->toBeTrue();

    $this->actingAs($admin, 'admin')
        ->get('/'.config('app.admin_url').'/corte-confeccion/rollos/edit/3940')
        ->assertNotFound();
});
