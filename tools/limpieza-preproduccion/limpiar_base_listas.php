<?php

// Clasificación de tablas de la limpieza preproducción (ver docs/limpieza-preproduccion/01_MATRIZ.md).
// La usan limpiar_base.php, verificar_copia.php y generar_matriz.php: una tabla nueva sin clasificar detiene la limpieza.
//
// Cada tabla está en UNA de tres listas: TABLAS_VACIAR (DELETE completo), TABLAS_PARCIALES (DELETE de las filas del
// filtro) o TABLAS_CONSERVAR. ANULACIONES y ACTUALIZACIONES son ajustes sobre filas que se conservan.
//
// Ronda 2 (decisiones de Camilo del 2026-11-15): sin clientes ni históricos, sin productos de prueba confirmados,
// solo la cuenta administrativa real, sin roles QA sin uso, sufijo de facturas -2026.

// Única cuenta administrativa que se conserva: la que registra la configuración (tipos de cargue, tarifas, acuerdos).
const ADMIN_CONSERVADO_ID = 1;

// Productos de prueba con procedencia confirmada (ver 00_ENTREGA.md §Productos):
//  2445–2447: Product::factory() de las pruebas Pest (SKU UUID v3 de Faker, solo atributo cost, mismo segundo).
//  6146: PRUEBA-CARGUES-99ENV. 6147–6167: PRUEBA-CARGUE-CAM-* con sus variantes (pruebas del doc. 17 de Cargues).
const IDS_PRODUCTOS_PRUEBA = '2445,2446,2447,6146,6147,6148,6149,6150,6151,6152,6153,6154,6155,6156,6157,6158,6159,'
    .'6160,6161,6162,6163,6164,6165,6166,6167';

// Roles creados por las pruebas QA Fase 1 (nombre QA, sin usuarios).
const IDS_ROLES_QA = '12059,12060,12061,12065';

const SUFIJO_FACTURA = ['code' => 'sales.invoice_settings.invoice_number.invoice_number_suffix', 'valor' => '-2026'];

// Numeración nativa de Bagisto (decisión de Camilo, 2026-11-16): sin clase generadora propia. El verificador exige
// que estos dos valores sigan vacíos, como en la base original.
const GENERADORES_NATIVOS = [
    'sales.order_settings.order_number.order_number_generator',
    'sales.invoice_settings.invoice_number.invoice_number_generator_class',
];

