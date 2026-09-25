<?php

/*
 * Un unico nodo. Webkul\Core\Menu::getItems() filtra clave por clave; un hijo
 * concedido sin su padre deja un nodo sin nombre y rompe el menu lateral.
 */
return [
    [
        'key' => 'ventas_asesor',
        'name' => 'ventasasesor::app.menu.title',
        'route' => 'admin.ventas_asesor.inicio.index',
        'sort' => 11,
        'icon' => 'icon-sales',
    ],
];
