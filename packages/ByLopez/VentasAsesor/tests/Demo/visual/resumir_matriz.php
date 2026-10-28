<?php

// Resume la salida de matriz_visual.sh (una linea JSON por captura): http, desbordamiento horizontal y tema aplicado.
// Uso: php resumir_matriz.php <archivo.jsonl>
$ok = 0;
$resumen = [];

foreach (file($argv[1]) as $linea) {
    $j = json_decode($linea, true);

    if (! $j) {
        $resumen[] = 'LINEA ILEGIBLE (comillas sin escapar en la salida del navegador)';

        continue;
    }

    $s = $j['salida'];
    preg_match('/"http\\\\?": (\d+)/', $s, $h);
    preg_match('/px\\\\?": (-?\d+)/', $s, $d);
    preg_match('/oscuro\\\\?": (true|false)/', $s, $o);
    $http = $h[1] ?? '?';
    $ovf = $d[1] ?? '?';
    $oscuro = $o[1] ?? '?';
    $esperado = $j['tema'] === 'oscuro' ? 'true' : 'false';
    $fallo = str_contains($s, 'FALLO');
    $bien = ! $fallo && in_array($http, ['200', '401'], true) && $ovf === '0' && $oscuro === $esperado;
    $ok += $bien ? 1 : 0;

    if (! $bien || $http === '401') {
        $resumen[] = sprintf('%s %s %s %s http=%s desbordamiento=%s oscuro=%s %s', $j['rol'], $j['paso'], $j['ancho'], $j['tema'], $http, $ovf, $oscuro, $fallo ? 'FALLO' : '');
    }
}

echo "Capturas correctas (http 200/401, sin desbordamiento, tema aplicado): {$ok} de ".count(file($argv[1]))."\n".implode("\n", array_unique($resumen))."\n";
