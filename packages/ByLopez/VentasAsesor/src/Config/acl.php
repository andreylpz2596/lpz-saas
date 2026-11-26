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
 * Cada operacion debe protegerse con su clave hoja explicita. ('pedidos' abre la ruta
 * del listado "Mis ventas", pero el controlador exige view_own/view_all.)
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
        /*
         * "Mis ventas / Pedidos": el Bouncer solo deja ENTRAR a la ruta; el controlador exige
         * ademas view_own o view_all (403 si no) y acota el listado por alcance. El arbol de
         * Roles marca esta clave al marcar cualquiera de sus hojas, asi que todo rol con
         * view_own/view_all la tiene. String (no arreglo): puede ser la primera clave con
         * punto de un rol y SessionController hace redirect()->route() sin indexar (regla 3).
         */
        'route' => 'admin.ventas_asesor.pedidos.index',
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
            // P7: la venta nace con la primera prenda (consultar productos no crea borradores).
            'admin.ventas_asesor.nueva_venta.iniciar_con_tallas',
            'admin.ventas_asesor.nueva_venta.iniciar_cliente_nuevo',
            'admin.ventas_asesor.nueva_venta.lineas.colores',
            // Doc. 34: pedido por voz (solo interpreta y valida contra el catalogo).
            'admin.ventas_asesor.nueva_venta.dictado.interpretar',
            'admin.ventas_asesor.nueva_venta.dictado.corregir',
            'admin.ventas_asesor.nueva_venta.productos.matriz',
            'admin.ventas_asesor.nueva_venta.matrices',
            'admin.ventas_asesor.nueva_venta.estado_inicial',
            'admin.ventas_asesor.nueva_venta.estado',
            'admin.ventas_asesor.nueva_venta.tallas',
            'admin.ventas_asesor.nueva_venta.entrega',
            // Cierre F1 (D1): quien entrega un domicilio local (opcional).
            'admin.ventas_asesor.nueva_venta.domiciliario',
            'admin.ventas_asesor.nueva_venta.pago',
            'admin.ventas_asesor.nueva_venta.confirmar',
            // 03G-D: la pantalla HTML y el listado de borradores activos del asesor.
            'admin.ventas_asesor.nueva_venta.pantalla',
            'admin.ventas_asesor.nueva_venta.en_curso',
            // 03G-D (rediseno): direcciones existentes del cliente y eliminar venta en curso
            // (BorradorVentaService::descartar, ya existente y con ownership propio).
            'admin.ventas_asesor.nueva_venta.clientes.direcciones',
            'admin.ventas_asesor.nueva_venta.eliminar',
            // 03G-D (entrega): agregar direccion reutilizable al cliente del borrador (RT-04).
            'admin.ventas_asesor.nueva_venta.direcciones.agregar',
            // Editar una direccion guardada del cliente del borrador (corrige errores de digitacion; mismas reglas que agregar).
            'admin.ventas_asesor.nueva_venta.direcciones.editar',
            // GRP-01: ver el historial de grupo de la propia venta (solo lectura; cambiar/restaurar exigen su clave propia).
            'admin.ventas_asesor.nueva_venta.grupo.historial',
            // R2 (Doc. 23): advertencia de rentabilidad del propio borrador (sin costos para el asesor).
            'admin.ventas_asesor.nueva_venta.rentabilidad',
            // Cotizaciones de la venta en curso (crear, historial y PDF): quien puede crear una venta puede cotizarla.
            'admin.ventas_asesor.nueva_venta.cotizaciones.store',
            'admin.ventas_asesor.nueva_venta.cotizaciones.index',
            'admin.ventas_asesor.nueva_venta.cotizaciones.pdf',
            // D-58: autocomplete «Departamento — Municipio» del formulario de direccion.
            'admin.ventas_asesor.ubicaciones.buscar',
        ],
        'sort' => 1,
    ], [
        'key' => 'ventas_asesor.pedidos.view_own',
        'name' => 'ventasasesor::app.acl.ver-propios',
        // Despacho V1: ademas del inicio, autoriza la ruta con la que el ASESOR ve / descarga la guia de sus pedidos
        // (la propiedad del pedido la valida Logistica en el servicio). $inicio sigue en la posicion 0 (regla 3).
        'route' => [$inicio, 'admin.ventas_asesor.pedidos.guia', 'admin.ventas_asesor.pedidos.factura', 'admin.ventas_asesor.pedidos.mensajes.opciones', 'admin.ventas_asesor.pedidos.mensajes.mensaje'],
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
        // G-11 / COM-D-15: elegir muestra / reposicion / garantia en Nueva venta (sin ella, solo venta normal). La
        // valida ConfirmarVentaService; no abre rutas propias.
        'key' => 'ventas_asesor.pedidos.tipo_operacion',
        'name' => 'ventasasesor::app.acl.tipo-operacion',
        'route' => $inicio,
        'sort' => 6,
    ], [
        // GRP-01: aplicar a UNA venta un grupo comercial temporal (y restaurar el habitual). No modifica al cliente.
        // $inicio va primero (regla 3); las rutas de cambio quedan SOLO bajo esta clave (regla 2: la ultima entrada que
        // lista una ruta la autoriza), y el servicio vuelve a validar el permiso.
        'key' => 'ventas_asesor.pedidos.cambiar_grupo_temporal',
        'name' => 'ventasasesor::app.acl.cambiar-grupo-temporal',
        'route' => [
            $inicio,
            'admin.ventas_asesor.nueva_venta.grupo.cambiar',
            'admin.ventas_asesor.nueva_venta.grupo.restaurar',
        ],
        'sort' => 7,
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
        /*
         * Doc. 23 (R3/R4): como `pedidos`, la agrupadora abre la pantalla Comisiones (string, regla 3) y el controlador
         * exige `view_own` (solo lo propio, sin costos) o `view_all`. Capacidades SEPARADAS (el servicio las vuelve a
         * exigir): `manage` = configurar % y umbral por asesor; `calcular` = reevaluar; `approve` = crear/aprobar
         * liquidaciones y resolver ajustes; `pay` = registrar abonos; `ajustar` = proponer ajustes; `revertir` = anular
         * liquidaciones y revertir abonos; `comprobantes` = descargar comprobantes (el asesor solo los suyos).
         */
        'key' => 'ventas_asesor.comisiones',
        'name' => 'ventasasesor::app.acl.comisiones',
        'route' => 'admin.ventas_asesor.comisiones.index',
        'sort' => 5,
    ], [
        'key' => 'ventas_asesor.comisiones.view_own',
        'name' => 'ventasasesor::app.acl.ver-propios',
        // «Mis comisiones»: ruta propia de la asesora (el servicio solo atiende al actor autenticado).
        'route' => [$inicio, 'admin.ventas_asesor.comisiones.mias', 'admin.ventas_asesor.comisiones.simular'],
        'sort' => 1,
    ], [
        'key' => 'ventas_asesor.comisiones.view_all',
        'name' => 'ventasasesor::app.acl.ver-todos',
        'route' => $inicio,
        'sort' => 2,
    ], [
        'key' => 'ventas_asesor.comisiones.manage',
        'name' => 'ventasasesor::app.acl.administrar',
        'route' => [$inicio, 'admin.ventas_asesor.comisiones.acuerdos.store'],
        'sort' => 3,
    ], [
        'key' => 'ventas_asesor.comisiones.approve',
        'name' => 'ventasasesor::app.acl.aprobar',
        'route' => [
            $inicio,
            'admin.ventas_asesor.comisiones.liquidaciones.store',
            'admin.ventas_asesor.comisiones.liquidaciones.aprobar',
            'admin.ventas_asesor.comisiones.ajustes.resolver',
            'admin.ventas_asesor.comisiones.incidencias.resolver',
        ],
        'sort' => 4,
    ], [
        'key' => 'ventas_asesor.comisiones.pay',
        'name' => 'ventasasesor::app.acl.pagar',
        'route' => [$inicio, 'admin.ventas_asesor.comisiones.abonos.store', 'admin.ventas_asesor.comisiones.soportes.leer', 'admin.ventas_asesor.comisiones.soportes.ver'],
        'sort' => 5,
    ], [
        'key' => 'ventas_asesor.comisiones.calcular',
        'name' => 'ventasasesor::app.acl.calcular-comisiones',
        'route' => [$inicio, 'admin.ventas_asesor.comisiones.evaluar'],
        'sort' => 6,
    ], [
        'key' => 'ventas_asesor.comisiones.ajustar',
        'name' => 'ventasasesor::app.acl.ajustar-comisiones',
        'route' => [$inicio, 'admin.ventas_asesor.comisiones.ajustes.store'],
        'sort' => 7,
    ], [
        'key' => 'ventas_asesor.comisiones.revertir',
        'name' => 'ventasasesor::app.acl.revertir-comisiones',
        'route' => [$inicio, 'admin.ventas_asesor.comisiones.liquidaciones.anular', 'admin.ventas_asesor.comisiones.abonos.revertir'],
        'sort' => 8,
    ], [
        'key' => 'ventas_asesor.comisiones.comprobantes',
        'name' => 'ventasasesor::app.acl.comprobantes-comisiones',
        'route' => [$inicio, 'admin.ventas_asesor.comisiones.abonos.comprobante'],
        'sort' => 9,
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
        /*
         * PAG-B (D-59 / P-08): rama de CARTERA. Solo infraestructura de permisos; el servicio SIEMPRE valida
         * (I-10), no los botones. Registrar y revisar son capacidades distintas: quien registra un pago no lo
         * confirma. Ninguna ruta propia todavia: apuntan a $inicio (regla 3) hasta que PAG-C cree la suya; la
         * agrupadora lleva una ruta STRING (no arreglo) por el redirect de login. NO se asigna a ningun rol
         * existente (P-12: tampoco se quita todavia `sales.transactions`: eso es del despliegue).
         */
        'key' => 'ventas_asesor.cartera',
        'name' => 'ventasasesor::app.acl.cartera',
        // PAG-D/PAG-E: como `pedidos`, la agrupadora abre la pantalla Cartera / Pagos (bandeja, `?pago=ID` y
        // `?cliente=ID`); el controlador exige view_own o view_all. String (no arreglo) por el redirect de login.
        'route' => 'admin.ventas_asesor.cartera.index',
        'sort' => 7,
    ], [
        'key' => 'ventas_asesor.cartera.view_own',
        'name' => 'ventasasesor::app.acl.ver-propios',
        'route' => $inicio,
        'sort' => 1,
    ], [
        'key' => 'ventas_asesor.cartera.view_all',
        'name' => 'ventasasesor::app.acl.ver-todos',
        // Estado de cuenta de WhatsApp del cliente: solo con alcance «todos» ($inicio sigue primero, regla 3).
        'route' => [
            $inicio,
            'admin.ventas_asesor.cartera.mensajes.opciones',
            'admin.ventas_asesor.cartera.mensajes.mensaje',
        ],
        'sort' => 2,
    ], [
        'key' => 'ventas_asesor.cartera.registrar',
        'name' => 'ventasasesor::app.acl.registrar-pago',
        // PAG-C: $inicio va primero (regla 3); la ruta POST autoriza registrar un pago desde el pedido.
        'route' => [
            $inicio,
            'admin.ventas_asesor.pedidos.pagos.store',
        ],
        'sort' => 3,
    ], [
        'key' => 'ventas_asesor.cartera.abono_general',
        'name' => 'ventasasesor::app.acl.abono-general',
        // PAG-D: la ruta POST autoriza registrar un abono multipedido ($inicio sigue primero, regla 3).
        'route' => [
            $inicio,
            'admin.ventas_asesor.cartera.abonos.store',
        ],
        'sort' => 4,
    ], [
        'key' => 'ventas_asesor.cartera.revisar',
        'name' => 'ventasasesor::app.acl.revisar-pagos',
        // PAG-E: las rutas POST autorizan confirmar y rechazar un pago ($inicio sigue primero, regla 3).
        'route' => [
            $inicio,
            'admin.ventas_asesor.cartera.pagos.confirmar',
            'admin.ventas_asesor.cartera.pagos.rechazar',
        ],
        'sort' => 5,
    ], [
        'key' => 'ventas_asesor.cartera.reversar',
        'name' => 'ventasasesor::app.acl.reversar-pagos',
        'route' => $inicio,
        'sort' => 6,
    ], [
        // D10 V1: credito del cliente. Hojas SEPARADAS (la agrupadora no autoriza nada); el servicio valida siempre. Sin asignar a ningun rol.
        'key' => 'ventas_asesor.cartera.credito',
        'name' => 'ventasasesor::app.acl.credito',
        'route' => $inicio,
        'sort' => 7,
    ], [
        'key' => 'ventas_asesor.cartera.credito.ver',
        'name' => 'ventasasesor::app.acl.credito-ver',
        'route' => [$inicio, 'admin.ventas_asesor.cartera.credito.pedido', 'admin.ventas_asesor.cartera.credito.cliente', 'admin.ventas_asesor.cartera.credito.comprobante'],
        'sort' => 1,
    ], [
        'key' => 'ventas_asesor.cartera.credito.aplicar',
        'name' => 'ventasasesor::app.acl.credito-aplicar',
        'route' => [$inicio, 'admin.ventas_asesor.cartera.credito.aplicar'],
        'sort' => 2,
    ], [
        'key' => 'ventas_asesor.cartera.credito.reembolsar',
        'name' => 'ventasasesor::app.acl.credito-reembolsar',
        'route' => [$inicio, 'admin.ventas_asesor.cartera.credito.reembolsar'],
        'sort' => 3,
    ], [
        'key' => 'ventas_asesor.cartera.credito.reversar',
        'name' => 'ventasasesor::app.acl.credito-reversar',
        'route' => [$inicio, 'admin.ventas_asesor.cartera.credito.reversar'],
        'sort' => 4,
    ], [
        'key' => 'ventas_asesor.cartera.credito.ajustar',
        'name' => 'ventasasesor::app.acl.credito-ajustar',
        'route' => [$inicio, 'admin.ventas_asesor.cartera.credito.ajustar', 'admin.ventas_asesor.cartera.credito.ajuste_reversar'],
        'sort' => 5,
    ], [
        /*
         * ASESOR-01: ficha de asesores. Como `pedidos`, la agrupadora abre la pantalla (string, regla 3) y el
         * controlador exige `view_own` (solo la ficha propia) o `view_all`. `manage` = crear, editar y activar.
         */
        'key' => 'ventas_asesor.asesores',
        'name' => 'ventasasesor::app.acl.asesores',
        'route' => 'admin.ventas_asesor.asesores.index',
        'sort' => 8,
    ], [
        'key' => 'ventas_asesor.asesores.view_own',
        'name' => 'ventasasesor::app.acl.ver-propios',
        'route' => $inicio,
        'sort' => 1,
    ], [
        'key' => 'ventas_asesor.asesores.view_all',
        'name' => 'ventasasesor::app.acl.ver-todos',
        'route' => $inicio,
        'sort' => 2,
    ], [
        'key' => 'ventas_asesor.asesores.manage',
        'name' => 'ventasasesor::app.acl.gestionar-asesores',
        'route' => [
            $inicio,
            'admin.ventas_asesor.asesores.store',
            'admin.ventas_asesor.asesores.update',
            'admin.ventas_asesor.asesores.estado',
        ],
        'sort' => 3,
    ], [
        /*
         * D-COST-04/05: «Control de costos». La agrupadora abre la pantalla (string, regla 3) y es la que autoriza
         * VER la comparacion; la hoja `actualizar` autoriza la vista previa y la confirmacion de la actualizacion
         * masiva del costo comisionable. Solo administracion: el rol de asesor no la recibe.
         */
        'key' => 'ventas_asesor.costos',
        'name' => 'ventasasesor::app.acl.costos',
        'route' => 'admin.ventas_asesor.costos.index',
        'sort' => 9,
    ], [
        'key' => 'ventas_asesor.costos.actualizar',
        'name' => 'ventasasesor::app.acl.actualizar-costos',
        'route' => [
            $inicio,
            'admin.ventas_asesor.costos.previsualizar',
            'admin.ventas_asesor.costos.actualizar',
        ],
        'sort' => 1,
    ], [
        /*
         * Doc. 32: «Precios por grupo», SEPARADO de costos. La agrupadora abre la consulta (string, regla 3) y su
         * comparacion entre grupos; la hoja `editar` autoriza vista previa, confirmacion y reintento de reindexacion.
         * Ninguna de las dos da acceso a costos ni margenes (eso sigue siendo `ventas_asesor.costos`). El menu muestra una
         * sola entrada «Costos y precios» con cualquiera de las dos agrupadoras (VentasAsesorServiceProvider).
         */
        'key' => 'ventas_asesor.precios_grupo',
        'name' => 'ventasasesor::app.acl.precios-grupo',
        'route' => 'admin.ventas_asesor.precios_grupo.index',
        'sort' => 10,
    ], [
        'key' => 'ventas_asesor.precios_grupo.editar',
        'name' => 'ventasasesor::app.acl.precios-grupo-editar',
        'route' => [
            $inicio,
            'admin.ventas_asesor.precios_grupo.previsualizar',
            'admin.ventas_asesor.precios_grupo.confirmar',
            'admin.ventas_asesor.precios_grupo.reindexar',
        ],
        'sort' => 1,
    ], [
        /*
         * Doc. 23 (R1/R4): «Rentabilidad». Agrupadora = pantalla (string, regla 3); el controlador exige `view` (diagnostico
         * de listas y configuracion faltante), `configurar` (politicas, costos directos, estado del costo) o `kpis.view_all`
         * (panel mensual). Solo administracion: el rol de asesor no la recibe.
         */
        'key' => 'ventas_asesor.rentabilidad',
        'name' => 'ventasasesor::app.acl.rentabilidad',
        'route' => 'admin.ventas_asesor.rentabilidad.index',
        'sort' => 10,
    ], [
        'key' => 'ventas_asesor.rentabilidad.view',
        'name' => 'ventasasesor::app.acl.ver-todos',
        'route' => $inicio,
        'sort' => 1,
    ], [
        'key' => 'ventas_asesor.rentabilidad.configurar',
        'name' => 'ventasasesor::app.acl.configurar-rentabilidad',
        'route' => [
            $inicio,
            'admin.ventas_asesor.rentabilidad.politicas.store',
            'admin.ventas_asesor.rentabilidad.costos_directos.store',
            'admin.ventas_asesor.rentabilidad.estados_costo.store',
        ],
        'sort' => 2,
    ], [
        // Cotizaciones: acceso del administrador a los datos de pago del PDF. Solo es el ENLACE del menu: la pantalla real es
        // Configuracion de Bagisto y exige su propia clave `configuration`. Sin asignar a ningun rol.
        'key' => 'ventas_asesor.cotizaciones_datos',
        'name' => 'ventasasesor::app.acl.cotizaciones-datos',
        'route' => [
            $inicio,
            'admin.ventas_asesor.nueva_venta.cotizaciones.datos',
        ],
        'sort' => 12,
    ], [
        // Doc. 33 P3/P5: autoservicio del cliente frecuente. Arbol PROPIO (no cuelga de ventas_asesor): marcar estas claves nunca
        // concede la raiz ventas_asesor ni su pantalla de inicio.
        // Regla 3 (cabecera): las HOJAS (autoservicio.comprar / .pedidos) llevan ruta en TEXTO porque SessionController redirige
        // tras el login a la primera hoja accesible; las rutas de la API van en la clave de grupo `autoservicio`, y cada operacion
        // exige ademas su hoja en ClienteActualService (defensa en profundidad).
        'key' => 'autoservicio',
        'name' => 'ventasasesor::app.acl.autoservicio',
        'route' => [
            'admin.autoservicio.estado_inicial',
            'admin.autoservicio.lineas.colores',
            'admin.autoservicio.matrices',
            'admin.autoservicio.productos.matriz',
            'admin.autoservicio.en_curso',
            'admin.autoservicio.iniciar_con_tallas',
            'admin.autoservicio.estado',
            'admin.autoservicio.tallas',
            'admin.autoservicio.eliminar',
            'admin.autoservicio.preparar',
            'admin.autoservicio.direcciones',
            'admin.autoservicio.direcciones.agregar',
            'admin.autoservicio.entrega',
            'admin.autoservicio.domiciliario',
            'admin.autoservicio.ubicaciones',
            'admin.autoservicio.confirmar',
            'admin.autoservicio.pedidos.data',
            'admin.autoservicio.pedidos.show',
        ],
        'sort' => 20,
    ], [
        'key' => 'autoservicio.comprar',
        'name' => 'ventasasesor::app.acl.autoservicio-comprar',
        'route' => 'admin.autoservicio.pantalla',
        'sort' => 1,
    ], [
        'key' => 'autoservicio.pedidos',
        'name' => 'ventasasesor::app.acl.autoservicio-pedidos',
        'route' => 'admin.autoservicio.pedidos.index',
        'sort' => 2,
    ], [
        // Doc. 33 P1: administrar el acceso de clientes al aplicativo (crear/vincular usuario, suspender, reactivar, desvincular).
        // Sin asignar a ningun rol: la concedel responsable funcional.
        'key' => 'ventas_asesor.clientes_acceso',
        'name' => 'ventasasesor::app.acl.clientes-acceso',
        'route' => $inicio,
        'sort' => 13,
    ], [
        'key' => 'ventas_asesor.clientes_acceso.manage',
        'name' => 'ventasasesor::app.acl.clientes-acceso-administrar',
        'route' => [
            $inicio,
            'admin.ventas_asesor.clientes_acceso.mostrar',
            'admin.ventas_asesor.clientes_acceso.crear_usuario',
            'admin.ventas_asesor.clientes_acceso.vincular',
            'admin.ventas_asesor.clientes_acceso.suspender',
            'admin.ventas_asesor.clientes_acceso.reactivar',
            'admin.ventas_asesor.clientes_acceso.desvincular',
        ],
        'sort' => 1,
    ], [
        // Debe ser la ultima entrada: ver la regla 2 del encabezado.
        'key' => 'ventas_asesor',
        'name' => 'ventasasesor::app.acl.title',
        'route' => $inicio,
        'sort' => 11,
    ],
];
