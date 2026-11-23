<?php

/*
 * Menu lateral de "Ventas Asesor". Bagisto anida por la clave con puntos y filtra CADA entrada con
 * bouncer()->hasPermission(clave), asi que cada clave reutiliza una clave ACL EXISTENTE (el arbol ACL es
 * fijo; ver Config/acl.php): ningun permiso nuevo y ningun rol existente cambia.
 *
 *  - ventas_asesor                      raiz (sin ruta propia: Bagisto la sustituye por la del primer hijo)
 *  - ventas_asesor.operar_como_asesor   "Inicio" (resumen del modulo)
 *  - ventas_asesor.pedidos              "Mis ventas / Pedidos" (el controlador exige view_own / view_all)
 *  - ventas_asesor.pedidos.create       "Nueva venta" (BorradorVentaService::PERMISO)
 *  - ventas_asesor.cartera              "Cartera / Pagos" (bandeja central; el controlador exige view_own o view_all)
 *  - ventas_asesor.asesores             "Asesores" (listado con view_all; ficha propia con view_own)
 *  - ventas_asesor.costos               "Control de costos" (solo administracion)
 *
 * Un hijo concedido sin su padre deja un nodo sin nombre y rompe el menu: el arbol de Roles marca los padres
 * al marcar una hoja, y la prueba de ACL/menu lo verifica. Solo se listan vistas que ya existen (no hay enlaces
 * muertos).
 */
return [
    [
        // Doc. 33 P3/P5: menu del CLIENTE de autoservicio (arbol propio). Solo ven estas dos entradas.
        'key' => 'autoservicio',
        'name' => 'Mis pedidos',
        'route' => 'admin.autoservicio.pantalla',
        'sort' => 1,
        'icon' => 'icon-sales',
    ], [
        'key' => 'autoservicio.comprar',
        'name' => 'Hacer pedido',
        'route' => 'admin.autoservicio.pantalla',
        'sort' => 1,
        'icon' => '',
    ], [
        'key' => 'autoservicio.pedidos',
        'name' => 'Mis pedidos',
        'route' => 'admin.autoservicio.pedidos.index',
        'sort' => 2,
        'icon' => '',
    ], [
        'key' => 'ventas_asesor',
        'name' => 'ventasasesor::app.menu.title',
        'route' => 'admin.ventas_asesor.inicio.index',
        'sort' => 11,
        'icon' => 'icon-sales',
    ], [
        'key' => 'ventas_asesor.operar_como_asesor',
        'name' => 'ventasasesor::app.menu.inicio',
        'route' => 'admin.ventas_asesor.inicio.index',
        'sort' => 1,
        'icon' => '',
    ], [
        'key' => 'ventas_asesor.pedidos',
        'name' => 'ventasasesor::app.menu.pedidos',
        'route' => 'admin.ventas_asesor.pedidos.index',
        'sort' => 2,
        'icon' => '',
    ], [
        // PAG-E: la bandeja de pagos ya existe (antes no habia pantalla y no se agregaba un enlace muerto).
        'key' => 'ventas_asesor.cartera',
        'name' => 'ventasasesor::app.menu.cartera',
        'route' => 'admin.ventas_asesor.cartera.index',
        'sort' => 3,
        'icon' => '',
    ], [
        // ASESOR-01: listado de asesores (view_all) o la ficha propia (view_own).
        'key' => 'ventas_asesor.asesores',
        'name' => 'ventasasesor::app.menu.asesores',
        'route' => 'admin.ventas_asesor.asesores.index',
        'sort' => 4,
        'icon' => '',
    ], [
        // D-COST-04/05: comparacion costo comisionable vs productivo y actualizacion masiva (solo administracion).
        'key' => 'ventas_asesor.costos',
        'name' => 'ventasasesor::app.menu.costos',
        'route' => 'admin.ventas_asesor.costos.index',
        'sort' => 5,
        'icon' => '',
    ], [
        // Doc. 23: comisiones (resumen del mes, acuerdos, liquidaciones). El controlador exige view_own o view_all.
        'key' => 'ventas_asesor.comisiones',
        'name' => 'ventasasesor::app.menu.comisiones',
        'route' => 'admin.ventas_asesor.comisiones.index',
        'sort' => 6,
        'icon' => '',
    ], [
        // Doc. 23: rentabilidad (diagnostico de listas, configuracion, panel mensual). Solo administracion.
        'key' => 'ventas_asesor.rentabilidad',
        'name' => 'ventasasesor::app.menu.rentabilidad',
        'route' => 'admin.ventas_asesor.rentabilidad.index',
        'sort' => 7,
        'icon' => '',
    ], [
        // Cotizaciones: datos de pago, vigencia y condiciones del PDF (abre Configuracion > Ventas Asesor > Cotizaciones).
        'key' => 'ventas_asesor.cotizaciones_datos',
        'name' => 'ventasasesor::app.menu.cotizaciones-datos',
        'route' => 'admin.ventas_asesor.nueva_venta.cotizaciones.datos',
        'sort' => 8,
        'icon' => '',
    ], [
        'key' => 'ventas_asesor.pedidos.create',
        'name' => 'ventasasesor::app.menu.nueva-venta',
        'route' => 'admin.ventas_asesor.nueva_venta.pantalla',
        'sort' => 1,
        'icon' => '',
    ],
];
