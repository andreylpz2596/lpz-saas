<?php

/*
 * Bagisto filtra la visibilidad del menu (Webkul\Core\Menu::getItems) llamando
 * bouncer()->hasPermission($menuItem['key']) directamente con la clave del
 * menu.php. Los permisos guardados en el rol (Settings > Roles) se generan a
 * partir de acl.php. Si las claves de menu.php y acl.php no son IDENTICAS,
 * el menu queda invisible para CUALQUIER rol personalizado sin importar que
 * permisos tenga marcados (bug reproducido y corregido en produccion: el
 * menu completo de Corte y Confeccion estaba invisible porque menu.php usaba
 * guiones, 'corte-confeccion', y acl.php usaba guion bajo, 'corte_confeccion').
 */
test('cada clave de menu.php de CorteConfeccion tiene una clave identica en acl.php', function () {
    $menuKeys = collect(config('menu.admin'))
        ->filter(fn ($item) => str_starts_with($item['key'], 'corte_confeccion'))
        ->pluck('key')
        ->sort()
        ->values();

    $aclKeys = collect(config('acl'))
        ->filter(fn ($item) => str_starts_with($item['key'], 'corte_confeccion'))
        ->pluck('key')
        ->sort()
        ->values();

    expect($menuKeys)->not->toBeEmpty();
    expect($menuKeys->diff($aclKeys)->all())->toBe([]);
});

test('ninguna clave de menu.php de CorteConfeccion usa guiones', function () {
    $menuKeys = collect(config('menu.admin'))
        ->filter(fn ($item) => str_starts_with($item['key'], 'corte'))
        ->pluck('key');

    expect($menuKeys)->not->toBeEmpty();

    foreach ($menuKeys as $key) {
        expect($key)->not->toContain('-');
    }
});