const TABLAS_VACIAR = [
    // Bagisto: ventas, carritos, colas y temporales
    'orders', 'order_items', 'order_payment', 'order_transactions', 'order_comments', 'invoices', 'invoice_items',
    'shipments', 'shipment_items', 'refunds', 'refund_items', 'notifications', 'cart', 'cart_items',
    'cart_item_inventories', 'cart_payment', 'cart_shipping_rates', 'product_ordered_inventories',
    'downloadable_link_purchased', 'bookings', 'pickup_orders', 'rma', 'rma_items', 'rma_images', 'rma_messages',
    'rma_additional_fields', 'eu_withdrawals', 'jobs', 'failed_jobs', 'job_batches', 'sessions',
    'customer_password_resets', 'admin_password_resets', 'password_resets', 'visits', 'cart_rule_coupon_usage',
    'imports', 'import_batches', 'personal_access_tokens',
    // Clientes y todo lo que depende de ellos (se vuelven a cargar con Cargues)
    'customers', 'addresses', 'customer_notes', 'customer_social_accounts', 'gdpr_data_request', 'subscribers_list',
    'wishlist', 'wishlist_items', 'compare_items', 'cart_rule_customers', 'product_reviews', 'product_review_attachments',
    'bylopez_va_customer_phones', 'bylopez_va_cliente_envios_historicos', 'bylopez_va_cliente_direcciones_pendientes',
    'bylopez_va_cliente_datos_pendientes',
    // VentasAsesor: borradores, cotizaciones, pagos, créditos, comisiones generadas y derivados por pedido
    'bylopez_va_advisor_assignment_logs', 'bylopez_va_ajustes_obligacion', 'bylopez_va_aplicaciones_pago',
    'bylopez_va_attribution_incidents', 'bylopez_va_borradores_venta', 'bylopez_va_borrador_grupo_eventos',
    'bylopez_va_borrador_solicitudes', 'bylopez_va_clasificaciones_pedido', 'bylopez_va_comision_abonos',
    'bylopez_va_comision_ajustes', 'bylopez_va_comision_aplicaciones', 'bylopez_va_comision_eventos',
    'bylopez_va_comision_incidencias', 'bylopez_va_comision_lineas', 'bylopez_va_comision_liquidaciones',
    'bylopez_va_comision_meses', 'bylopez_va_comision_reevaluaciones', 'bylopez_va_comision_soportes',
    'bylopez_va_costos_venta', 'bylopez_va_cotizaciones', 'bylopez_va_creditos_cliente_movimientos',
    'bylopez_va_order_attributions', 'bylopez_va_pagos', 'bylopez_va_pagos_eventos',
    'bylopez_va_pedido_grupo_snapshots', 'bylopez_va_rentabilidad_evaluaciones',
    'bylopez_va_rentabilidad_evaluacion_lineas', 'bylopez_va_umbral_movimientos',
    // VentasAsesor: fichas de asesor y su acuerdo. admin_id es NOT NULL con FK RESTRICT: la ficha no puede existir
    // sin su cuenta, y todas las cuentas de asesor se eliminan.
    'bylopez_va_asesores', 'bylopez_va_comision_acuerdos',
    // Logística: guías, entregas, devoluciones, cuentas de transportadoras
    'bylopez_lg_cancelaciones', 'bylopez_lg_cancelacion_lineas', 'bylopez_lg_conciliaciones_movimiento',
    'bylopez_lg_cuenta_movimientos', 'bylopez_lg_disposiciones_cobro', 'bylopez_lg_entregas',
    'bylopez_lg_entrega_items', 'bylopez_lg_guias', 'bylopez_lg_guia_archivos', 'bylopez_lg_guia_correcciones',
    'bylopez_lg_guia_estados_proveedor', 'bylopez_lg_guia_estado_proveedor_eventos', 'bylopez_lg_incidencias_stock',
    'bylopez_lg_incidencia_stock_lineas', 'bylopez_lg_liquidaciones_transportadora', 'bylopez_lg_preferencias_entrega',
    'bylopez_lg_recepciones_devolucion', 'bylopez_lg_recepcion_devolucion_items', 'bylopez_lg_retornos',
    'bylopez_lg_retorno_items', 'bylopez_lg_unidades_defectuosas', 'bylopez_lg_validaciones_entrega',
    // Corte y Confección: compras, rollos, órdenes, recepciones, cartera de prestadores, prioridades
    'bylopez_cc_cartera_aplicaciones', 'bylopez_cc_cartera_liquidaciones', 'bylopez_cc_cartera_movimientos',
    'bylopez_cc_cartera_operaciones', 'bylopez_cc_cartera_soportes', 'bylopez_cc_compras_tela',
    'bylopez_cc_cuenta_eventos', 'bylopez_cc_inventario_movimientos', 'bylopez_cc_notificaciones',
    'bylopez_cc_ordenes_confeccion', 'bylopez_cc_ordenes_confeccion_detalles', 'bylopez_cc_ordenes_confeccion_retornos',
    'bylopez_cc_ordenes_confeccion_retorno_detalles', 'bylopez_cc_ordenes_corte', 'bylopez_cc_ordenes_corte_detalles',
    'bylopez_cc_orden_corte_cantidad_audits', 'bylopez_cc_orden_corte_rollos', 'bylopez_cc_orden_tarifas',
    'bylopez_cc_prioridad_cambios', 'bylopez_cc_prioridad_operaciones', 'bylopez_cc_reasignaciones',
    'bylopez_cc_reasignacion_lineas', 'bylopez_cc_recepciones_corte', 'bylopez_cc_recepciones_corte_detalles',
    'bylopez_cc_rollos',
    // Cargues: todas las ejecuciones y su staging (las definiciones, versiones y procedimientos se conservan)
    'bylopez_cg_cargas', 'bylopez_cg_carga_datos', 'bylopez_cg_carga_incidencias',
    'bylopez_cgi_clientes_historicos', 'bylopez_cgi_clientes_historicos__a1', 'bylopez_cgi_clientes_historicos__a2',
    'bylopez_cgi_envios_completos', 'bylopez_cgi_envios_completos__a1', 'bylopez_cgi_inventario_conteo',
    'bylopez_cgi_productos_camisetas', 'bylopez_cgi_productos_camisetas__a1', 'bylopez_cgi_prueba_e1',
];

