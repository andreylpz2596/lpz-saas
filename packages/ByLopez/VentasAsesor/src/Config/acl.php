<?php

/*
 * ACL de ByLopez\VentasAsesor.
 *
 * Tres reglas de Bagisto 2.4.9, verificadas en el codigo, condicionan este archivo:
 *
 * 1. Los permisos son PLANOS. Webkul\User\Http\Middleware\Bouncer::checkIfAuthorized()
 *    obtiene la clave de la ruta con acl()->getRoles() y Admin::hasPermission() exige
 *    coincidencia exacta. Una clave padre NO concede sus hijas y una hija NO concede
 *    a su padre.
 *
 * 2. Si varias entradas listan la misma ruta, la autoriza la ULTIMA
 *    (Webkul\Core\Acl::getRoles() usa mapWithKeys). Por eso la clave raiz
 *    'ventas_asesor' va AL FINAL: es la unica que autoriza la pantalla de inicio.
 *
 * 3. SessionController::redirectToFirstAccessibleRoute() hace
 *    redirect()->route($permiso['route']) con el primer permiso con punto del rol.
 *    Toda clave necesita una ruta GET existente y sin parametros, o el login de ese
 *    rol falla. Las claves de fases posteriores apuntan a la pantalla de inicio
 *    hasta que su fase cree su propia ruta.
 *
 * Las claves agrupadoras (pedidos, logistica, evidencias, comisiones, kpis) solo
 * ordenan el arbol de Configuracion > Roles y no autorizan ninguna operacion.
 * Cada operacion debe protegerse con su clave hoja explicita.
 */

$inicio = 'admin.ventas_asesor.inicio.index';

