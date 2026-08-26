<?php

return [
    [
        'key'   => 'corte_confeccion',
        'name'  => 'corteconfeccion::app.acl.title',
        'route' => 'admin.corte_confeccion.compras_tela.index',
        'sort'  => 10,
    ], [
        'key'   => 'corte_confeccion.compras_tela',
        'name'  => 'corteconfeccion::app.acl.compras-tela',
        'route' => 'admin.corte_confeccion.compras_tela.index',
        'sort'  => 1,
    ], [
        'key'   => 'corte_confeccion.compras_tela.create',
        'name'  => 'admin::app.acl.create',
        'route' => 'admin.corte_confeccion.compras_tela.create',
        'sort'  => 1,
    ], [
        'key'   => 'corte_confeccion.compras_tela.edit',
        'name'  => 'admin::app.acl.edit',
        'route' => 'admin.corte_confeccion.compras_tela.edit',
        'sort'  => 2,
    ], [
        'key'   => 'corte_confeccion.proveedores_tela',
        'name'  => 'corteconfeccion::app.acl.proveedores-tela',
        'route' => 'admin.corte_confeccion.proveedores_tela.index',
        'sort'  => 2,
    ], [
        'key'   => 'corte_confeccion.proveedores_tela.create',
        'name'  => 'admin::app.acl.create',
        'route' => 'admin.corte_confeccion.proveedores_tela.create',
        'sort'  => 1,
    ], [
        'key'   => 'corte_confeccion.proveedores_tela.edit',
        'name'  => 'admin::app.acl.edit',
        'route' => 'admin.corte_confeccion.proveedores_tela.edit',
        'sort'  => 2,
    ], [
        'key'   => 'corte_confeccion.rollos',
        'name'  => 'corteconfeccion::app.acl.rollos',
        'route' => 'admin.corte_confeccion.rollos.index',
        'sort'  => 3,
    ], [
        'key'   => 'corte_confeccion.rollos.create',
        'name'  => 'admin::app.acl.create',
        'route' => 'admin.corte_confeccion.rollos.create',
        'sort'  => 1,
    ], [
        'key'   => 'corte_confeccion.rollos.edit',
        'name'  => 'admin::app.acl.edit',
        'route' => 'admin.corte_confeccion.rollos.edit',
        'sort'  => 2,
    ], [
        'key'   => 'corte_confeccion.ordenes_corte',
        'name'  => 'corteconfeccion::app.acl.ordenes-corte',
        'route' => 'admin.corte_confeccion.ordenes_corte.index',
        'sort'  => 4,
    ], [
        'key'   => 'corte_confeccion.ordenes_corte.create',
        'name'  => 'admin::app.acl.create',
        'route' => 'admin.corte_confeccion.ordenes_corte.create',
        'sort'  => 1,
    ], [
        'key'   => 'corte_confeccion.ordenes_corte.operar',
        'name'  => 'corteconfeccion::app.acl.operar',
        'route' => 'admin.corte_confeccion.ordenes_corte.index',
        'sort'  => 2,
    ], [
        'key'   => 'corte_confeccion.recepciones_corte',
        'name'  => 'corteconfeccion::app.acl.recepciones-corte',
        'route' => 'admin.corte_confeccion.recepciones_corte.index',
        'sort'  => 5,
    ],
];
