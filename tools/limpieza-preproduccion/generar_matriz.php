<?php

/*
| Genera la matriz de conservación y limpieza (Markdown) a partir de la MISMA clasificación que usa el limpiador.
| Solo lectura. Uso: php generar_matriz.php --cnf=<.cnf con lectura> --antes=<restauración del respaldo> --despues=<copia limpia> > matriz.md
*/

require __DIR__.'/limpiar_base_listas.php';

$o = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(cnf|antes|despues)=(.+)$/', $a, $m)) {
        $o[$m[1]] = $m[2];
    }
}
$c = parse_ini_file($o['cnf'], true, INI_SCANNER_RAW)['client'];
$pdo = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['user'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// '—' cuando la tabla no existía en esa base (migración posterior al respaldo).
$contar = function (string $base, string $t) use ($pdo): string {
    try {
        return (string) $pdo->query("SELECT COUNT(*) FROM `$base`.`$t`")->fetchColumn();
    } catch (PDOException) {
        return '—';
    }
};

$fks = $pdo->query('SELECT table_name, referenced_table_name FROM information_schema.key_column_usage
    WHERE table_schema = '.$pdo->quote($o['despues']).' AND referenced_table_name IS NOT NULL')->fetchAll(PDO::FETCH_NUM);
$padres = $hijas = [];
foreach ($fks as [$t, $p]) {
    if ($t !== $p) {
        $padres[$t][$p] = true;
        $hijas[$p][$t] = true;
    }
}

// Función por prefijo/tabla (descripción corta) y motivo por clasificación.
function funcion(string $t): string
{
    $especiales = [
        'addresses' => 'Direcciones: de cliente (se conservan) y copias en carritos/pedidos (se borran)',
        'bylopez_cg_cargas' => 'Ejecuciones de cargues',
        'bylopez_cg_carga_datos' => 'Filas importadas de cada cargue',
        'bylopez_cg_carga_incidencias' => 'Rechazos e incidencias de cada cargue',
        'product_inventories' => 'Existencias físicas por producto y bodega',
        'product_inventory_indices' => 'Índice de stock vendible (derivado)',
        'product_ordered_inventories' => 'Reservas de pedidos (derivado)',
        'bylopez_lg_consecutivos' => 'Consecutivos de guías internas (GI)',
        'migrations' => 'Registro de migraciones aplicadas',
        'jobs' => 'Cola de trabajos pendientes',
        'visits' => 'Registro de visitas de la tienda',
        'core_config' => 'Configuración del sistema (incluye numeración de pedidos y facturas)',
    ];
    if (isset($especiales[$t])) {
        return $especiales[$t];
    }
    $prefijos = [
        'bylopez_cgi_clientes_historicos' => 'Staging de «Clientes históricos — 99 Envíos»',
        'bylopez_cgi_' => 'Staging de cargues',
        'bylopez_cg_' => 'Cargues: definición (tipos, versiones, estructuras, comparaciones)',
        'bylopez_va_comision' => 'Comisiones',
        'bylopez_va_' => 'VentasAsesor',
        'bylopez_lg_' => 'Logística',
        'bylopez_cc_' => 'Corte y Confección',
        'bylopez_geo_' => 'Geografía (DANE)',
        'product_' => 'Catálogo de productos',
        'attribute' => 'Atributos y opciones',
        'categor' => 'Categorías',
        'cart_rule' => 'Reglas de carrito (promociones)',
        'catalog_rule' => 'Reglas de catálogo',
        'cart' => 'Carritos y borradores',
        'order' => 'Pedidos',
        'invoice' => 'Facturas',
        'shipment' => 'Envíos',
        'refund' => 'Reembolsos',
        'rma' => 'Devoluciones (RMA de Bagisto)',
        'booking' => 'Reservas de producto (Bagisto)',
        'channel' => 'Canales',
        'customer' => 'Clientes',
        'country' => 'Países y departamentos',
        'currenc' => 'Monedas',
        'tax' => 'Impuestos',
        'cms' => 'Páginas CMS',
        'theme' => 'Tema de la tienda',
        'marketing' => 'Marketing',
        'sitemap' => 'Sitemap',
        'wishlist' => 'Listas de deseos',
        'admin' => 'Usuarios del panel',
        'role' => 'Roles y permisos',
    ];
    foreach ($prefijos as $p => $f) {
        if (str_starts_with($t, $p)) {
            return $f;
        }
    }

    return 'Bagisto / sistema';
}

$filas = [];
$clientes = ['customers', 'addresses', 'customer_notes', 'customer_social_accounts', 'gdpr_data_request', 'subscribers_list',
    'wishlist', 'wishlist_items', 'compare_items', 'cart_rule_customers', 'product_reviews', 'product_review_attachments',
    'bylopez_va_customer_phones', 'bylopez_va_cliente_envios_historicos', 'bylopez_va_cliente_direcciones_pendientes',
    'bylopez_va_cliente_datos_pendientes', 'customer_password_resets'];
foreach (TABLAS_VACIAR as $t) {
    $motivo = match (true) {
        in_array($t, $clientes, true) => 'Clientes y sus dependientes: se vuelven a cargar con Cargues (decisión ronda 2)',
        in_array($t, ['bylopez_va_asesores', 'bylopez_va_comision_acuerdos'], true) => 'Ficha/acuerdo atados a la cuenta (admin_id NOT NULL, FK RESTRICT); todas las cuentas de asesor se eliminan',
        str_starts_with($t, 'bylopez_cg') => 'Ejecuciones y staging de Cargues (las definiciones y procedimientos se conservan)',
        in_array($t, ['personal_access_tokens', 'sessions', 'admin_password_resets', 'password_resets'], true) => 'Sesiones y tokens: se invalidan',
        default => 'Operación anterior o dato derivado de operaciones',
    };
    $filas[$t] = ['VACIAR', 'DELETE completo', $motivo];
}
foreach (TABLAS_PARCIALES as $t => $f) {
    $motivo = match (true) {
        $t === 'admins' => 'Solo se conserva la cuenta administrativa real (id '.ADMIN_CONSERVADO_ID.')',
        $t === 'roles' => 'Roles QA Fase 1 sin uso; los operativos se conservan',
        default => 'Productos de prueba confirmados y sus dependencias exclusivas',
    };
    $f = preg_replace('/\(('.preg_quote(IDS_PRODUCTOS_PRUEBA, '/').')\)/', '(productos de prueba)', $f);
    $filas[$t] = ['VACIAR (parcial)', "DELETE WHERE $f", $motivo];
}
foreach (TABLAS_CONSERVAR as $t) {
    $filas[$t] = ['CONSERVAR', '—', 'Maestro, catálogo o configuración'];
}
foreach (ANULACIONES as $a) {
    $filas[$a['tabla']][1] = "UPDATE SET {$a['columna']} = NULL WHERE {$a['donde']}";
    $filas[$a['tabla']][2] = 'Ficha maestra conservada, desvinculada de la cuenta eliminada';
}
foreach (ACTUALIZACIONES as $a) {
    $filas[$a['tabla']][1] = ($filas[$a['tabla']][1] === '—' ? '' : $filas[$a['tabla']][1].' + ')."UPDATE SET {$a['set']}";
    $filas[$a['tabla']][2] = $a['tabla'] === 'core_config' ? 'Sufijo de facturas -2026; numeración nativa de Bagisto; el resto intacto'
        : 'Inventario inicial en cero; filas producto × bodega conservadas (salvo productos de prueba)';
}
foreach (TABLAS_RECONSTRUIR as $t) {
    $filas[$t][1] .= ' + indexer:index --type=inventory --mode=full';
    $filas[$t][2] = 'Derivado de existencias y reservas; se reconstruye';
}
ksort($filas);

echo "| Tabla | Función | Antes | Después | Depende de | La usan | Clasificación | Acción | Motivo |\n|---|---|---:|---:|---|---|---|---|---|\n";
foreach ($filas as $t => [$clase, $accion, $motivo]) {
    $dep = implode(', ', array_keys($padres[$t] ?? []));
    $usan = count($hijas[$t] ?? []);
    printf("| `%s` | %s | %s | %s | %s | %s | %s | %s | %s |\n", $t, funcion($t), $contar($o['antes'], $t), $contar($o['despues'], $t),
        $dep ?: '—', $usan ? "$usan tabla(s)" : '—', $clase, str_replace('|', '\|', $accion), $motivo);
}
