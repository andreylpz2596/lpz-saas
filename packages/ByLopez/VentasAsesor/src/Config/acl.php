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
        'route' => $inicio,
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
