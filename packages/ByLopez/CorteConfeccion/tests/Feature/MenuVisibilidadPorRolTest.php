<?php

use Illuminate\Support\Facades\Auth;
use Webkul\Core\Menu;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

test('un rol personalizado con permisos de corte_confeccion ve exactamente lo que tiene marcado', function () {
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_corte', 'corte_confeccion.ordenes_corte.operar'],
    ]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    Auth::guard('admin')->setUser($admin);

    $menu = app(Menu::class)->getItems('admin');
    $corteMenu = $menu->first(fn ($item) => $item->key === 'corte_confeccion');

    expect($corteMenu)->not->toBeNull()
        ->and($corteMenu->getChildren()->pluck('key')->all())->toBe(['corte_confeccion.ordenes_corte']);
});

test('un rol personalizado sin ningun permiso de corte_confeccion no ve el menu', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    Auth::guard('admin')->setUser($admin);

    $menu = app(Menu::class)->getItems('admin');

    expect($menu->first(fn ($item) => str_starts_with($item->key, 'corte_confeccion')))->toBeNull();
});

test('un rol TALLER CONFECCION ve la entrada unica de ordenes de confeccion', function () {
    $role = Role::factory()->create([
        'name' => 'TALLER CONFECCION',
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.reportar_taller'],
    ]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    Auth::guard('admin')->setUser($admin);

    $menu = app(Menu::class)->getItems('admin');
    $corteMenu = $menu->first(fn ($item) => $item->key === 'corte_confeccion');

    expect($corteMenu)->not->toBeNull()
        ->and($corteMenu->getChildren()->pluck('key')->all())->toBe(['corte_confeccion.ordenes_confeccion'])
        ->and($corteMenu->getChildren()->first()->getRoute())->toBe('admin.corte_confeccion.ordenes_confeccion.index');
});

test('un rol TALLER CONFECCION sin ordenes_confeccion no ve el listado aunque tenga reportar_taller', function () {
    $role = Role::factory()->create([
        'name' => 'TALLER CONFECCION',
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_confeccion.reportar_taller'],
    ]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    Auth::guard('admin')->setUser($admin);

    $menu = app(Menu::class)->getItems('admin');
    $corteMenu = $menu->first(fn ($item) => $item->key === 'corte_confeccion');

    // El icono raiz sigue apareciendo (el rol tiene el permiso base 'corte_confeccion'),
    // pero la accion reportar_taller no publica una segunda bandeja de listado.
    expect($corteMenu)->not->toBeNull()
        ->and($corteMenu->getChildren()->pluck('key')->all())->not->toContain('corte_confeccion.ordenes_confeccion');
});

test('un super-admin (permission_type=all) ve todo el arbol de menu de corte_confeccion', function () {
    $role = Role::factory()->create(['permission_type' => 'all']);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    Auth::guard('admin')->setUser($admin);

    $menu = app(Menu::class)->getItems('admin');
    $corteMenu = $menu->first(fn ($item) => $item->key === 'corte_confeccion');

    // "Recepciones de corte" se retiro del menu (se integro dentro de la vista de
    // Orden de Corte); sus rutas/controlador/datos siguen accesibles por URL directa.
    expect($corteMenu)->not->toBeNull()
        ->and($corteMenu->getChildren()->count())->toBe(8)
        ->and($corteMenu->getChildren()->pluck('key')->all())
        ->not->toContain('corte_confeccion.recepciones_corte')
        ->not->toContain('corte_confeccion.mis_ordenes')
        ->toContain('corte_confeccion.ordenes_confeccion')
        ->toContain('corte_confeccion.cortadores');
});
