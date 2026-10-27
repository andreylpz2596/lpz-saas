<?php

/*
 * ACL de ByLopez\Logistica (LOG-01 + Despacho V1). Mismas reglas de Bagisto 2.4.9 que VentasAsesor (ver su Config/acl.php):
 *
 * 1. Permisos PLANOS: una clave padre no concede sus hijas.
 * 2. Si varias entradas listan la misma ruta, la autoriza la ULTIMA. Por eso `logistica.despachos` va despues de las
 *    claves que usan la bandeja de despachos como ruta de relleno, y la raiz `logistica` va al final.
 * 3. Toda clave apunta primero a una ruta GET sin parametros (redirect tras el login).
 *
 * Roles:
 *  - BODEGA (#4): logistica, .despachos, .despachos.despachar, .entregas, .entregas.registrar, .guias, .guias.ver,
 *    .guias.archivo. Sin permisos comerciales: no ve margen, costos, comision ni utilidad.
 *  - ADMIN / SUPERVISION: ademas .entregas.anular y .transportadoras(.manage). Un rol `all` lo tiene todo.
 *  - ASESOR VENTAS: ninguna `logistica.*`. Ve la guia de SUS pedidos por una ruta de Ventas Asesor (AccesoGuiaPedido).
 * Los servicios validan siempre; ocultar un boton no autoriza nada.
 *
 * Las claves reservadas `ventas_asesor.logistica(.view/.manage)` de VentasAsesor quedan SIN USO.
 */
$bandeja = 'admin.logistica.entregas.index';
$despachos = 'admin.logistica.despachos.index';
$catalogo = 'admin.logistica.transportadoras.index';
$cuentas = 'admin.logistica.cuentas.index';

