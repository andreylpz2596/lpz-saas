# 01 · Matriz de conservación y limpieza por tabla

Generada con `tools/limpieza-preproduccion/generar_matriz.php` a partir de la **misma** clasificación que ejecuta el limpiador (`limpiar_base_listas.php`), con las decisiones de las dos rondas (2026-11-15). «Antes» = restauración verificada del respaldo de `lpz_saas`; «Después» = `lpz_saas_preproduccion_limpia` final. «Depende de» = claves foráneas declaradas; «La usan» = número de tablas con FK hacia ella. «—» en «Antes» = tabla creada después del respaldo (migración nueva).

Clasificación: **CONSERVAR** (maestro/configuración; contenido idéntico fila a fila salvo los ajustes indicados en «Acción»), **VACIAR** (DELETE completo), **VACIAR (parcial)** (solo las filas del filtro: productos de prueba, cuentas distintas de la conservada, roles QA). Ajustes sobre filas conservadas: inventario a cero, sufijo de facturas en `core_config` (numeración nativa de Bagisto, sin clase generadora), vínculos de talleres/cortadores anulados, índice de inventario reconstruido.

## Relaciones sin clave foránea revisadas (verificador: 16, sin huérfanos)
| Columna | Apunta a | Resultado |
|---|---|---|
| Auditoría en tablas conservadas: `creado_por_admin_id`, `actor_admin_id`, `revision_admin_id`, `anulada_por_admin_id`, `creada_por_admin_id` (tarifas, tipos y versiones de cargue, comparaciones, reglas, costos, políticas) | `admins` | Todas apuntan a la cuenta conservada (id 1) o son nulas |
| `bylopez_va_costo_comisionable_cambios.product_id` | `products` | Se borraron las 4 filas de productos de prueba; resto sin huérfanos |
| `bylopez_va_costo_estados.familia_id` | `attribute_families` | Sin huérfanos |
| `admins.role_id` | `roles` | La cuenta conservada usa el rol 1 (acceso total) |
| `product_flat.parent_id` | `products` | Sin huérfanos |
| `bylopez_cc_talleres.usuario_id`, `bylopez_cc_cortadores.usuario_id` (FK SET NULL) | `admins` | Anuladas antes de borrar las cuentas: las 3 fichas se conservan sin cuenta |
| `bylopez_va_asesores.admin_id` (FK RESTRICT, NOT NULL) | `admins` | El modelo no permite desvincular: fichas y acuerdo se vacían con sus cuentas |
| Polimórficas / JSON con ids | — | Solo en tablas vaciadas; `core_config` guarda rutas de logo y nombres de clase, no ids de operaciones |

## Archivos asociados (no se borró ningún archivo compartido)
| Carpeta en `storage/app` | Referenciada por | Entrega limpia |
|---|---|---|
| `public/product`, `category`, `channel`, `locales`, `public/configuration`, `public/theme` | catálogo, canal, configuración | **Incluir** |
| `admins/` | foto de perfil de cuentas | Incluir solo la de la cuenta conservada, si existe |
| `cargues/2026/...` | `bylopez_cg_cargas.archivo_ruta` (vaciada) | **Excluir** (archivos reales de clientes e inventario) |
| `cartera_soportes/` | `bylopez_cc_cartera_soportes` (vaciada) | **Excluir** |
| `private/logistica/guias` | `bylopez_lg_guia_archivos` (vaciada) | **Excluir** |
| `private/ventas-asesor` | comprobantes de pagos (vaciados) | **Excluir** |
| `private/tmp`, `tessdata` | temporales / OCR | No necesarios |

