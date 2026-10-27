<?php

/*
 * Menu «Logistica». Cada clave reutiliza la clave ACL del mismo nombre (Bagisto filtra cada entrada con
 * bouncer()->hasPermission(clave)). Solo vistas que existen. «Despachos» es la bandeja de trabajo de BODEGA.
 */
return [
    [
        'key' => 'logistica',
        'name' => 'logistica::app.menu.logistica',
        'route' => 'admin.logistica.despachos.index',
        'sort' => 12,
        'icon' => 'icon-ship',
    ], [
        'key' => 'logistica.despachos',
        'name' => 'logistica::app.menu.despachos',
        'route' => 'admin.logistica.despachos.index',
        'sort' => 1,
        'icon' => '',
    ], [
        'key' => 'logistica.entregas',
        'name' => 'logistica::app.menu.entregas',
        'route' => 'admin.logistica.entregas.index',
        'sort' => 2,
        'icon' => '',
    ], [
        'key' => 'logistica.transportadoras',
        'name' => 'logistica::app.menu.transportadoras',
        'route' => 'admin.logistica.transportadoras.index',
        'sort' => 3,
        'icon' => '',
    ], [
        'key' => 'logistica.cuentas',
        'name' => 'logistica::app.menu.cuentas',
        'route' => 'admin.logistica.cuentas.index',
        'sort' => 4,
        'icon' => '',
    ], [
        'key' => 'logistica.zonas_locales',
        'name' => 'logistica::app.menu.zonas_locales',
        'route' => 'admin.logistica.zonas_locales.index',
        'sort' => 5,
        'icon' => '',
    ],
];
