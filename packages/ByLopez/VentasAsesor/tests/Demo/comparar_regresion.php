<?php

/*
| Compara dos listas de fallos generadas por resumir_regresion.php --json (p. ej. la corrida actual y la del respaldo ejecutada en una copia
| AISLADA, fuera del repositorio):
|   php comparar_regresion.php <actual.json> <respaldo.json>
| Imprime: fallan en ambos (previos con evidencia), solo en el codigo actual (regresiones a investigar) y solo en el respaldo (arreglados).
*/

[$actual, $respaldo] = [json_decode((string) file_get_contents($argv[1] ?? ''), true), json_decode((string) file_get_contents($argv[2] ?? ''), true)];

if (! $actual || ! $respaldo) {
    fwrite(STDERR, "Uso: comparar_regresion.php <actual.json> <respaldo.json>\n");
    exit(1);
}

$clave = fn (array $f) => basename(str_replace('\\', '/', explode('::', $f['archivo'])[0])).'::'.$f['prueba'];
$a = array_column(array_map(fn ($f) => ['k' => $clave($f), 'm' => $f['mensaje']], $actual['fallos']), 'm', 'k');
$b = array_column(array_map(fn ($f) => ['k' => $clave($f), 'm' => $f['mensaje']], $respaldo['fallos']), 'm', 'k');

$ambos = array_intersect_key($a, $b);
$soloActual = array_diff_key($a, $b);
$soloRespaldo = array_diff_key($b, $a);

echo 'Fallan en AMBOS (ya fallaban en el respaldo): '.count($ambos)."\n";
echo 'Fallan SOLO en el codigo actual: '.count($soloActual)."\n";

foreach ($soloActual as $k => $m) {
    echo "  ACTUAL: {$k}\n      {$m}\n";
}

echo 'Fallan SOLO en el respaldo (arreglados desde entonces): '.count($soloRespaldo)."\n";

foreach ($soloRespaldo as $k => $m) {
    echo "  RESPALDO: {$k}\n      {$m}\n";
}

echo "\nMensajes distintos entre ambos (misma prueba, distinta causa):\n";

foreach ($ambos as $k => $m) {
    if (mb_substr($m, 0, 80) !== mb_substr($b[$k], 0, 80)) {
        echo "  {$k}\n    actual:   {$m}\n    respaldo: {$b[$k]}\n";
    }
}
