<?php

/*
| La cuenta restringida de VALIDAR de la demo (cargues_validar_demo) es UNA por conexion y el modulo exige que NO tenga privilegios fuera de la
| base del tipo. Si quedaron permisos de una demo anterior (otra base lpz_saas_demo_*), se revocan aqui, solo para ESA cuenta y solo sobre bases
| `lpz_saas_demo_*` distintas de la actual. Nunca toca otras cuentas ni otras bases.
|
|   bash demo.sh php packages/ByLopez/VentasAsesor/tests/Demo/cuenta_cargues_limpiar.php
*/

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$raiz = dirname(__DIR__, 5);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = (string) DB::selectOne('SELECT DATABASE() AS b')->b;

if (! str_starts_with($base, 'lpz_saas_demo_')) {
    fwrite(STDERR, "Bloqueado: base {$base}\n");
    exit(2);
}

$cuenta = "'cargues_validar_demo'@'127.0.0.1'";

try {
    $grants = array_map(fn ($f) => (string) array_values((array) $f)[0], DB::select("SHOW GRANTS FOR {$cuenta}"));
} catch (Throwable $e) {
    echo "La cuenta aun no existe: nada que limpiar.\n";
    exit(0);
}

$revocados = 0;

foreach ($grants as $g) {
    // GRANT <privilegios> ON [PROCEDURE] `db`.`objeto` TO ...
    if (! preg_match('/^GRANT (.+?) ON (?:(PROCEDURE|FUNCTION|TABLE) )?`([^`]+)`\.(`[^`]+`|\*) TO /', $g, $m)) {
        continue;
    }

    [, $priv, $tipo, $db, $objeto] = $m;

    if ($db === $base || ! str_starts_with($db, 'lpz_saas_demo_')) {
        if ($db !== $base) {
            echo "  OJO: privilegio fuera de una base demo, NO se toca: {$g}\n";
        }

        continue;
    }

    DB::unprepared("REVOKE {$priv} ON ".($tipo ? "{$tipo} " : '')."`{$db}`.{$objeto} FROM {$cuenta}");
    $revocados++;
}

echo "Privilegios revocados de demos anteriores: {$revocados}\n";