return [
    [
        'key' => 'logistica.entregas.registrar',
        'name' => 'logistica::app.acl.registrar',
        'route' => [
            $bandeja,
            'admin.logistica.entregas.crear',
            'admin.logistica.entregas.store',
            'admin.logistica.retornos.crear',
            'admin.logistica.retornos.store',
            'admin.logistica.entregas.drawer.entregar',
            'admin.logistica.entregas.drawer.devolver',
        ],
        'sort' => 1,
    ], [
        // Recibir fisicamente una devolucion en bodega (reingresa las unidades aptas al inventario de la bodega del despacho).
        'key' => 'logistica.entregas.recibir_devolucion',
        'name' => 'logistica::app.acl.recibir_devolucion',
        'route' => [$bandeja, 'admin.logistica.entregas.recepcion.store'],
        'sort' => 2,
    ], [
        // Validar la entrega (hito administrativo) y registrar el cobro contra entrega por Cartera desde el drawer.
        'key' => 'logistica.entregas.validar',
        'name' => 'logistica::app.acl.validar',
        'route' => [$bandeja, 'admin.logistica.entregas.drawer.validar', 'admin.logistica.entregas.drawer.anular_validacion', 'admin.logistica.entregas.drawer.cobrar'],
        'sort' => 2,
    ], [
        'key' => 'logistica.entregas.anular',
        'name' => 'logistica::app.acl.anular',
        'route' => [$bandeja, 'admin.logistica.entregas.anular', 'admin.logistica.retornos.anular', 'admin.logistica.entregas.drawer.anular_entrega', 'admin.logistica.entregas.drawer.anular_devolucion', 'admin.logistica.entregas.drawer.anular_recepcion'],
        'sort' => 2,
    ], [
        'key' => 'logistica.entregas',
        'name' => 'logistica::app.acl.entregas',
        'route' => [$bandeja, 'admin.logistica.entregas.drawer.contexto'],
        'sort' => 2,
    ], [
        // Registrar guia y despachar / completar un despacho / entregar en bodega (POST). Valida de nuevo el servicio.
        'key' => 'logistica.despachos.despachar',
        'name' => 'logistica::app.acl.despachar',
        // F6: la ruta de la decision de cobro va aqui (cobrar al entregar = quien despacha); el credito lo revalida el servicio.
        'route' => [$despachos, 'admin.logistica.despachos.despachar', 'admin.logistica.despachos.entregar_bodega', 'admin.logistica.despachos.disposicion_cobro'],
        'sort' => 1,
    ], [
        // F6: despachar SIN cobro un pedido con saldo pendiente (credito). Autoridad administrativa: ningun rol la trae por defecto.
        'key' => 'logistica.despachos.autorizar_credito',
        'name' => 'logistica::app.acl.autorizar-credito',
        'route' => $despachos,
        'sort' => 2,
    ], [
        'key' => 'logistica.guias.archivo',
        'name' => 'logistica::app.acl.guia-archivo',
        'route' => [$despachos, 'admin.logistica.guias.archivo.guardar'],
        'sort' => 2,
    ], [
        'key' => 'logistica.guias.corregir',
        'name' => 'logistica::app.acl.guia-corregir',
        'route' => [$despachos, 'admin.logistica.guias.corregir'],
        'sort' => 3,
    ], [
        'key' => 'logistica.guias.ver',
        'name' => 'logistica::app.acl.guia-ver',
        'route' => [$despachos, 'admin.logistica.guias.archivo'],
        'sort' => 1,
    ], [
        'key' => 'logistica.guias',
        'name' => 'logistica::app.acl.guias',
        'route' => $despachos,
        'sort' => 3,
    ], [
        // BODEGA: pedir autorizacion de stock con el conteo fisico. Nunca corrige inventario.
        'key' => 'logistica.stock.solicitar',
        'name' => 'logistica::app.acl.stock-solicitar',
        'route' => [$despachos, 'admin.logistica.stock.solicitar'],
        'sort' => 1,
    ], [
        // ADMINISTRADOR: regularizar (subir al conteo fisico) o rechazar; tambien en una sola operacion.
        'key' => 'logistica.stock.regularizar',
        'name' => 'logistica::app.acl.stock-regularizar',
        'route' => [$despachos, 'admin.logistica.stock.directa', 'admin.logistica.incidencias.regularizar', 'admin.logistica.incidencias.rechazar'],
        'sort' => 2,
    ], [
        // Ver incidencias (pestaña de Despachos) y su detalle de revision.
        'key' => 'logistica.stock',
        'name' => 'logistica::app.acl.stock',
        'route' => [$despachos, 'admin.logistica.incidencias.detalle'],
        'sort' => 4,
    ], [
        // Va DESPUES de las que usan la bandeja como relleno: es la que autoriza `despachos` y su contexto.
        'key' => 'logistica.despachos',
        'name' => 'logistica::app.acl.despachos',
        'route' => [$despachos, 'admin.logistica.despachos.contexto'],
        'sort' => 1,
    ], [
        // ADMIN: cerrar una liquidacion de cuenta corriente (queda a nombre de quien la confirma).
        'key' => 'logistica.cuentas.liquidar',
        'name' => 'logistica::app.acl.cuentas-liquidar',
        'route' => [$cuentas, 'admin.logistica.cuentas.liquidar'],
        'sort' => 1,
    ], [
        // ADMIN: ajustes, costos de devolucion y resolver envios «Requiere revision». Nunca editar un saldo.
        'key' => 'logistica.cuentas.ajustar',
        'name' => 'logistica::app.acl.cuentas-ajustar',
        'route' => [$cuentas, 'admin.logistica.cuentas.ajustar', 'admin.logistica.cuentas.revision'],
        'sort' => 2,
    ], [
        // Va DESPUES de sus hijas (que usan el listado como relleno): ver cuentas, movimientos, cuadre e historico (BODEGA y ADMIN).
        'key' => 'logistica.cuentas',
        'name' => 'logistica::app.acl.cuentas',
        'route' => [$cuentas, 'admin.logistica.cuentas.contexto', 'admin.logistica.cuentas.liquidacion'],
        'sort' => 5,
    ], [
        'key' => 'logistica.transportadoras.manage',
        'name' => 'logistica::app.acl.gestionar',
        'route' => [$catalogo, 'admin.logistica.transportadoras.store', 'admin.logistica.transportadoras.estado', 'admin.logistica.transportadoras.capacidades', 'admin.logistica.tarifas.store', 'admin.logistica.zonas_locales.guardar'],
        'sort' => 1,
    ], [
        'key' => 'logistica.zonas_locales',
        'name' => 'logistica::app.acl.zonas_locales',
        'route' => 'admin.logistica.zonas_locales.index',
        'sort' => 5,
    ], [
        'key' => 'logistica.transportadoras',
        'name' => 'logistica::app.acl.transportadoras',
        'route' => $catalogo,
        'sort' => 4,
    ], [
        'key' => 'logistica',
        'name' => 'logistica::app.acl.logistica',
        'route' => $bandeja,
        'sort' => 12,
    ],
];