// Filas que se borran en tablas que se conservan en parte. Solo listas fijas de ids o columnas propias (sin
// subconsultas), para que el filtro dé el mismo resultado en cualquier momento de la transacción.
const TABLAS_PARCIALES = [
    'admins' => 'id <> '.ADMIN_CONSERVADO_ID,
    'roles' => 'id IN ('.IDS_ROLES_QA.')',
    'products' => 'id IN ('.IDS_PRODUCTOS_PRUEBA.')',
    'product_attribute_values' => 'product_id IN ('.IDS_PRODUCTOS_PRUEBA.')',
    'product_categories' => 'product_id IN ('.IDS_PRODUCTOS_PRUEBA.')',
    'product_channels' => 'product_id IN ('.IDS_PRODUCTOS_PRUEBA.')',
    'product_flat' => 'product_id IN ('.IDS_PRODUCTOS_PRUEBA.')',
    'product_inventories' => 'product_id IN ('.IDS_PRODUCTOS_PRUEBA.')',
    'product_inventory_indices' => 'product_id IN ('.IDS_PRODUCTOS_PRUEBA.')',
    'product_price_indices' => 'product_id IN ('.IDS_PRODUCTOS_PRUEBA.')',
    'product_super_attributes' => 'product_id IN ('.IDS_PRODUCTOS_PRUEBA.')',
    'product_customer_group_prices' => 'product_id IN ('.IDS_PRODUCTOS_PRUEBA.')',
    'product_images' => 'product_id IN ('.IDS_PRODUCTOS_PRUEBA.')',
    'product_videos' => 'product_id IN ('.IDS_PRODUCTOS_PRUEBA.')',
    'product_relations' => 'parent_id IN ('.IDS_PRODUCTOS_PRUEBA.') OR child_id IN ('.IDS_PRODUCTOS_PRUEBA.')',
    'product_cross_sells' => 'parent_id IN ('.IDS_PRODUCTOS_PRUEBA.') OR child_id IN ('.IDS_PRODUCTOS_PRUEBA.')',
    'product_up_sells' => 'parent_id IN ('.IDS_PRODUCTOS_PRUEBA.') OR child_id IN ('.IDS_PRODUCTOS_PRUEBA.')',
    'catalog_rule_products' => 'product_id IN ('.IDS_PRODUCTOS_PRUEBA.')',
    'catalog_rule_product_prices' => 'product_id IN ('.IDS_PRODUCTOS_PRUEBA.')',
    'bylopez_cc_stock_minimos' => 'product_id IN ('.IDS_PRODUCTOS_PRUEBA.')',
    'bylopez_va_costo_comisionable_cambios' => 'product_id IN ('.IDS_PRODUCTOS_PRUEBA.')',
];

// Vínculos de fichas maestras con cuentas que se eliminan: se anulan antes de borrar (columnas nulables).
const ANULACIONES = [
    ['tabla' => 'bylopez_cc_cortadores', 'columna' => 'usuario_id', 'donde' => 'usuario_id <> '.ADMIN_CONSERVADO_ID],
    ['tabla' => 'bylopez_cc_talleres', 'columna' => 'usuario_id', 'donde' => 'usuario_id <> '.ADMIN_CONSERVADO_ID],
];

// Cambios de valor en filas conservadas. 'pendientes' = filas que aún no tienen el valor final (idempotente);
// 'alcance' = filas que pueden cambiar (sobre columnas que NO cambian) y 'columnas' = columnas que cambian.
const ACTUALIZACIONES = [
    // Inventario inicial en cero: las filas producto × bodega se conservan.
    ['tabla' => 'product_inventories', 'set' => 'qty = 0', 'pendientes' => 'qty <> 0', 'alcance' => '1 = 1', 'columnas' => ['qty']],
    // Sufijo de las nuevas facturas (Configuración › Ventas › Configuración de facturas › Número de factura › Sufijo).
    ['tabla' => 'core_config', 'set' => "value = '".SUFIJO_FACTURA['valor']."'",
        'pendientes' => "code = '".SUFIJO_FACTURA['code']."' AND value <> '".SUFIJO_FACTURA['valor']."'",
        'alcance' => "code = '".SUFIJO_FACTURA['code']."'", 'columnas' => ['value']],
];

// Se reconstruye después con `php artisan indexer:index --type=inventory --mode=full`.
const TABLAS_RECONSTRUIR = ['product_inventory_indices'];

