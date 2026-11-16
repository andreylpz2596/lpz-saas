<?php

/*
| Verificación de la copia limpia (solo lectura).
|
| Uso: php verificar_copia.php --cnf=<.cnf con lectura en ambas bases> --antes=<base restaurada del respaldo> --despues=<copia limpia>
|
| Comprueba:
|  1. Tablas que se conservan: contenido idéntico fila a fila (hash de todas las columnas, ordenado por clave primaria).
|  2. Tablas parciales o ajustadas: las filas conservadas son idénticas salvo las columnas ajustadas (qty, sufijo,
|     vínculos anulados); las del alcance de borrado ya no existen.
|  3. Existencias: qty = 0; índice de inventario en 0; sin reservas ni rollos.
|  4. Tablas vaciadas: 0 filas.
|  5. Huérfanos: ninguna clave foránea declarada apunta a una fila inexistente, ni las referencias lógicas sin FK.
|  6. Estado final: solo la cuenta administrativa conservada, sufijo -2026, sin productos de prueba ni roles QA.
*/

require __DIR__.'/limpiar_base_listas.php';

function opcionesVerificar(array $argv): array
{
    $o = [];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--(cnf|antes|despues)=(.+)$/', $a, $m)) {
            $o[$m[1]] = $m[2];
        }
    }
    foreach (['cnf', 'antes', 'despues'] as $k) {
        if (empty($o[$k])) {
            fwrite(STDERR, "falta --$k\n");
            exit(2);
        }
    }

    return $o;
}

