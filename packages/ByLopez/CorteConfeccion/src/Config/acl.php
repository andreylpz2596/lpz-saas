<?php

return [
    /*
     * Webkul usa el primer "route" de cada permiso como destino tras el login
     * cuando el rol no tiene dashboard. Por eso la primera entrada de cada key
     * debe ser una ruta GET sin parametros; las entradas duplicadas conservan
     * el mapeo ACL de rutas auxiliares, POST/PUT o con parametros.
     */
    [
        'key' => 'corte_confeccion',
        'name' => 'corteconfeccion::app.acl.title',
        'route' => 'admin.corte_confeccion.compras_tela.index',
        'sort' => 10,
    ], [
        'key' => 'corte_confeccion.compras_tela',
        'name' => 'corteconfeccion::app.acl.compras-tela',
        'route' => 'admin.corte_confeccion.compras_tela.index',
        'sort' => 1,
    ], [
        'key' => 'corte_confeccion.compras_tela',
        'name' => 'corteconfeccion::app.acl.compras-tela',
        'route' => [
            'admin.corte_confeccion.compras_tela.show',
            'admin.corte_confeccion.compras_tela.referencias',
        ],
        'sort' => 1,
    ], [
        'key' => 'corte_confeccion.compras_tela.create',
        'name' => 'admin::app.acl.create',
        'route' => 'admin.corte_confeccion.compras_tela.create',
        'sort' => 1,
    ], [
        'key' => 'corte_confeccion.compras_tela.create',
        'name' => 'admin::app.acl.create',
        'route' => [
            'admin.corte_confeccion.compras_tela.store',
        ],
        'sort' => 1,
    ], [
        'key' => 'corte_confeccion.compras_tela.edit',
        'name' => 'admin::app.acl.edit',
        'route' => 'admin.corte_confeccion.compras_tela.edit',
        'sort' => 2,
    ], [
        'key' => 'corte_confeccion.compras_tela.edit',
        'name' => 'admin::app.acl.edit',
        'route' => [
            'admin.corte_confeccion.compras_tela.update',
        ],
        'sort' => 2,
    ], [
        'key' => 'corte_confeccion.proveedores_tela',
        'name' => 'corteconfeccion::app.acl.proveedores-tela',
        'route' => 'admin.corte_confeccion.proveedores_tela.index',
        'sort' => 2,
    ], [
        'key' => 'corte_confeccion.proveedores_tela',
        'name' => 'corteconfeccion::app.acl.proveedores-tela',
        'route' => [
            'admin.corte_confeccion.proveedores_tela.show',
            'admin.corte_confeccion.proveedores_tela.referencias',
        ],
        'sort' => 2,
    ], [
        'key' => 'corte_confeccion.proveedores_tela.create',
        'name' => 'admin::app.acl.create',
        'route' => 'admin.corte_confeccion.proveedores_tela.create',
        'sort' => 1,
    ], [
        'key' => 'corte_confeccion.proveedores_tela.create',
        'name' => 'admin::app.acl.create',
        'route' => [
            'admin.corte_confeccion.proveedores_tela.store',
        ],
        'sort' => 1,
    ], [
        'key' => 'corte_confeccion.proveedores_tela.edit',
        'name' => 'admin::app.acl.edit',
        'route' => 'admin.corte_confeccion.proveedores_tela.edit',
        'sort' => 2,
    ], [
        'key' => 'corte_confeccion.proveedores_tela.edit',
        'name' => 'admin::app.acl.edit',
        'route' => [
            'admin.corte_confeccion.proveedores_tela.update',
            'admin.corte_confeccion.proveedores_tela.referencia.create',
            'admin.corte_confeccion.proveedores_tela.referencia.edit',
        ],
        'sort' => 2,
    ], [
        'key' => 'corte_confeccion.rollos',
        'name' => 'corteconfeccion::app.acl.rollos',
        'route' => 'admin.corte_confeccion.rollos.index',
        'sort' => 3,
    ], [
        'key' => 'corte_confeccion.rollos',
        'name' => 'corteconfeccion::app.acl.rollos',
        'route' => [
            'admin.corte_confeccion.rollos.show',
        ],
        'sort' => 3,
    ], [
        'key' => 'corte_confeccion.rollos.edit',
        'name' => 'admin::app.acl.edit',
        'route' => [
            'admin.corte_confeccion.rollos.update',
            'admin.corte_confeccion.rollos.cancel',
        ],
        'sort' => 2,
    ], [
        'key' => 'corte_confeccion.ordenes_corte',
        'name' => 'corteconfeccion::app.acl.ordenes-corte',
        'route' => 'admin.corte_confeccion.ordenes_corte.index',
        'sort' => 4,
    ], [
        'key' => 'corte_confeccion.ordenes_corte',
        'name' => 'corteconfeccion::app.acl.ordenes-corte',
        'route' => [
            'admin.corte_confeccion.ordenes_corte.show',
            'admin.corte_confeccion.ordenes_corte.productos',
        ],
        'sort' => 4,
    ], [
        'key' => 'corte_confeccion.ordenes_corte.create',
        'name' => 'admin::app.acl.create',
        'route' => 'admin.corte_confeccion.ordenes_corte.create',
        'sort' => 1,
    ], [
        'key' => 'corte_confeccion.ordenes_corte.create',
        'name' => 'admin::app.acl.create',
        'route' => [
            'admin.corte_confeccion.ordenes_corte.store',
            'admin.corte_confeccion.ordenes_corte.opciones',
            'admin.corte_confeccion.ordenes_corte.previsualizar',
        ],
        'sort' => 1,
    ], [
        'key' => 'corte_confeccion.ordenes_corte.operar',
        'name' => 'corteconfeccion::app.acl.operar',
        'route' => 'admin.corte_confeccion.ordenes_corte.operar_index',
        'sort' => 2,
    ], [
        'key' => 'corte_confeccion.ordenes_corte.operar',
        'name' => 'corteconfeccion::app.acl.operar',
        'route' => [
            'admin.corte_confeccion.ordenes_corte.detalles.store',
            'admin.corte_confeccion.ordenes_corte.rollos.store',
            'admin.corte_confeccion.ordenes_corte.rollos.retirar',
            'admin.corte_confeccion.ordenes_corte.entregar',
            'admin.corte_confeccion.ordenes_corte.en_corte',
            'admin.corte_confeccion.ordenes_corte.reportar',
            'admin.corte_confeccion.ordenes_corte.adicionales.opciones',
            'admin.corte_confeccion.ordenes_corte.adicionales.previsualizar',
            'admin.corte_confeccion.ordenes_corte.cerrar',
            'admin.corte_confeccion.ordenes_corte.cancel',
        ],
        'sort' => 2,
    ], [
        'key' => 'corte_confeccion.recepciones_corte',
        'name' => 'corteconfeccion::app.acl.recepciones-corte',
        'route' => 'admin.corte_confeccion.recepciones_corte.index',
        'sort' => 5,
    ], [
        'key' => 'corte_confeccion.recepciones_corte',
        'name' => 'corteconfeccion::app.acl.recepciones-corte',
        'route' => [
            'admin.corte_confeccion.recepciones_corte.create',
            'admin.corte_confeccion.recepciones_corte.store',
            'admin.corte_confeccion.recepciones_corte.show',
        ],
        'sort' => 5,
    ], [
        'key' => 'corte_confeccion.talleres',
        'name' => 'corteconfeccion::app.acl.talleres',
        'route' => 'admin.corte_confeccion.talleres.index',
        'sort' => 6,
    ], [
        'key' => 'corte_confeccion.talleres',
        'name' => 'corteconfeccion::app.acl.talleres',
        'route' => [
            'admin.corte_confeccion.talleres.buscar',
            'admin.corte_confeccion.talleres.show',
            'admin.corte_confeccion.talleres.referencias.index',
            'admin.corte_confeccion.talleres.referencias.historial',
        ],
        'sort' => 6,
    ], [
        'key' => 'corte_confeccion.talleres.create',
        'name' => 'admin::app.acl.create',
        'route' => 'admin.corte_confeccion.talleres.create',
        'sort' => 1,
    ], [
        'key' => 'corte_confeccion.talleres.create',
        'name' => 'admin::app.acl.create',
        'route' => [
            'admin.corte_confeccion.talleres.store',
        ],
        'sort' => 1,
    ], [
        'key' => 'corte_confeccion.talleres.edit',
        'name' => 'admin::app.acl.edit',
        'route' => 'admin.corte_confeccion.talleres.edit',
        'sort' => 2,
    ], [
        'key' => 'corte_confeccion.talleres.edit',
        'name' => 'admin::app.acl.edit',
        'route' => [
            'admin.corte_confeccion.talleres.update',
        ],
        'sort' => 2,
    ], [
        'key' => 'corte_confeccion.talleres.tarifas',
        'name' => 'corteconfeccion::app.acl.tarifas',
        'route' => 'admin.corte_confeccion.talleres.tarifas.store',
        'sort' => 3,
    ], [
        'key' => 'corte_confeccion.talleres.tarifas',
        'name' => 'corteconfeccion::app.acl.tarifas',
        'route' => [
            'admin.corte_confeccion.talleres.tarifas.anular',
            'admin.corte_confeccion.ordenes_confeccion.tarifa',
        ],
        'sort' => 3,
    ], [
        'key' => 'corte_confeccion.cortadores',
        'name' => 'corteconfeccion::app.acl.cortadores',
        'route' => 'admin.corte_confeccion.cortadores.index',
        'sort' => 7,
    ], [
        'key' => 'corte_confeccion.cortadores.tarifas',
        'name' => 'corteconfeccion::app.acl.tarifas',
        'route' => 'admin.corte_confeccion.cortadores.tarifas.store',
        'sort' => 3,
    ], [
        'key' => 'corte_confeccion.cortadores.tarifas',
        'name' => 'corteconfeccion::app.acl.tarifas',
        'route' => [
            'admin.corte_confeccion.cortadores.tarifas.anular',
            'admin.corte_confeccion.ordenes_corte.tarifa',
        ],
        'sort' => 3,
    ], [
        'key' => 'corte_confeccion.cortadores',
        'name' => 'corteconfeccion::app.acl.cortadores',
        'route' => [
            'admin.corte_confeccion.cortadores.show',
            'admin.corte_confeccion.cortadores.referencias.index',
            'admin.corte_confeccion.cortadores.referencias.historial',
        ],
        'sort' => 7,
    ], [
        'key' => 'corte_confeccion.cortadores.create',
        'name' => 'admin::app.acl.create',
        'route' => 'admin.corte_confeccion.cortadores.create',
        'sort' => 1,
    ], [
        'key' => 'corte_confeccion.cortadores.create',
        'name' => 'admin::app.acl.create',
        'route' => [
            'admin.corte_confeccion.cortadores.store',
        ],
        'sort' => 1,
    ], [
        'key' => 'corte_confeccion.cortadores.edit',
        'name' => 'admin::app.acl.edit',
        'route' => 'admin.corte_confeccion.cortadores.edit',
        'sort' => 2,
    ], [
        'key' => 'corte_confeccion.cortadores.edit',
        'name' => 'admin::app.acl.edit',
        'route' => [
            'admin.corte_confeccion.cortadores.update',
        ],
        'sort' => 2,
    ], [
        'key' => 'corte_confeccion.ordenes_confeccion',
        'name' => 'corteconfeccion::app.acl.ordenes-confeccion',
        'route' => 'admin.corte_confeccion.ordenes_confeccion.index',
        'sort' => 8,
    ], [
        'key' => 'corte_confeccion.ordenes_confeccion',
        'name' => 'corteconfeccion::app.acl.ordenes-confeccion',
        'route' => [
            'admin.corte_confeccion.ordenes_confeccion.show',
            'admin.corte_confeccion.ordenes_confeccion.mis_ordenes',
            'admin.corte_confeccion.ordenes_confeccion.mis_ordenes.show',
            'admin.corte_confeccion.usuarios.cortadores',
            'admin.corte_confeccion.usuarios.taller_confeccion',
        ],
        'sort' => 8,
    ], [
        'key' => 'corte_confeccion.ordenes_confeccion.create',
        'name' => 'admin::app.acl.create',
        'route' => 'admin.corte_confeccion.ordenes_confeccion.create_index',
        'sort' => 1,
    ], [
        'key' => 'corte_confeccion.ordenes_confeccion.create',
        'name' => 'admin::app.acl.create',
        'route' => [
            'admin.corte_confeccion.ordenes_confeccion.create',
            'admin.corte_confeccion.ordenes_confeccion.store',
            'admin.corte_confeccion.ordenes_confeccion.distribuir',
            'admin.corte_confeccion.ordenes_confeccion.contexto',
        ],
        'sort' => 1,
    ], [
        'key' => 'corte_confeccion.ordenes_confeccion.operar',
        'name' => 'corteconfeccion::app.acl.operar',
        'route' => 'admin.corte_confeccion.ordenes_confeccion.operar_index',
        'sort' => 2,
    ], [
        'key' => 'corte_confeccion.ordenes_confeccion.operar',
        'name' => 'corteconfeccion::app.acl.operar',
        'route' => [
            'admin.corte_confeccion.ordenes_confeccion.cancel',
        ],
        'sort' => 2,
    ], [
        'key' => 'corte_confeccion.ordenes_confeccion.reportar_taller',
        'name' => 'corteconfeccion::app.acl.reportar-taller',
        'route' => [
            'admin.corte_confeccion.ordenes_confeccion.reportar_taller',
        ],
        'sort' => 3,
    ], [
        'key' => 'corte_confeccion.ordenes_confeccion.recibir_bodega',
        'name' => 'corteconfeccion::app.acl.recibir-bodega',
        'route' => 'admin.corte_confeccion.ordenes_confeccion.recibir_bodega',
        'sort' => 4,
    ], [
        'key' => 'corte_confeccion.inventario_movimientos',
        'name' => 'corteconfeccion::app.acl.inventario-movimientos',
        'route' => 'admin.corte_confeccion.inventario_movimientos.index',
        'sort' => 8,
    ], [
        'key' => 'corte_confeccion.inventario_movimientos',
        'name' => 'corteconfeccion::app.acl.inventario-movimientos',
        'route' => [
            'admin.corte_confeccion.inventario_movimientos.ajuste_manual',
            'admin.corte_confeccion.inventario_movimientos.show',
        ],
        'sort' => 8,
    ],
];
