<?php

/*
| Limpieza de operaciones para el arranque en producción (ByLopez, 2026-11-15).
|
| Vacía pedidos, pagos, cartera, comisiones, logística, producción, clientes y ejecuciones de Cargues de una COPIA de la
| base; borra productos de prueba confirmados, cuentas de acceso (salvo la administrativa real) y roles QA sin uso;
| conserva catálogo y configuración. Pone existencias en cero y el sufijo de facturas en -2026 (decisiones de Camilo).
| No usa DDL ni TRUNCATE: solo DELETE/UPDATE dentro de una transacción InnoDB. No reinicia AUTO_INCREMENT.
|
| Uso:
|   php limpiar_base.php --cnf=<archivo .cnf de un usuario restringido a la copia> --base=<lpz_saas_preproduccion_...>
|                        [--ejecutar] [--log=<carpeta privada>]
|   Sin --ejecutar: simulación (conteos + ensayo completo dentro de una transacción que termina en ROLLBACK).
|
| Recuperación ante fallo: la ejecución es una sola transacción; si algo falla se hace ROLLBACK y la copia queda como
| estaba. Si la copia quedara en un estado dudoso, se descarta y se restaura otra vez desde el respaldo verificado.
*/

require __DIR__.'/limpiar_base_listas.php';

function salir(string $mensaje, int $codigo = 1): never
{
    fwrite(STDERR, "ABORTADO: $mensaje\n");
    exit($codigo);
}

function opciones(array $argv): array
{
    $o = ['ejecutar' => false, 'cnf' => null, 'base' => null, 'log' => null];
    foreach (array_slice($argv, 1) as $a) {
        if ($a === '--ejecutar') {
            $o['ejecutar'] = true;
        } elseif (preg_match('/^--(cnf|base|log)=(.+)$/', $a, $m)) {
            $o[$m[1]] = $m[2];
        } else {
            salir("argumento desconocido: $a", 2);
        }
    }
    if (! $o['cnf'] || ! $o['base']) {
        salir('faltan --cnf y --base', 2);
    }
    // Antes de conectar: el nombre de la base debe ser una copia de preparación.
    if (! preg_match('/^lpz_saas_preproduccion_[a-z0-9_]+$/', $o['base'])) {
        salir("la base '{$o['base']}' no es una copia de preparación (lpz_saas_preproduccion_*)", 2);
    }

    return $o;
}

function conectar(string $cnf, string $base): PDO
{
    $c = parse_ini_file($cnf, true, INI_SCANNER_RAW)['client'] ?? salir("sin sección [client] en $cnf");
    $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};dbname=$base;charset=utf8mb4", $c['user'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    return $pdo;
}

/** Guardas de destino: se repiten antes de cada etapa. */
function comprobarDestino(PDO $pdo, string $base): array
{
    if (! preg_match('/^lpz_saas_preproduccion_[a-z0-9_]+$/', $base)) {
        salir("la base '$base' no es una copia de preparación (lpz_saas_preproduccion_*)");
    }
    $fila = $pdo->query('SELECT DATABASE(), CURRENT_USER(), @@hostname, @@port, VERSION()')->fetch(PDO::FETCH_NUM);
    [$actual, $usuario] = $fila;
    if ($actual !== $base) {
        salir("SELECT DATABASE() = '$actual', se esperaba '$base'");
    }
    if (str_starts_with($usuario, 'root@')) {
        salir('no se permite ejecutar con root: use el usuario restringido a la copia');
    }
    // El usuario no debe poder leer la base fuente ni otras bases.
    foreach (['lpz_saas', 'lpz_saas_testing'] as $ajena) {
        try {
            $pdo->query("SELECT 1 FROM `$ajena`.`migrations` LIMIT 1");
            salir("el usuario $usuario puede leer `$ajena`: las credenciales no están restringidas a la copia");
        } catch (PDOException $e) {
            // esperado: acceso denegado
        }
    }

    return ['base' => $actual, 'usuario' => $usuario, 'servidor' => "$fila[2]:$fila[3]", 'version' => $fila[4]];
}

function contar(PDO $pdo, string $tabla, ?string $donde = null): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM `$tabla`".($donde ? " WHERE $donde" : ''))->fetchColumn();
}