return [
    [
        'key' => 'ventas_asesor.operar_como_asesor',
        'name' => 'ventasasesor::app.acl.operar-como-asesor',
        'route' => $inicio,
        'sort' => 1,
    ], [
        'key' => 'ventas_asesor.pedidos',
        'name' => 'ventasasesor::app.acl.pedidos',
        'route' => $inicio,
        'sort' => 2,
    ], [
        'key' => 'ventas_asesor.pedidos.create',
        'name' => 'ventasasesor::app.acl.crear',
        /*
         * $inicio va PRIMERO: la clave de esta entrada sigue siendo UNICA (ninguna clave se
         * duplica: la prueba "el arbol ACL... es exactamente el definido" lo exige) y
         * Acl::getRoles() mapea TODOS los elementos del arreglo (Arr::wrap), asi que
         * autoriza tambien la API de "Nueva venta rapida" (03G-C, NuevaVentaController)
         * bajo el MISMO permiso que ya exige BorradorVentaService::PERMISO, sin clave
         * nueva. $inicio en la posicion 0 mantiene la regla 3 del encabezado (toda clave
         * apunta a una ruta GET sin parametros: la prueba solo mira Arr::wrap(route)[0]).
         *
         * Riesgo aceptado y documentado: SessionController::redirectToFirstAccessibleRoute()
         * (Bagisto core) hace `redirect()->route($permissionDetails['route'])` SIN indexar
         * [0]; si esta clave fuera algun dia la PRIMERA con punto en la lista de permisos de
         * un rol 'custom' (orden de guardado del rol, no de este archivo), ese redirect
         * fallaria con un array. El propio Admin\Config\acl.php de Bagisto ya usa arreglos
         * en 'route' de forma extendida (mismo riesgo, no introducido por este cambio); no
         * se soluciona aqui porque exigiria modificar Webkul (fuera de alcance de 03G-C).
         */
        'route' => [
            $inicio,
            'admin.ventas_asesor.nueva_venta.clientes.buscar',
            'admin.ventas_asesor.nueva_venta.clientes.crear',
            'admin.ventas_asesor.nueva_venta.iniciar',
            'admin.ventas_asesor.nueva_venta.iniciar_cliente_nuevo',
            'admin.ventas_asesor.nueva_venta.lineas.colores',
            'admin.ventas_asesor.nueva_venta.productos.matriz',
            'admin.ventas_asesor.nueva_venta.estado_inicial',
            'admin.ventas_asesor.nueva_venta.estado',
            'admin.ventas_asesor.nueva_venta.tallas',
            'admin.ventas_asesor.nueva_venta.entrega',
            'admin.ventas_asesor.nueva_venta.pago',
            'admin.ventas_asesor.nueva_venta.confirmar',
        ],
        'sort' => 1,
    ], [
        'key' => 'ventas_asesor.pedidos.view_own',
        'name' => 'ventasasesor::app.acl.ver-propios',
        'route' => $inicio,
        'sort' => 2,
    ], [
        'key' => 'ventas_asesor.pedidos.view_all',
        'name' => 'ventasasesor::app.acl.ver-todos',
        'route' => $inicio,
        'sort' => 3,
    ], [
        'key' => 'ventas_asesor.pedidos.assign',
        'name' => 'ventasasesor::app.acl.asignar',
        'route' => $inicio,
        'sort' => 4,
    ], [
        'key' => 'ventas_asesor.pedidos.reassign',
        'name' => 'ventasasesor::app.acl.reasignar',
        'route' => $inicio,
        'sort' => 5,
    ], [
        'key' => 'ventas_asesor.logistica',
        'name' => 'ventasasesor::app.acl.logistica',
        'route' => $inicio,
        'sort' => 3,
    ], [
        'key' => 'ventas_asesor.logistica.view',
        'name' => 'ventasasesor::app.acl.ver',
        'route' => $inicio,
        'sort' => 1,
    ], [
        'key' => 'ventas_asesor.logistica.manage',
        'name' => 'ventasasesor::app.acl.administrar',
        'route' => $inicio,
        'sort' => 2,
    ], [
        'key' => 'ventas_asesor.evidencias',
        'name' => 'ventasasesor::app.acl.evidencias',
        'route' => $inicio,
        'sort' => 4,
    ], [
        'key' => 'ventas_asesor.evidencias.create',
        'name' => 'ventasasesor::app.acl.crear',
        'route' => $inicio,
        'sort' => 1,
    ], [
        'key' => 'ventas_asesor.evidencias.delete',
        'name' => 'ventasasesor::app.acl.eliminar',
        'route' => $inicio,
        'sort' => 2,
    ], [
        'key' => 'ventas_asesor.comisiones',
        'name' => 'ventasasesor::app.acl.comisiones',
        'route' => $inicio,
        'sort' => 5,
    ], [
        'key' => 'ventas_asesor.comisiones.view_own',
        'name' => 'ventasasesor::app.acl.ver-propios',
        'route' => $inicio,
        'sort' => 1,
    ], [
        'key' => 'ventas_asesor.comisiones.view_all',
        'name' => 'ventasasesor::app.acl.ver-todos',
        'route' => $inicio,
        'sort' => 2,
    ], [
        'key' => 'ventas_asesor.comisiones.manage',
        'name' => 'ventasasesor::app.acl.administrar',
        'route' => $inicio,
        'sort' => 3,
    ], [
        'key' => 'ventas_asesor.comisiones.approve',
        'name' => 'ventasasesor::app.acl.aprobar',
        'route' => $inicio,
        'sort' => 4,
    ], [
        'key' => 'ventas_asesor.comisiones.pay',
        'name' => 'ventasasesor::app.acl.pagar',
        'route' => $inicio,
        'sort' => 5,
    ], [
        'key' => 'ventas_asesor.kpis',
        'name' => 'ventasasesor::app.acl.kpis',
        'route' => $inicio,
        'sort' => 6,
    ], [
        'key' => 'ventas_asesor.kpis.view_own',
        'name' => 'ventasasesor::app.acl.ver-propios',
        'route' => $inicio,
        'sort' => 1,
    ], [
        'key' => 'ventas_asesor.kpis.view_all',
        'name' => 'ventasasesor::app.acl.ver-todos',
        'route' => $inicio,
        'sort' => 2,
    ], [
        // Debe ser la ultima entrada: ver la regla 2 del encabezado.
        'key' => 'ventas_asesor',
        'name' => 'ventasasesor::app.acl.title',
        'route' => $inicio,
        'sort' => 11,
    ],
];
