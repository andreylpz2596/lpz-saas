<?php

return [
    [
        'key' => 'corte_confeccion',
        'name' => 'corteconfeccion::app.menu.title',
        'route' => 'admin.corte_confeccion.compras_tela.index',
        'sort' => 10,
        'icon' => 'icon-sales',
    ], [
        'key' => 'corte_confeccion.compras_tela',
        'name' => 'corteconfeccion::app.menu.compras-tela',
        'route' => 'admin.corte_confeccion.compras_tela.index',
        'sort' => 1,
        'icon' => '',
    ], [
        'key' => 'corte_confeccion.proveedores_tela',
        'name' => 'corteconfeccion::app.menu.proveedores-tela',
        'route' => 'admin.corte_confeccion.proveedores_tela.index',
        'sort' => 2,
        'icon' => '',
    ], [
        'key' => 'corte_confeccion.rollos',
        'name' => 'corteconfeccion::app.menu.rollos',
        'route' => 'admin.corte_confeccion.rollos.index',
        'sort' => 3,
        'icon' => '',
    ], [
        'key' => 'corte_confeccion.ordenes_corte',
        'name' => 'corteconfeccion::app.menu.ordenes-corte',
        'route' => 'admin.corte_confeccion.ordenes_corte.index',
        'sort' => 4,
        'icon' => '',
    ], [
        // "Recepciones de corte" ya no es un flujo de entrada principal: la
        // recepcion de bodega ahora se hace integrada dentro de la propia
        // Orden de Corte (ver ordenes-corte/show.blade.php). Se retira del
        // menu (Fase 11), pero las rutas, el controlador y el historico de
        // recepciones existentes se mantienen intactos y siguen accesibles
        // por URL directa para consulta (admin.corte_confeccion.recepciones_corte.show).
        // F0 (docs/ORDEN_CORTE_CREACION_V2_AUDITORIA_02.md §12.2): la pantalla
        // de creacion independiente (admin.corte_confeccion.recepciones_corte.create)
        // dejaba elegir cualquier producto del catalogo por fila sin validar
        // que perteneciera a la orden. El controlador ahora redirige ese GET
        // a la orden de corte; el unico flujo real para registrar una
        // recepcion nueva es el card embebido en ordenes-corte/show.blade.php.
        'key' => 'corte_confeccion.talleres',
        'name' => 'corteconfeccion::app.menu.talleres',
        'route' => 'admin.corte_confeccion.talleres.index',
        'sort' => 5,
        'icon' => '',
    ], [
        'key' => 'corte_confeccion.cortadores',
        'name' => 'corteconfeccion::app.menu.cortadores',
        'route' => 'admin.corte_confeccion.cortadores.index',
        'sort' => 6,
        'icon' => '',
    ], [
        'key' => 'corte_confeccion.ordenes_confeccion',
        'name' => 'corteconfeccion::app.menu.ordenes-confeccion',
        'route' => 'admin.corte_confeccion.ordenes_confeccion.index',
        'sort' => 7,
        'icon' => '',
    ], [
        'key' => 'corte_confeccion.inventario_movimientos',
        'name' => 'corteconfeccion::app.menu.inventario-movimientos',
        'route' => 'admin.corte_confeccion.inventario_movimientos.index',
        'sort' => 8,
        'icon' => '',
    ], [
        'key' => 'corte_confeccion.stock_minimos',
        'name' => 'corteconfeccion::app.menu.stock-minimos',
        'route' => 'admin.corte_confeccion.stock_minimos.index',
        'sort' => 8,
        'icon' => '',
    ], [
        'key' => 'corte_confeccion.cartera',
        'name' => 'corteconfeccion::app.menu.cartera',
        'route' => 'admin.corte_confeccion.cartera.index',
        'sort' => 9,
        'icon' => '',
    ], [
        'key' => 'corte_confeccion.mi_cuenta',
        'name' => 'corteconfeccion::app.menu.mi-cuenta',
        'route' => 'admin.corte_confeccion.cartera.mi_cuenta',
        'sort' => 10,
        'icon' => '',
    ],
];