const TABLAS_CONSERVAR = [
    'agent_conversations', 'agent_conversation_messages', 'attributes', 'attribute_families',
    'attribute_groups', 'attribute_group_mappings', 'attribute_options', 'attribute_option_translations',
    'attribute_translations', 'booking_products', 'booking_product_appointment_slots', 'booking_product_default_slots',
    'booking_product_event_tickets', 'booking_product_event_ticket_translations', 'booking_product_rental_slots',
    'booking_product_table_slots', 'bylopez_cc_cartera_config', 'bylopez_cc_cortadores', 'bylopez_cc_proveedores_tela',
    'bylopez_cc_proveedor_tela_referencias', 'bylopez_cc_proveedor_tela_tipos',
    'bylopez_cc_stock_minimo_cambios', 'bylopez_cc_talleres', 'bylopez_cc_tarifas_prestador', 'bylopez_cg_comparaciones',
    'bylopez_cg_estructuras', 'bylopez_cg_tipos', 'bylopez_cg_tipo_versiones', 'bylopez_geo_departamentos',
    'bylopez_geo_municipios', 'bylopez_lg_catalogo_defectos', 'bylopez_lg_consecutivos', 'bylopez_lg_origen_zonas',
    'bylopez_lg_proveedor_transportadoras', 'bylopez_lg_tarifas', 'bylopez_lg_transportadoras', 'bylopez_lg_zonas',
    'bylopez_lg_zona_municipios', 'bylopez_va_comision_reglas', 'bylopez_va_costos_directos',
    'bylopez_va_costo_estados', 'bylopez_va_estados_envio_clase',
    'bylopez_va_rentabilidad_politicas', 'cart_rules', 'cart_rule_channels', 'cart_rule_coupons',
    'cart_rule_customer_groups', 'cart_rule_translations', 'catalog_rules', 'catalog_rule_channels',
    'catalog_rule_customer_groups', 'categories',
    'category_filterable_attributes', 'category_translations', 'channels', 'channel_currencies',
    'channel_inventory_sources', 'channel_locales', 'channel_translations', 'cms_pages', 'cms_page_channels',
    'cms_page_translations', 'core_config', 'countries', 'country_states', 'country_state_translations',
    'country_translations', 'currencies', 'currency_exchange_rates', 'customer_groups',
    'datagrid_saved_filters', 'inventory_sources', 'locales',
    'marketing_campaigns', 'marketing_events', 'marketing_templates', 'migrations',
    'pickup_timeslots', 'product_bundle_options',
    'product_bundle_option_products', 'product_bundle_option_translations', 'product_customizable_options',
    'product_customizable_option_prices', 'product_customizable_option_translations', 'product_downloadable_links',
    'product_downloadable_link_translations', 'product_downloadable_samples', 'product_downloadable_sample_translations',
    'product_grouped_products', 'rma_custom_fields', 'rma_custom_field_options', 'rma_reasons', 'rma_reason_resolutions',
    'rma_rules', 'rma_statuses', 'search_synonyms', 'search_terms', 'sitemaps', 'sitemap_channels', 'tax_categories',
    'tax_categories_tax_rates', 'tax_rates', 'theme_customizations', 'theme_customization_translations', 'url_rewrites',
    'users',
];

// Migraciones aplicadas a la copia después del respaldo; el resto de `migrations` debe ser idéntico. Ninguna: la única
// que hubo (contador de numeración visible) se retiró junto con su tabla.
const MIGRACIONES_NUEVAS = [];

// Referencias lógicas (sin FK declarada) desde filas conservadas; el verificador comprueba que no queden huérfanas.
const REFERENCIAS_LOGICAS = [
    ['bylopez_va_costo_comisionable_cambios', 'product_id', 'products', 'id'],
    ['bylopez_va_costo_comisionable_cambios', 'actor_admin_id', 'admins', 'id'],
    ['bylopez_va_costo_estados', 'familia_id', 'attribute_families', 'id'],
    ['bylopez_va_costo_estados', 'creado_por_admin_id', 'admins', 'id'],
    ['bylopez_va_comision_reglas', 'creado_por_admin_id', 'admins', 'id'],
    ['bylopez_va_costos_directos', 'creado_por_admin_id', 'admins', 'id'],
    ['bylopez_va_rentabilidad_politicas', 'creado_por_admin_id', 'admins', 'id'],
    ['bylopez_cc_tarifas_prestador', 'creado_por_admin_id', 'admins', 'id'],
    ['bylopez_cc_tarifas_prestador', 'anulada_por_admin_id', 'admins', 'id'],
    ['bylopez_lg_tarifas', 'creado_por_admin_id', 'admins', 'id'],
    ['bylopez_cg_tipos', 'creado_por_admin_id', 'admins', 'id'],
    ['bylopez_cg_tipo_versiones', 'actor_admin_id', 'admins', 'id'],
    ['bylopez_cg_tipo_versiones', 'revision_admin_id', 'admins', 'id'],
    ['bylopez_cg_comparaciones', 'creada_por_admin_id', 'admins', 'id'],
    ['admins', 'role_id', 'roles', 'id'],
    ['product_flat', 'parent_id', 'products', 'id'],
];