function claves(PDO $pdo, string $base): array
{
    return $pdo->query('SELECT k.table_name AS hija, k.column_name AS columna, k.referenced_table_name AS padre,
            k.referenced_column_name AS ref, r.delete_rule AS regla, c.is_nullable AS nulable
        FROM information_schema.key_column_usage k
        JOIN information_schema.referential_constraints r ON r.constraint_schema = k.table_schema
            AND r.constraint_name = k.constraint_name AND r.table_name = k.table_name
        JOIN information_schema.columns c ON c.table_schema = k.table_schema AND c.table_name = k.table_name
            AND c.column_name = k.column_name
        WHERE k.table_schema = '.$pdo->quote($base).' AND k.referenced_table_name IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
}

/** Filtro de las filas que se borran (null = toda la tabla); false = la tabla no se borra. */
function filtroBorrado(string $tabla): string|false|null
{
    if (in_array($tabla, TABLAS_VACIAR, true)) {
        return null;
    }

    return TABLAS_PARCIALES[$tabla] ?? false;
}

/** Orden de borrado: una tabla va después de todas las que la referencian (hijas primero). */
function ordenBorrado(array $fks): array
{
    $borrar = array_merge(TABLAS_VACIAR, array_keys(TABLAS_PARCIALES));
    $dependientes = array_fill_keys($borrar, []);
    foreach ($fks as $fk) {
        if ($fk['hija'] !== $fk['padre'] && isset($dependientes[$fk['padre']]) && isset($dependientes[$fk['hija']])) {
            $dependientes[$fk['padre']][$fk['hija']] = true;
        }
    }
    $orden = [];
    $pendientes = $dependientes;
    while ($pendientes) {
        $libres = array_keys(array_filter($pendientes, fn ($hijas) => ! array_diff_key($hijas, array_flip($orden))));
        if (! $libres) {
            salir('ciclo de claves foráneas entre: '.implode(', ', array_keys($pendientes)));
        }
        sort($libres);
        foreach ($libres as $t) {
            $orden[] = $t;
            unset($pendientes[$t]);
        }
    }

    return $orden;
}

/** Ninguna fila conservada puede quedar apuntando a una fila borrada (evita cascadas o SET NULL sobre datos maestros). */
function comprobarReferenciasConservadas(PDO $pdo, array $fks): array
{
    $problemas = [];
    foreach ($fks as $fk) {
        $filtroPadre = filtroBorrado($fk['padre']);
        if ($filtroPadre === false || $fk['hija'] === $fk['padre']) {
            continue;
        }
        $filtroHija = filtroBorrado($fk['hija']);
        if ($filtroHija === null) {
            continue; // la hija se vacía completa
        }
        $sub = "SELECT `{$fk['ref']}` FROM `{$fk['padre']}`".($filtroPadre ? " WHERE $filtroPadre" : '');
        $donde = "`{$fk['columna']}` IN ($sub)".($filtroHija ? " AND NOT ($filtroHija)" : '');
        foreach (ANULACIONES as $a) {
            if ($a['tabla'] === $fk['hija'] && $a['columna'] === $fk['columna']) {
                $donde .= " AND NOT ({$a['donde']})"; // ese vínculo se anula antes de borrar
            }
        }
        $n = contar($pdo, $fk['hija'], $donde);
        if ($n > 0) {
            $problemas[] = "{$fk['hija']}.{$fk['columna']} → {$fk['padre']} ({$fk['regla']}): $n fila(s) conservadas";
        }
    }

    return $problemas;
}

function plan(PDO $pdo, array $fks): array
{
    $pasos = [];
    // Vínculos de fichas conservadas con filas que se borran: se anulan primero.
    foreach (ANULACIONES as $a) {
        $donde = "`{$a['columna']}` IS NOT NULL AND ({$a['donde']})";
        $pasos[] = ['tipo' => 'anular', 'tabla' => $a['tabla'], 'sql' => "UPDATE `{$a['tabla']}` SET `{$a['columna']}` = NULL WHERE $donde",
            'filas' => contar($pdo, $a['tabla'], $donde)];
    }
    // Autorreferencias RESTRICT dentro de tablas que se vacían: se anulan antes de borrar.
    foreach ($fks as $fk) {
        if ($fk['hija'] === $fk['padre'] && filtroBorrado($fk['hija']) !== false
            && in_array($fk['regla'], ['RESTRICT', 'NO ACTION'], true)) {
            if ($fk['nulable'] !== 'YES') {
                salir("autorreferencia no nulable {$fk['hija']}.{$fk['columna']}");
            }
            $f = filtroBorrado($fk['hija']);
            $pasos[] = ['tipo' => 'anular', 'tabla' => $fk['hija'],
                'sql' => "UPDATE `{$fk['hija']}` SET `{$fk['columna']}` = NULL WHERE `{$fk['columna']}` IS NOT NULL".($f ? " AND ($f)" : ''),
                'filas' => contar($pdo, $fk['hija'], "`{$fk['columna']}` IS NOT NULL".($f ? " AND ($f)" : ''))];
        }
    }
    foreach (ordenBorrado($fks) as $t) {
        $f = filtroBorrado($t);
        $pasos[] = ['tipo' => 'borrar', 'tabla' => $t, 'sql' => "DELETE FROM `$t`".($f ? " WHERE $f" : ''),
            'filas' => contar($pdo, $t, $f), 'total' => contar($pdo, $t)];
    }
    foreach (ACTUALIZACIONES as $a) {
        $pasos[] = ['tipo' => 'actualizar', 'tabla' => $a['tabla'], 'sql' => "UPDATE `{$a['tabla']}` SET {$a['set']} WHERE {$a['pendientes']}",
            'filas' => contar($pdo, $a['tabla'], $a['pendientes']), 'total' => contar($pdo, $a['tabla'])];
    }

    return $pasos;
}

function verificarResultado(PDO $pdo): array
{
    $fallos = [];
    foreach (TABLAS_VACIAR as $t) {
        if (($n = contar($pdo, $t)) !== 0) {
            $fallos[] = "$t conserva $n filas";
        }
    }
    foreach (TABLAS_PARCIALES as $t => $f) {
        if (($n = contar($pdo, $t, $f)) !== 0) {
            $fallos[] = "$t conserva $n filas del alcance";
        }
    }
    foreach (ACTUALIZACIONES as $a) {
        if (($n = contar($pdo, $a['tabla'], $a['pendientes'])) !== 0) {
            $fallos[] = "{$a['tabla']}: $n filas sin actualizar";
        }
    }
    foreach (ANULACIONES as $a) {
        if (($n = contar($pdo, $a['tabla'], "`{$a['columna']}` IS NOT NULL AND ({$a['donde']})")) !== 0) {
            $fallos[] = "{$a['tabla']}.{$a['columna']}: $n vínculos sin anular";
        }
    }
    comprobarAdmin($pdo, $fallos);

    return $fallos;
}

/** La cuenta conservada debe existir, estar activa y tener rol de acceso total; ninguna cuenta conservada usa un rol que se borra. */
function comprobarAdmin(PDO $pdo, array &$fallos): void
{
    $a = $pdo->query('SELECT a.status, r.permission_type FROM admins a LEFT JOIN roles r ON r.id = a.role_id WHERE a.id = '.ADMIN_CONSERVADO_ID)->fetch(PDO::FETCH_NUM);
    if (! $a || (int) $a[0] !== 1 || $a[1] !== 'all') {
        $fallos[] = 'la cuenta administrativa '.ADMIN_CONSERVADO_ID.' no existe, está inactiva o su rol no es de acceso total';
    }
    $n = contar($pdo, 'admins', 'NOT ('.TABLAS_PARCIALES['admins'].') AND role_id IN (SELECT id FROM roles WHERE '.TABLAS_PARCIALES['roles'].')');
    if ($n) {
        $fallos[] = "$n cuenta(s) conservada(s) usan un rol que se borra";
    }
}

// ---------------------------------------------------------------------------------------------------------------------
$o = opciones($argv);
$pdo = conectar($o['cnf'], $o['base']);
$destino = comprobarDestino($pdo, $o['base']);
$modo = $o['ejecutar'] ? 'EJECUCIÓN' : 'SIMULACIÓN';
echo "== $modo sobre {$destino['base']} ({$destino['servidor']}, {$destino['version']}) como {$destino['usuario']}\n";

// 1. Toda tabla de la base debe estar clasificada; una tabla nueva detiene la limpieza.
$reales = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
$clasificadas = array_merge(TABLAS_VACIAR, array_keys(TABLAS_PARCIALES), TABLAS_CONSERVAR);
if ($dup = array_diff_assoc($clasificadas, array_unique($clasificadas))) {
    salir('tabla clasificada dos veces: '.implode(', ', $dup));
}
if ($sin = array_diff($reales, $clasificadas)) {
    salir('tablas sin clasificar: '.implode(', ', $sin));
}
if ($faltan = array_diff($clasificadas, $reales)) {
    salir('tablas clasificadas que no existen: '.implode(', ', $faltan));
}
$motores = $pdo->query("SELECT DISTINCT engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
if ($motores !== ['InnoDB']) {
    salir('hay tablas no InnoDB; la transacción no garantizaría la reversión: '.implode(', ', $motores));
}
$ajustadas = array_merge(array_column(ANULACIONES, 'tabla'), array_column(ACTUALIZACIONES, 'tabla'), TABLAS_RECONSTRUIR);
if ($mal = array_diff($ajustadas, array_keys(TABLAS_PARCIALES), TABLAS_CONSERVAR)) {
    salir('ajustes sobre tablas que no se conservan: '.implode(', ', $mal));
}
$fallosAdmin = [];
comprobarAdmin($pdo, $fallosAdmin);
if ($fallosAdmin) {
    salir(implode('; ', $fallosAdmin));
}
echo 'Tablas: '.count($reales).' clasificadas ('.count(TABLAS_VACIAR).' vaciar, '.count(TABLAS_PARCIALES).' parciales, '
    .count(TABLAS_CONSERVAR).' conservar; '.count(ANULACIONES).' anulaciones, '.count(ACTUALIZACIONES).' actualizaciones, '
    .count(TABLAS_RECONSTRUIR)." a reconstruir)\n";

// 2. Referencias desde filas conservadas hacia filas que se borran.
$fks = claves($pdo, $o['base']);
if ($problemas = comprobarReferenciasConservadas($pdo, $fks)) {
    salir("filas conservadas que apuntan a filas que se borran:\n  ".implode("\n  ", $problemas));
}
echo "Referencias conservadas → borradas: ninguna\n";

// 3. Plan.
$pasos = plan($pdo, $fks);
$filasTotal = 0;
foreach ($pasos as $i => $p) {
    $filasTotal += $p['filas'];
    if ($p['filas'] > 0 || $p['tipo'] !== 'borrar') {
        printf("%3d. %-10s %-48s %6d fila(s)%s\n", $i + 1, $p['tipo'], $p['tabla'], $p['filas'], isset($p['total']) ? " de {$p['total']}" : '');
    }
}
echo 'Pasos: '.count($pasos)." (los borrados de tablas ya vacías no se listan) · filas afectadas: $filasTotal\n";

// 4. Ensayo o ejecución en una sola transacción.
comprobarDestino($pdo, $o['base']);
$registro = ['modo' => $modo, 'inicio' => date('c'), 'destino' => $destino, 'pasos' => []];
$pdo->beginTransaction();
try {
    foreach ($pasos as $p) {
        $afectadas = $pdo->exec($p['sql']);
        $registro['pasos'][] = ['tabla' => $p['tabla'], 'tipo' => $p['tipo'], 'previstas' => $p['filas'], 'afectadas' => $afectadas];
    }
    $fallos = verificarResultado($pdo);
    if ($fallos) {
        throw new RuntimeException("verificación interna:\n  ".implode("\n  ", $fallos));
    }
    if ($o['ejecutar']) {
        comprobarDestino($pdo, $o['base']);
        $pdo->commit();
        echo "COMMIT: limpieza aplicada y verificada dentro de la transacción.\n";
    } else {
        $pdo->rollBack();
        echo "Ensayo completo sin errores; ROLLBACK (la simulación no deja cambios).\n";
    }
    $registro['resultado'] = $o['ejecutar'] ? 'aplicada' : 'simulada';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $registro['resultado'] = 'error: '.$e->getMessage();
    echo "ERROR, ROLLBACK: {$e->getMessage()}\n";
}
$registro['fin'] = date('c');
if ($o['log']) {
    $archivo = rtrim($o['log'], '/\\').'/limpieza_'.$o['base'].'_'.date('Ymd_His').'_'.($o['ejecutar'] ? 'ejecucion' : 'simulacion').'.json';
    file_put_contents($archivo, json_encode($registro, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Registro: $archivo\n";
}
exit(str_starts_with($registro['resultado'], 'error') ? 1 : 0);
