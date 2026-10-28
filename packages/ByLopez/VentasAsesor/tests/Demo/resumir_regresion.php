<?php

/*
| Resume uno o varios informes JUnit de Pest (--log-junit) en una lista SIN DUPLICADOS de fallos:
|   php resumir_regresion.php <informe.xml> [<otro.xml> ...] [--json salida.json]
| Imprime: totales por informe y, por cada prueba fallida, archivo, nombre y primera linea del mensaje. Con --json guarda la lista para
| compararla con otra corrida (por ejemplo contra el respaldo) en comparar_regresion.php.
*/

$args = array_slice($argv, 1);
$json = null;

if (($i = array_search('--json', $args, true)) !== false) {
    $json = $args[$i + 1] ?? null;
    array_splice($args, $i, 2);
}

$fallos = [];
$totales = [];

foreach ($args as $ruta) {
    $xml = simplexml_load_file($ruta);

    if ($xml === false) {
        fwrite(STDERR, "No se pudo leer {$ruta}\n");
        exit(1);
    }

    $t = ['pruebas' => 0, 'fallos' => 0, 'errores' => 0, 'omitidas' => 0];

    foreach ($xml->xpath('//testcase') as $caso) {
        $t['pruebas']++;
        $malo = isset($caso->failure) ? $caso->failure : (isset($caso->error) ? $caso->error : null);

        if (isset($caso->skipped)) {
            $t['omitidas']++;
        }

        if ($malo === null) {
            continue;
        }

        isset($caso->failure) ? $t['fallos']++ : $t['errores']++;
        $archivo = str_replace('\\', '/', (string) $caso['file']);
        $archivo = preg_replace('#^.*?packages/#', 'packages/', $archivo);
        $mensaje = trim(preg_replace('/\s+/', ' ', strtok((string) $malo, "\n")));
        $clave = $archivo.'::'.(string) $caso['name'];
        $fallos[$clave] = ['archivo' => $archivo, 'prueba' => (string) $caso['name'], 'mensaje' => mb_substr($mensaje, 0, 220)];
    }

    $totales[basename($ruta)] = $t;
}

ksort($fallos);

foreach ($totales as $nombre => $t) {
    echo sprintf("%s: %d pruebas, %d fallos, %d errores, %d omitidas\n", $nombre, $t['pruebas'], $t['fallos'], $t['errores'], $t['omitidas']);
}

echo "\nFallos distintos: ".count($fallos)."\n";

foreach ($fallos as $f) {
    echo sprintf("- [%s] %s\n    %s\n", basename($f['archivo']), $f['prueba'], $f['mensaje']);
}

if ($json) {
    file_put_contents($json, json_encode(['totales' => $totales, 'fallos' => array_values($fallos)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