## Matriz
| Tabla | Función | Antes | Después | Depende de | La usan | Clasificación | Acción | Motivo |
|---|---|---:|---:|---|---|---|---|---|
| `addresses` | Direcciones: de cliente (se conservan) y copias en carritos/pedidos (se borran) | 270 | 0 | cart, customers, orders | — | VACIAR | DELETE completo | Clientes y sus dependientes: se vuelven a cargar con Cargues (decisión ronda 2) |
| `admin_password_resets` | Usuarios del panel | 0 | 0 | — | — | VACIAR | DELETE completo | Sesiones y tokens: se invalidan |
| `admins` | Usuarios del panel | 13 | 1 | — | 15 tabla(s) | VACIAR (parcial) | DELETE WHERE id <> 1 | Solo se conserva la cuenta administrativa real (id 1) |
| `agent_conversation_messages` | Bagisto / sistema | 0 | 0 | — | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `agent_conversations` | Bagisto / sistema | 0 | 0 | — | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `attribute_families` | Atributos y opciones | 5 | 5 | — | 5 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `attribute_group_mappings` | Atributos y opciones | 156 | 156 | attribute_groups, attributes | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `attribute_groups` | Atributos y opciones | 35 | 35 | attribute_families | 1 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `attribute_option_translations` | Atributos y opciones | 60 | 60 | attribute_options | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `attribute_options` | Atributos y opciones | 71 | 71 | attributes | 6 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `attribute_translations` | Atributos y opciones | 34 | 34 | attributes | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `attributes` | Atributos y opciones | 34 | 34 | — | 6 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `booking_product_appointment_slots` | Reservas de producto (Bagisto) | 0 | 0 | booking_products | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `booking_product_default_slots` | Reservas de producto (Bagisto) | 0 | 0 | booking_products | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `booking_product_event_ticket_translations` | Reservas de producto (Bagisto) | 0 | 0 | booking_product_event_tickets | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `booking_product_event_tickets` | Reservas de producto (Bagisto) | 0 | 0 | booking_products | 2 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `booking_product_rental_slots` | Reservas de producto (Bagisto) | 0 | 0 | booking_products | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `booking_product_table_slots` | Reservas de producto (Bagisto) | 0 | 0 | booking_products | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `booking_products` | Reservas de producto (Bagisto) | 0 | 0 | products | 5 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `bookings` | Reservas de producto (Bagisto) | 0 | 0 | booking_product_event_tickets, orders, order_items, products | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_cartera_aplicaciones` | Corte y Confección | 3 | 0 | admins, bylopez_cc_cartera_liquidaciones, bylopez_cc_cartera_movimientos, bylopez_cc_ordenes_confeccion, bylopez_cc_ordenes_corte | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_cartera_config` | Corte y Confección | 1 | 1 | admins | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_cc_cartera_liquidaciones` | Corte y Confección | 0 | 0 | bylopez_cc_cortadores, admins, bylopez_cc_talleres | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_cartera_movimientos` | Corte y Confección | 3 | 0 | bylopez_cc_cortadores, admins, bylopez_cc_talleres | 3 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_cartera_operaciones` | Corte y Confección | 2 | 0 | bylopez_cc_cartera_movimientos, bylopez_cc_cartera_soportes | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_cartera_soportes` | Corte y Confección | 4 | 0 | bylopez_cc_cortadores, bylopez_cc_cartera_movimientos, admins, bylopez_cc_talleres | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_compras_tela` | Corte y Confección | 9 | 0 | bylopez_cc_proveedores_tela | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_cortadores` | Corte y Confección | 1 | 1 | admins | 5 tabla(s) | CONSERVAR | UPDATE SET usuario_id = NULL WHERE usuario_id <> 1 | Ficha maestra conservada, desvinculada de la cuenta eliminada |
| `bylopez_cc_cuenta_eventos` | Corte y Confección | 19 | 0 | bylopez_cc_cortadores, bylopez_cc_talleres | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_inventario_movimientos` | Corte y Confección | 27 | 0 | inventory_sources, bylopez_cc_ordenes_confeccion, products, bylopez_cc_ordenes_confeccion_retorno_detalles, bylopez_cc_ordenes_confeccion_retornos | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_notificaciones` | Corte y Confección | 1 | 0 | admins, bylopez_cc_prioridad_operaciones | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_orden_corte_cantidad_audits` | Corte y Confección | 46 | 0 | bylopez_cc_ordenes_corte_detalles, bylopez_cc_ordenes_corte | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_orden_corte_rollos` | Corte y Confección | 27 | 0 | bylopez_cc_ordenes_corte, bylopez_cc_rollos | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_orden_tarifas` | Corte y Confección | 5 | 0 | bylopez_cc_ordenes_confeccion, bylopez_cc_ordenes_corte, attribute_families, bylopez_cc_tarifas_prestador, attribute_options | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_ordenes_confeccion` | Corte y Confección | 12 | 0 | bylopez_cc_tarifas_prestador, bylopez_cc_ordenes_corte, bylopez_cc_talleres | 7 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_ordenes_confeccion_detalles` | Corte y Confección | 48 | 0 | bylopez_cc_ordenes_confeccion | 3 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_ordenes_confeccion_retorno_detalles` | Corte y Confección | 27 | 0 | bylopez_cc_ordenes_confeccion_detalles, bylopez_cc_ordenes_confeccion_retornos | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_ordenes_confeccion_retornos` | Corte y Confección | 19 | 0 | bylopez_cc_ordenes_confeccion, admins, bylopez_cc_talleres | 2 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_ordenes_corte` | Corte y Confección | 13 | 0 | bylopez_cc_tarifas_prestador, admins, bylopez_cc_rollos | 8 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_ordenes_corte_detalles` | Corte y Confección | 74 | 0 | bylopez_cc_ordenes_corte | 2 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_prioridad_cambios` | Corte y Confección | 1 | 0 | bylopez_cc_prioridad_operaciones, bylopez_cc_ordenes_confeccion_detalles, bylopez_cc_ordenes_confeccion | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_prioridad_operaciones` | Corte y Confección | 1 | 0 | bylopez_cc_talleres | 2 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_proveedor_tela_referencias` | Corte y Confección | 11 | 11 | attribute_options, bylopez_cc_proveedores_tela | 1 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_cc_proveedor_tela_tipos` | Corte y Confección | 6 | 6 | bylopez_cc_proveedores_tela, attribute_options | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_cc_proveedores_tela` | Corte y Confección | 3 | 3 | — | 4 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_cc_reasignacion_lineas` | Corte y Confección | 0 | 0 | bylopez_cc_ordenes_confeccion_detalles, bylopez_cc_reasignaciones | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_reasignaciones` | Corte y Confección | 0 | 0 | admins, bylopez_cc_ordenes_corte, bylopez_cc_ordenes_confeccion, bylopez_cc_talleres | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_recepciones_corte` | Corte y Confección | 7 | 0 | bylopez_cc_ordenes_corte | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_recepciones_corte_detalles` | Corte y Confección | 42 | 0 | bylopez_cc_ordenes_corte_detalles, bylopez_cc_recepciones_corte | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_rollos` | Corte y Confección | 39 | 0 | attribute_options, bylopez_cc_compras_tela, bylopez_cc_proveedores_tela, bylopez_cc_proveedor_tela_referencias | 2 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_cc_stock_minimo_cambios` | Corte y Confección | 0 | 0 | admins | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_cc_stock_minimos` | Corte y Confección | 0 | 0 | admins, products | — | VACIAR (parcial) | DELETE WHERE product_id IN (productos de prueba) | Productos de prueba confirmados y sus dependencias exclusivas |
| `bylopez_cc_talleres` | Corte y Confección | 2 | 2 | admins | 9 tabla(s) | CONSERVAR | UPDATE SET usuario_id = NULL WHERE usuario_id <> 1 | Ficha maestra conservada, desvinculada de la cuenta eliminada |
| `bylopez_cc_tarifas_prestador` | Corte y Confección | 14 | 14 | bylopez_cc_cortadores, attribute_families, bylopez_cc_talleres, attribute_options | 3 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_cg_carga_datos` | Filas importadas de cada cargue | 3275 | 0 | bylopez_cg_cargas | — | VACIAR | DELETE completo | Ejecuciones y staging de Cargues (las definiciones y procedimientos se conservan) |
| `bylopez_cg_carga_incidencias` | Rechazos e incidencias de cada cargue | 1600 | 0 | bylopez_cg_cargas | — | VACIAR | DELETE completo | Ejecuciones y staging de Cargues (las definiciones y procedimientos se conservan) |
| `bylopez_cg_cargas` | Ejecuciones de cargues | 33 | 0 | bylopez_cg_comparaciones, bylopez_cg_tipos, bylopez_cg_tipo_versiones | 2 tabla(s) | VACIAR | DELETE completo | Ejecuciones y staging de Cargues (las definiciones y procedimientos se conservan) |
| `bylopez_cg_comparaciones` | Cargues: definición (tipos, versiones, estructuras, comparaciones) | 1 | 1 | bylopez_cg_tipos, bylopez_cg_tipo_versiones | 1 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_cg_estructuras` | Cargues: definición (tipos, versiones, estructuras, comparaciones) | 9 | 9 | bylopez_cg_tipos | 1 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_cg_tipo_versiones` | Cargues: definición (tipos, versiones, estructuras, comparaciones) | 13 | 13 | bylopez_cg_estructuras, bylopez_cg_tipos | 2 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_cg_tipos` | Cargues: definición (tipos, versiones, estructuras, comparaciones) | 5 | 5 | — | 4 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_cgi_clientes_historicos` | Staging de «Clientes históricos — 99 Envíos» | 258 | 0 | — | — | VACIAR | DELETE completo | Ejecuciones y staging de Cargues (las definiciones y procedimientos se conservan) |
| `bylopez_cgi_clientes_historicos__a1` | Staging de «Clientes históricos — 99 Envíos» | 18 | 0 | — | — | VACIAR | DELETE completo | Ejecuciones y staging de Cargues (las definiciones y procedimientos se conservan) |
| `bylopez_cgi_clientes_historicos__a2` | Staging de «Clientes históricos — 99 Envíos» | 197 | 0 | — | — | VACIAR | DELETE completo | Ejecuciones y staging de Cargues (las definiciones y procedimientos se conservan) |
| `bylopez_cgi_envios_completos` | Staging de cargues | 1283 | 0 | — | — | VACIAR | DELETE completo | Ejecuciones y staging de Cargues (las definiciones y procedimientos se conservan) |
| `bylopez_cgi_envios_completos__a1` | Staging de cargues | 78 | 0 | — | — | VACIAR | DELETE completo | Ejecuciones y staging de Cargues (las definiciones y procedimientos se conservan) |
| `bylopez_cgi_inventario_conteo` | Staging de cargues | 1320 | 0 | — | — | VACIAR | DELETE completo | Ejecuciones y staging de Cargues (las definiciones y procedimientos se conservan) |
| `bylopez_cgi_productos_camisetas` | Staging de cargues | 17 | 0 | — | — | VACIAR | DELETE completo | Ejecuciones y staging de Cargues (las definiciones y procedimientos se conservan) |
| `bylopez_cgi_productos_camisetas__a1` | Staging de cargues | 19 | 0 | — | — | VACIAR | DELETE completo | Ejecuciones y staging de Cargues (las definiciones y procedimientos se conservan) |
| `bylopez_cgi_prueba_e1` | Staging de cargues | 0 | 0 | — | — | VACIAR | DELETE completo | Ejecuciones y staging de Cargues (las definiciones y procedimientos se conservan) |
| `bylopez_geo_departamentos` | Geografía (DANE) | 33 | 33 | country_states | 1 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_geo_municipios` | Geografía (DANE) | 1122 | 1122 | bylopez_geo_departamentos | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_lg_cancelacion_lineas` | Logística | 0 | 0 | bylopez_lg_cancelaciones, order_items | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_cancelaciones` | Logística | 0 | 0 | orders | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_catalogo_defectos` | Logística | 25 | 25 | — | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_lg_conciliaciones_movimiento` | Logística | 2 | 0 | bylopez_lg_liquidaciones_transportadora, bylopez_lg_cuenta_movimientos | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_consecutivos` | Consecutivos de guías internas (GI) | 2 | 2 | — | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_lg_cuenta_movimientos` | Logística | 32 | 0 | bylopez_lg_guias, bylopez_lg_liquidaciones_transportadora, orders, shipments, bylopez_lg_transportadoras | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_disposiciones_cobro` | Logística | 3 | 0 | orders | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_entrega_items` | Logística | 218 | 0 | bylopez_lg_entregas, order_items, shipment_items | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_entregas` | Logística | 49 | 0 | orders, shipments | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_guia_archivos` | Logística | 6 | 0 | bylopez_lg_guias | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_guia_correcciones` | Logística | 0 | 0 | bylopez_lg_guias | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_guia_estado_proveedor_eventos` | Logística | 8 | 0 | bylopez_lg_guias | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_guia_estados_proveedor` | Logística | 8 | 0 | bylopez_lg_guias | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_guias` | Logística | 42 | 0 | bylopez_lg_disposiciones_cobro, orders, shipments, bylopez_lg_zonas | 6 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_incidencia_stock_lineas` | Logística | 0 | 0 | bylopez_lg_incidencias_stock, order_items | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_incidencias_stock` | Logística | 0 | 0 | orders | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_liquidaciones_transportadora` | Logística | 4 | 0 | bylopez_lg_transportadoras | 2 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_origen_zonas` | Logística | 2 | 2 | inventory_sources, bylopez_lg_zonas | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_lg_preferencias_entrega` | Logística | 19 | 0 | orders, bylopez_lg_transportadoras | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_proveedor_transportadoras` | Logística | 1 | 1 | bylopez_lg_transportadoras | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_lg_recepcion_devolucion_items` | Logística | 3 | 0 | order_items, products, bylopez_lg_recepciones_devolucion, bylopez_lg_retorno_items, shipment_items | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_recepciones_devolucion` | Logística | 2 | 0 | orders, bylopez_lg_retornos, shipments, inventory_sources | 2 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_retorno_items` | Logística | 4 | 0 | order_items, bylopez_lg_retornos, shipment_items | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_retornos` | Logística | 3 | 0 | orders, shipments | 2 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_tarifas` | Logística | 5 | 5 | bylopez_lg_transportadoras, bylopez_lg_zonas | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_lg_transportadoras` | Logística | 10 | 10 | — | 6 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_lg_unidades_defectuosas` | Logística | 0 | 0 | bylopez_lg_recepcion_devolucion_items, orders, products, bylopez_lg_recepciones_devolucion | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_validaciones_entrega` | Logística | 9 | 0 | orders, shipments | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_lg_zona_municipios` | Logística | 2 | 2 | bylopez_lg_zonas | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_lg_zonas` | Logística | 2 | 2 | — | 4 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_va_advisor_assignment_logs` | VentasAsesor | 54 | 0 | bylopez_va_order_attributions | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_ajustes_obligacion` | VentasAsesor | 0 | 0 | customers, orders | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_aplicaciones_pago` | VentasAsesor | 36 | 0 | invoices, orders, bylopez_va_pagos, order_transactions | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_asesores` | VentasAsesor | 2 | 0 | admins | — | VACIAR | DELETE completo | Ficha/acuerdo atados a la cuenta (admin_id NOT NULL, FK RESTRICT); todas las cuentas de asesor se eliminan |
| `bylopez_va_attribution_incidents` | VentasAsesor | 0 | 0 | bylopez_va_order_attributions, orders | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_borrador_grupo_eventos` | VentasAsesor | 1 | 0 | orders | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_borrador_solicitudes` | VentasAsesor | 417 | 0 | bylopez_va_borradores_venta, products | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_borradores_venta` | VentasAsesor | 115 | 0 | cart, orders, bylopez_lg_transportadoras | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_clasificaciones_pedido` | VentasAsesor | 48 | 0 | orders | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_cliente_datos_pendientes` | VentasAsesor | 0 | 0 | customers | — | VACIAR | DELETE completo | Clientes y sus dependientes: se vuelven a cargar con Cargues (decisión ronda 2) |
| `bylopez_va_cliente_direcciones_pendientes` | VentasAsesor | 3 | 0 | customers | — | VACIAR | DELETE completo | Clientes y sus dependientes: se vuelven a cargar con Cargues (decisión ronda 2) |
| `bylopez_va_cliente_envios_historicos` | VentasAsesor | 14 | 0 | customers | — | VACIAR | DELETE completo | Clientes y sus dependientes: se vuelven a cargar con Cargues (decisión ronda 2) |
| `bylopez_va_comision_abonos` | Comisiones | 0 | 0 | bylopez_va_comision_liquidaciones | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_comision_acuerdos` | Comisiones | 1 | 0 | — | 3 tabla(s) | VACIAR | DELETE completo | Ficha/acuerdo atados a la cuenta (admin_id NOT NULL, FK RESTRICT); todas las cuentas de asesor se eliminan |
| `bylopez_va_comision_ajustes` | Comisiones | 0 | 0 | bylopez_va_comision_lineas, bylopez_va_comision_liquidaciones | 2 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_comision_aplicaciones` | Comisiones | 0 | 0 | bylopez_va_comision_abonos, bylopez_va_comision_ajustes, bylopez_va_comision_lineas, bylopez_va_comision_liquidaciones | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_comision_eventos` | Comisiones | 58 | 0 | bylopez_va_comision_lineas | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_comision_incidencias` | Comisiones | 0 | 0 | bylopez_va_comision_ajustes, bylopez_va_comision_lineas, bylopez_va_comision_liquidaciones | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_comision_lineas` | Comisiones | 58 | 0 | bylopez_va_comision_acuerdos, order_items, bylopez_va_comision_liquidaciones, orders | 5 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_comision_liquidaciones` | Comisiones | 0 | 0 | — | 5 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_comision_meses` | Comisiones | 2 | 0 | bylopez_va_comision_acuerdos | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_comision_reevaluaciones` | Comisiones | 33 | 0 | — | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_comision_reglas` | Comisiones | 1 | 1 | — | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_va_comision_soportes` | Comisiones | 0 | 0 | — | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_costo_comisionable_cambios` | VentasAsesor | 144 | 140 | — | — | VACIAR (parcial) | DELETE WHERE product_id IN (productos de prueba) | Productos de prueba confirmados y sus dependencias exclusivas |
| `bylopez_va_costo_estados` | VentasAsesor | 0 | 0 | — | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_va_costos_directos` | VentasAsesor | 0 | 0 | — | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_va_costos_venta` | VentasAsesor | 223 | 0 | orders, order_items | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_cotizaciones` | VentasAsesor | 0 | 0 | — | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_creditos_cliente_movimientos` | VentasAsesor | 0 | 0 | customers, orders, bylopez_va_pagos | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_customer_phones` | VentasAsesor | 42 | 0 | customers | — | VACIAR | DELETE completo | Clientes y sus dependientes: se vuelven a cargar con Cargues (decisión ronda 2) |
| `bylopez_va_estados_envio_clase` | VentasAsesor | 9 | 9 | — | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_va_order_attributions` | VentasAsesor | 54 | 0 | orders | 2 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_pagos` | VentasAsesor | 33 | 0 | customers, bylopez_lg_guias | 3 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_pagos_eventos` | VentasAsesor | 66 | 0 | bylopez_va_pagos | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_pedido_grupo_snapshots` | VentasAsesor | 32 | 0 | orders | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_rentabilidad_evaluacion_lineas` | VentasAsesor | 79 | 0 | bylopez_va_rentabilidad_evaluaciones, order_items, bylopez_va_rentabilidad_politicas | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_rentabilidad_evaluaciones` | VentasAsesor | 20 | 0 | bylopez_va_comision_acuerdos, orders | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `bylopez_va_rentabilidad_politicas` | VentasAsesor | 0 | 0 | — | 1 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `bylopez_va_umbral_movimientos` | VentasAsesor | 60 | 0 | bylopez_va_comision_lineas, orders | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `cart` | Carritos y borradores | 153 | 0 | channels, customers | 5 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `cart_item_inventories` | Carritos y borradores | 0 | 0 | — | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `cart_items` | Carritos y borradores | 487 | 0 | cart, products, tax_categories | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `cart_payment` | Carritos y borradores | 25 | 0 | cart | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `cart_rule_channels` | Reglas de carrito (promociones) | 0 | 0 | cart_rules, channels | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `cart_rule_coupon_usage` | Reglas de carrito (promociones) | 0 | 0 | cart_rule_coupons, customers | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `cart_rule_coupons` | Reglas de carrito (promociones) | 0 | 0 | cart_rules | 1 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `cart_rule_customer_groups` | Reglas de carrito (promociones) | 0 | 0 | cart_rules, customer_groups | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `cart_rule_customers` | Reglas de carrito (promociones) | 0 | 0 | cart_rules, customers | — | VACIAR | DELETE completo | Clientes y sus dependientes: se vuelven a cargar con Cargues (decisión ronda 2) |
| `cart_rule_translations` | Reglas de carrito (promociones) | 0 | 0 | cart_rules | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `cart_rules` | Reglas de carrito (promociones) | 0 | 0 | — | 5 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `cart_shipping_rates` | Carritos y borradores | 83 | 0 | cart | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `catalog_rule_channels` | Reglas de catálogo | 0 | 0 | catalog_rules, channels | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `catalog_rule_customer_groups` | Reglas de catálogo | 0 | 0 | catalog_rules, customer_groups | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `catalog_rule_product_prices` | Reglas de catálogo | 0 | 0 | catalog_rules, channels, customer_groups, products | — | VACIAR (parcial) | DELETE WHERE product_id IN (productos de prueba) | Productos de prueba confirmados y sus dependencias exclusivas |
| `catalog_rule_products` | Reglas de catálogo | 0 | 0 | catalog_rules, channels, customer_groups, products | — | VACIAR (parcial) | DELETE WHERE product_id IN (productos de prueba) | Productos de prueba confirmados y sus dependencias exclusivas |
| `catalog_rules` | Reglas de catálogo | 0 | 0 | — | 4 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `categories` | Categorías | 19 | 19 | — | 4 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `category_filterable_attributes` | Categorías | 47 | 47 | attributes, categories | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `category_translations` | Categorías | 19 | 19 | categories, locales | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `channel_currencies` | Canales | 1 | 1 | channels, currencies | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `channel_inventory_sources` | Canales | 1 | 1 | channels, inventory_sources | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `channel_locales` | Canales | 1 | 1 | channels, locales | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `channel_translations` | Canales | 1 | 1 | channels | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `channels` | Canales | 1 | 1 | currencies, locales, categories | 25 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `cms_page_channels` | Páginas CMS | 10 | 10 | channels, cms_pages | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `cms_page_translations` | Páginas CMS | 10 | 10 | cms_pages | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `cms_pages` | Páginas CMS | 10 | 10 | — | 2 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `compare_items` | Bagisto / sistema | 0 | 0 | customers, products | — | VACIAR | DELETE completo | Clientes y sus dependientes: se vuelven a cargar con Cargues (decisión ronda 2) |
| `core_config` | Configuración del sistema (incluye numeración de pedidos y facturas) | 123 | 123 | — | — | CONSERVAR | UPDATE SET value = '-2026' | Sufijo de facturas -2026; numeración nativa de Bagisto; el resto intacto |
| `countries` | Bagisto / sistema | 1 | 1 | — | 2 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `country_state_translations` | Países y departamentos | 0 | 0 | country_states | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `country_states` | Países y departamentos | 33 | 33 | countries | 2 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `country_translations` | Países y departamentos | 1 | 1 | countries | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `currencies` | Monedas | 1 | 1 | — | 3 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `currency_exchange_rates` | Monedas | 0 | 0 | currencies | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `customer_groups` | Clientes | 7 | 7 | — | 8 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `customer_notes` | Clientes | 0 | 0 | customers | — | VACIAR | DELETE completo | Clientes y sus dependientes: se vuelven a cargar con Cargues (decisión ronda 2) |
| `customer_password_resets` | Clientes | 1 | 0 | — | — | VACIAR | DELETE completo | Clientes y sus dependientes: se vuelven a cargar con Cargues (decisión ronda 2) |
| `customer_social_accounts` | Clientes | 0 | 0 | customers | — | VACIAR | DELETE completo | Clientes y sus dependientes: se vuelven a cargar con Cargues (decisión ronda 2) |
| `customers` | Clientes | 47 | 0 | channels, customer_groups | 22 tabla(s) | VACIAR | DELETE completo | Clientes y sus dependientes: se vuelven a cargar con Cargues (decisión ronda 2) |
| `datagrid_saved_filters` | Bagisto / sistema | 0 | 0 | — | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `downloadable_link_purchased` | Bagisto / sistema | 0 | 0 | customers, orders, order_items | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `eu_withdrawals` | Bagisto / sistema | 0 | 0 | channels, customers, admins, orders | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `failed_jobs` | Bagisto / sistema | 0 | 0 | — | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `gdpr_data_request` | Bagisto / sistema | 0 | 0 | customers | — | VACIAR | DELETE completo | Clientes y sus dependientes: se vuelven a cargar con Cargues (decisión ronda 2) |
| `import_batches` | Bagisto / sistema | 0 | 0 | imports | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `imports` | Bagisto / sistema | 0 | 0 | — | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `inventory_sources` | Bagisto / sistema | 1 | 1 | — | 7 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `invoice_items` | Facturas | 655 | 0 | invoices | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `invoices` | Facturas | 77 | 0 | orders | 2 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `job_batches` | Bagisto / sistema | 0 | 0 | — | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `jobs` | Cola de trabajos pendientes | 1 | 0 | — | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `locales` | Bagisto / sistema | 1 | 1 | — | 3 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `marketing_campaigns` | Marketing | 0 | 0 | channels, customer_groups, marketing_events, marketing_templates | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `marketing_events` | Marketing | 1 | 1 | — | 1 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `marketing_templates` | Marketing | 0 | 0 | — | 1 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `migrations` | Registro de migraciones aplicadas | 295 | 295 | — | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `notifications` | Bagisto / sistema | 78 | 0 | orders | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `order_comments` | Pedidos | 3 | 0 | orders | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `order_items` | Pedidos | 661 | 0 | orders, tax_categories | 12 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `order_payment` | Pedidos | 79 | 0 | orders | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `order_transactions` | Pedidos | 28 | 0 | orders | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `orders` | Pedidos | 79 | 0 | channels, customers | 38 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `password_resets` | Bagisto / sistema | 0 | 0 | — | — | VACIAR | DELETE completo | Sesiones y tokens: se invalidan |
| `personal_access_tokens` | Bagisto / sistema | 0 | 0 | — | — | VACIAR | DELETE completo | Sesiones y tokens: se invalidan |
| `pickup_orders` | Bagisto / sistema | 0 | 0 | customers, inventory_sources, orders, pickup_timeslots | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `pickup_timeslots` | Bagisto / sistema | 40 | 40 | — | 1 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `product_attribute_values` | Catálogo de productos | 11714 | 11290 | attributes, products | — | VACIAR (parcial) | DELETE WHERE product_id IN (productos de prueba) | Productos de prueba confirmados y sus dependencias exclusivas |
| `product_bundle_option_products` | Catálogo de productos | 0 | 0 | product_bundle_options, products | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `product_bundle_option_translations` | Catálogo de productos | 0 | 0 | product_bundle_options | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `product_bundle_options` | Catálogo de productos | 0 | 0 | products | 2 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `product_categories` | Catálogo de productos | 488 | 460 | categories, products | — | VACIAR (parcial) | DELETE WHERE product_id IN (productos de prueba) | Productos de prueba confirmados y sus dependencias exclusivas |
| `product_channels` | Catálogo de productos | 405 | 383 | channels, products | — | VACIAR (parcial) | DELETE WHERE product_id IN (productos de prueba) | Productos de prueba confirmados y sus dependencias exclusivas |
| `product_cross_sells` | Catálogo de productos | 0 | 0 | products | — | VACIAR (parcial) | DELETE WHERE parent_id IN (productos de prueba) OR child_id IN (productos de prueba) | Productos de prueba confirmados y sus dependencias exclusivas |
| `product_customer_group_prices` | Catálogo de productos | 1495 | 1495 | customer_groups, products | — | VACIAR (parcial) | DELETE WHERE product_id IN (productos de prueba) | Productos de prueba confirmados y sus dependencias exclusivas |
| `product_customizable_option_prices` | Catálogo de productos | 0 | 0 | product_customizable_options | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `product_customizable_option_translations` | Catálogo de productos | 0 | 0 | product_customizable_options | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `product_customizable_options` | Catálogo de productos | 0 | 0 | products | 2 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `product_downloadable_link_translations` | Catálogo de productos | 0 | 0 | product_downloadable_links | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `product_downloadable_links` | Catálogo de productos | 0 | 0 | products | 1 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `product_downloadable_sample_translations` | Catálogo de productos | 0 | 0 | product_downloadable_samples | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `product_downloadable_samples` | Catálogo de productos | 0 | 0 | products | 1 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `product_flat` | Catálogo de productos | 405 | 383 | attribute_families, products | — | VACIAR (parcial) | DELETE WHERE product_id IN (productos de prueba) | Productos de prueba confirmados y sus dependencias exclusivas |
| `product_grouped_products` | Catálogo de productos | 0 | 0 | products | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `product_images` | Catálogo de productos | 5 | 5 | products | — | VACIAR (parcial) | DELETE WHERE product_id IN (productos de prueba) | Productos de prueba confirmados y sus dependencias exclusivas |
| `product_inventories` | Existencias físicas por producto y bodega | 311 | 304 | inventory_sources, products | — | VACIAR (parcial) | DELETE WHERE product_id IN (productos de prueba) + UPDATE SET qty = 0 | Inventario inicial en cero; filas producto × bodega conservadas (salvo productos de prueba) |
| `product_inventory_indices` | Índice de stock vendible (derivado) | 404 | 382 | channels, products | — | VACIAR (parcial) | DELETE WHERE product_id IN (productos de prueba) + indexer:index --type=inventory --mode=full | Derivado de existencias y reservas; se reconstruye |
| `product_ordered_inventories` | Reservas de pedidos (derivado) | 168 | 0 | channels, products | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `product_price_indices` | Catálogo de productos | 2828 | 2674 | channels, customer_groups, products | — | VACIAR (parcial) | DELETE WHERE product_id IN (productos de prueba) | Productos de prueba confirmados y sus dependencias exclusivas |
| `product_relations` | Catálogo de productos | 368 | 368 | products | — | VACIAR (parcial) | DELETE WHERE parent_id IN (productos de prueba) OR child_id IN (productos de prueba) | Productos de prueba confirmados y sus dependencias exclusivas |
| `product_review_attachments` | Catálogo de productos | 0 | 0 | product_reviews | — | VACIAR | DELETE completo | Clientes y sus dependientes: se vuelven a cargar con Cargues (decisión ronda 2) |
| `product_reviews` | Catálogo de productos | 0 | 0 | products | 1 tabla(s) | VACIAR | DELETE completo | Clientes y sus dependientes: se vuelven a cargar con Cargues (decisión ronda 2) |
| `product_super_attributes` | Catálogo de productos | 170 | 156 | attributes, products | — | VACIAR (parcial) | DELETE WHERE product_id IN (productos de prueba) | Productos de prueba confirmados y sus dependencias exclusivas |
| `product_up_sells` | Catálogo de productos | 0 | 0 | products | — | VACIAR (parcial) | DELETE WHERE parent_id IN (productos de prueba) OR child_id IN (productos de prueba) | Productos de prueba confirmados y sus dependencias exclusivas |
| `product_videos` | Catálogo de productos | 0 | 0 | products | — | VACIAR (parcial) | DELETE WHERE product_id IN (productos de prueba) | Productos de prueba confirmados y sus dependencias exclusivas |
| `products` | Bagisto / sistema | 408 | 383 | attribute_families | 36 tabla(s) | VACIAR (parcial) | DELETE WHERE id IN (productos de prueba) | Productos de prueba confirmados y sus dependencias exclusivas |
| `refund_items` | Reembolsos | 0 | 0 | order_items, refunds | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `refunds` | Reembolsos | 0 | 0 | orders | 1 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `rma` | Devoluciones (RMA de Bagisto) | 0 | 0 | orders, rma_statuses | 4 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `rma_additional_fields` | Devoluciones (RMA de Bagisto) | 0 | 0 | rma_custom_fields, rma | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `rma_custom_field_options` | Devoluciones (RMA de Bagisto) | 0 | 0 | rma_custom_fields | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `rma_custom_fields` | Devoluciones (RMA de Bagisto) | 0 | 0 | — | 2 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `rma_images` | Devoluciones (RMA de Bagisto) | 0 | 0 | rma | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `rma_items` | Devoluciones (RMA de Bagisto) | 0 | 0 | order_items, rma, rma_reasons, products | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `rma_messages` | Devoluciones (RMA de Bagisto) | 0 | 0 | rma | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `rma_reason_resolutions` | Devoluciones (RMA de Bagisto) | 0 | 0 | rma_reasons | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `rma_reasons` | Devoluciones (RMA de Bagisto) | 0 | 0 | — | 2 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `rma_rules` | Devoluciones (RMA de Bagisto) | 0 | 0 | — | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `rma_statuses` | Devoluciones (RMA de Bagisto) | 0 | 0 | — | 1 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `roles` | Roles y permisos | 12 | 8 | — | — | VACIAR (parcial) | DELETE WHERE id IN (12059,12060,12061,12065) | Roles QA Fase 1 sin uso; los operativos se conservan |
| `search_synonyms` | Bagisto / sistema | 0 | 0 | — | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `search_terms` | Bagisto / sistema | 6 | 6 | channels | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `sessions` | Bagisto / sistema | 0 | 0 | — | — | VACIAR | DELETE completo | Sesiones y tokens: se invalidan |
| `shipment_items` | Envíos | 389 | 0 | shipments | 3 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `shipments` | Envíos | 78 | 0 | inventory_sources, orders | 7 tabla(s) | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `sitemap_channels` | Sitemap | 0 | 0 | channels, sitemaps | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `sitemaps` | Sitemap | 0 | 0 | — | 1 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `subscribers_list` | Bagisto / sistema | 5 | 0 | channels, customers | — | VACIAR | DELETE completo | Clientes y sus dependientes: se vuelven a cargar con Cargues (decisión ronda 2) |
| `tax_categories` | Impuestos | 0 | 0 | — | 3 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `tax_categories_tax_rates` | Impuestos | 0 | 0 | tax_categories, tax_rates | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `tax_rates` | Impuestos | 0 | 0 | — | 1 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `theme_customization_translations` | Tema de la tienda | 12 | 12 | theme_customizations | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `theme_customizations` | Tema de la tienda | 12 | 12 | channels | 1 tabla(s) | CONSERVAR | — | Maestro, catálogo o configuración |
| `url_rewrites` | Bagisto / sistema | 357 | 357 | — | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `users` | Bagisto / sistema | 0 | 0 | — | — | CONSERVAR | — | Maestro, catálogo o configuración |
| `visits` | Registro de visitas de la tienda | 906 | 0 | channels | — | VACIAR | DELETE completo | Operación anterior o dato derivado de operaciones |
| `wishlist` | Listas de deseos | 0 | 0 | channels, customers, products | — | VACIAR | DELETE completo | Clientes y sus dependientes: se vuelven a cargar con Cargues (decisión ronda 2) |
| `wishlist_items` | Listas de deseos | 0 | 0 | channels, customers, products | — | VACIAR | DELETE completo | Clientes y sus dependientes: se vuelven a cargar con Cargues (decisión ronda 2) |
