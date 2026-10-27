<?php

/*
 * Entrada de menu «Cargues». NO se fusiona en `menu.admin` en el arranque: Bagisto filtra el menu con
 * `bouncer()->hasPermission(clave)` y todo rol con `permission_type = all` la veria aunque no sea administrador.
 * El proveedor la agrega por request SOLO si el usuario es el rol administrador (ver CarguesServiceProvider).
 */
return [
    [
        'key' => 'cargues',
        'name' => 'cargues::app.menu.cargues',
        'route' => 'admin.cargues.index',
        'sort' => 13,
        'icon' => 'icon-folder',
    ],
];