$o = opcionesVerificar($argv);
$c = parse_ini_file($o['cnf'], true, INI_SCANNER_RAW)['client'];
$pdo = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['user'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
[$A, $D] = [$o['antes'], $o['despues']];
if ($D === 'lpz_saas' || $A === 'lpz_saas') {
    fwrite(STDERR, "no se verifica contra la base fuente; use la restauración del respaldo como 'antes'\n");
    exit(2);
}

$fallos = [];
$ok = fn (string $m) => print "  OK  $m\n";
$mal = function (string $m) use (&$fallos) {
    $fallos[] = $m;
    echo "  FALLA $m\n";
};

function columnas(PDO $pdo, string $base, string $tabla, array $excluir = []): array
{
    $cols = $pdo->query('SELECT column_name FROM information_schema.columns WHERE table_schema = '.$pdo->quote($base)
        .' AND table_name = '.$pdo->quote($tabla).' ORDER BY ordinal_position')->fetchAll(PDO::FETCH_COLUMN);

    return array_values(array_diff($cols, $excluir));
}

function clavePrimaria(PDO $pdo, string $base, string $tabla): array
{
    $pk = $pdo->query('SELECT column_name FROM information_schema.key_column_usage WHERE table_schema = '.$pdo->quote($base)
        .' AND table_name = '.$pdo->quote($tabla)." AND constraint_name = 'PRIMARY' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);

    return $pk ?: columnas($pdo, $base, $tabla);
}

/** Hash del contenido completo (o de un subconjunto) de una tabla. */
function huella(PDO $pdo, string $base, string $tabla, ?string $donde = null, array $excluir = []): array
{
    $cols = columnas($pdo, $base, $tabla, $excluir);
    $orden = implode(', ', array_map(fn ($c) => "`$c`", clavePrimaria($pdo, $base, $tabla)));
    $lista = implode(', ', array_map(fn ($c) => "`$c`", $cols));
    $ctx = hash_init('sha256');
    $n = 0;
    $st = $pdo->query("SELECT $lista FROM `$base`.`$tabla`".($donde ? " WHERE $donde" : '')." ORDER BY $orden", PDO::FETCH_NUM);
    foreach ($st as $fila) {
        hash_update($ctx, json_encode($fila)."\n");
        $n++;
    }

    return [$n, hash_final($ctx)];
}

echo "== Verificación: antes=$A · después=$D\n";

// Ajustes por tabla: [alcance, columnas que cambian] (ANULACIONES y ACTUALIZACIONES).
$ajustes = [];
foreach (ANULACIONES as $a) {
    $ajustes[$a['tabla']][] = ['1 = 1', [$a['columna']]];
}
foreach (ACTUALIZACIONES as $a) {
    $ajustes[$a['tabla']][] = [$a['alcance'], $a['columnas']];
}
foreach (TABLAS_RECONSTRUIR as $t) {
    $ajustes[$t][] = ['1 = 1', ['qty', 'updated_at']]; // índice derivado: lo reescribe el indexador de Bagisto
}

echo "1. Tablas conservadas completas (contenido idéntico)\n";
$iguales = 0;
$enteras = array_diff(TABLAS_CONSERVAR, array_keys($ajustes));
$nuevas = implode(',', array_map(fn ($m) => $pdo->quote($m), MIGRACIONES_NUEVAS));
foreach ($enteras as $t) {
    $filtro = $t === 'migrations' ? "migration NOT IN ($nuevas)" : null;
    [$na, $ha] = huella($pdo, $A, $t, $filtro);
    [$nd, $hd] = huella($pdo, $D, $t, $filtro);
    if ($ha === $hd) {
        $iguales++;
    } else {
        $mal("$t: contenido distinto ($na → $nd filas)");
    }
}
$ok("$iguales de ".count($enteras).' tablas idénticas fila a fila');
$aplicadas = $pdo->query("SELECT migration FROM `$D`.migrations WHERE migration IN ($nuevas)")->fetchAll(PDO::FETCH_COLUMN);
count($aplicadas) === count(MIGRACIONES_NUEVAS) ? $ok('migraciones nuevas aplicadas: '.implode(', ', $aplicadas))
    : $mal('faltan migraciones nuevas: '.implode(', ', array_diff(MIGRACIONES_NUEVAS, $aplicadas)));

echo "2. Tablas parciales o ajustadas (filas conservadas idénticas salvo las columnas ajustadas; 0 filas del alcance de borrado)\n";
foreach (array_unique(array_merge(array_keys(TABLAS_PARCIALES), array_keys($ajustes))) as $t) {
    $borrar = TABLAS_PARCIALES[$t] ?? null;
    $quedan = $borrar ? "NOT ($borrar)" : '1 = 1';
    $iguales = true;
    $resumen = [];
    // Filas fuera de todo alcance de ajuste: idénticas completas.
    $fuera = $quedan;
    foreach ($ajustes[$t] ?? [] as [$alcance]) {
        $fuera .= " AND NOT ($alcance)";
    }
    [$na, $ha] = huella($pdo, $A, $t, $fuera);
    [$nd, $hd] = huella($pdo, $D, $t, $fuera);
    $iguales = $iguales && $ha === $hd;
    $resumen[] = "$nd sin ajuste";
    // Filas dentro de cada alcance: idénticas salvo las columnas ajustadas.
    foreach ($ajustes[$t] ?? [] as [$alcance, $columnas]) {
        [$na, $ha] = huella($pdo, $A, $t, "$quedan AND ($alcance)", $columnas);
        [$nd, $hd] = huella($pdo, $D, $t, "$quedan AND ($alcance)", $columnas);
        $iguales = $iguales && $ha === $hd && $na === $nd;
        $resumen[] = "$nd ajustadas en ".implode(',', $columnas);
    }
    $restantes = $borrar ? (int) $pdo->query("SELECT COUNT(*) FROM `$D`.`$t` WHERE $borrar")->fetchColumn() : 0;
    $borradas = $borrar ? (int) $pdo->query("SELECT COUNT(*) FROM `$A`.`$t` WHERE $borrar")->fetchColumn() : 0;
    $txt = "$t: ".implode(', ', $resumen).($borrar ? "; borradas $borradas, quedan del alcance $restantes" : '');
    ($iguales && $restantes === 0) ? $ok($txt) : $mal($txt.($iguales ? '' : ' — CONTENIDO DISTINTO'));
}

echo "3. Existencias, reservas y rollos\n";
$qty = (int) $pdo->query("SELECT COUNT(*) FROM `$D`.product_inventories WHERE qty <> 0")->fetchColumn();
$qty === 0 ? $ok('product_inventories: ninguna fila con qty <> 0') : $mal("product_inventories: $qty filas con qty <> 0");
$idx = $pdo->query("SELECT COUNT(*), COALESCE(SUM(qty <> 0), 0) FROM `$D`.product_inventory_indices")->fetch(PDO::FETCH_NUM);
((int) $idx[1] === 0) ? $ok("product_inventory_indices: {$idx[0]} filas, ninguna con qty <> 0") : $mal("product_inventory_indices: {$idx[1]} filas con qty <> 0");
$sinIndice = (int) $pdo->query("SELECT COUNT(*) FROM `$D`.products p WHERE NOT EXISTS (SELECT 1 FROM `$D`.product_inventory_indices i WHERE i.product_id = p.id)")->fetchColumn();
echo "      productos sin fila de índice de inventario: $sinIndice (informativo)\n";
foreach (['product_ordered_inventories', 'cart_item_inventories', 'bylopez_cc_rollos', 'bylopez_cc_inventario_movimientos'] as $t) {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM `$D`.`$t`")->fetchColumn();
    $n === 0 ? $ok("$t: 0") : $mal("$t: $n filas");
}

echo "4. Tablas vaciadas\n";
$conDatos = [];
foreach (TABLAS_VACIAR as $t) {
    if (($n = (int) $pdo->query("SELECT COUNT(*) FROM `$D`.`$t`")->fetchColumn()) > 0) {
        $conDatos[] = "$t=$n";
    }
}
$conDatos ? $mal('con filas: '.implode(', ', $conDatos)) : $ok(count(TABLAS_VACIAR).' tablas en 0');

echo "5. Huérfanos\n";
$fks = $pdo->query('SELECT k.table_name, k.column_name, k.referenced_table_name, k.referenced_column_name
    FROM information_schema.key_column_usage k WHERE k.table_schema = '.$pdo->quote($D).' AND k.referenced_table_name IS NOT NULL')->fetchAll(PDO::FETCH_NUM);
$huerf = [];
foreach ($fks as [$t, $col, $p, $ref]) {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM `$D`.`$t` h WHERE h.`$col` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `$D`.`$p` x WHERE x.`$ref` = h.`$col`)")->fetchColumn();
    if ($n) {
        $huerf[] = "$t.{$col}→{$p}: $n";
    }
}
$huerf ? $mal('FK huérfanas: '.implode(', ', $huerf)) : $ok(count($fks).' claves foráneas sin huérfanos');
$hl = [];
foreach (REFERENCIAS_LOGICAS as [$t, $col, $p, $ref]) {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM `$D`.`$t` h WHERE h.`$col` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `$D`.`$p` x WHERE x.`$ref` = h.`$col`)")->fetchColumn();
    if ($n) {
        $hl[] = "$t.{$col}→{$p}: $n";
    }
}
$hl ? $mal('referencias lógicas huérfanas: '.implode(', ', $hl)) : $ok(count(REFERENCIAS_LOGICAS).' referencias lógicas sin huérfanos');

echo "6. Estado final de las decisiones\n";
$v = fn (string $sql) => $pdo->query($sql)->fetchColumn();
$admins = array_map('intval', $pdo->query("SELECT id FROM `$D`.admins")->fetchAll(PDO::FETCH_COLUMN));
$admins === [ADMIN_CONSERVADO_ID] ? $ok('admins: solo la cuenta '.ADMIN_CONSERVADO_ID) : $mal('admins: '.implode(',', $admins));
$rol = $v("SELECT r.permission_type FROM `$D`.admins a JOIN `$D`.roles r ON r.id = a.role_id WHERE a.id = ".ADMIN_CONSERVADO_ID);
$rol === 'all' ? $ok('la cuenta conservada tiene un rol de acceso total') : $mal("rol de la cuenta conservada: $rol");
$sufijo = $v("SELECT GROUP_CONCAT(DISTINCT value) FROM `$D`.core_config WHERE code = '".SUFIJO_FACTURA['code']."'");
$sufijo === SUFIJO_FACTURA['valor'] ? $ok("sufijo de facturas: $sufijo") : $mal("sufijo de facturas: $sufijo");
foreach (GENERADORES as $code => $clase) {
    $valor = $v("SELECT GROUP_CONCAT(DISTINCT value) FROM `$D`.core_config WHERE code = '$code'");
    $valor === $clase ? $ok("$code: $valor") : $mal("$code: $valor");
}
$n = (int) $v("SELECT COUNT(*) FROM `$D`.products WHERE id IN (".IDS_PRODUCTOS_PRUEBA.')');
$n === 0 ? $ok('productos de prueba: 0') : $mal("productos de prueba: $n");
$n = (int) $v("SELECT COUNT(*) FROM `$D`.roles WHERE id IN (".IDS_ROLES_QA.')');
$n === 0 ? $ok('roles QA: 0') : $mal("roles QA: $n");

echo $fallos ? 'RESULTADO: '.count($fallos)." falla(s)\n" : "RESULTADO: todo correcto\n";
exit($fallos ? 1 : 0);
