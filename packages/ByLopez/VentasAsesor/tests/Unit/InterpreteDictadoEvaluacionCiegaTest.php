<?php

use ByLopez\VentasAsesor\Support\Dictado\InterpreteDictado;

/*
| Doc. 34 · Evaluación CIEGA (Datos/dictado_evaluacion_ciega.php): 80 frases sintéticas escritas el 2026-11-25 23:56 (commit
| bc25bd5496) antes de implementar la jornada, sin usarlas para programar. Umbrales fijados antes de la primera ejecución:
|  - cero interpretaciones incorrectas aceptadas (falsos positivos);
|  - al menos 90 % de líneas exactas (una prueba que rechaza todo no pasa);
|  - a lo sumo 5 % de aclaraciones innecesarias.
| Se mide por línea y por pedido (frase completa).
*/

function evaluacionCiega(): array
{
    $datos = require __DIR__.'/Datos/dictado_evaluacion_ciega.php';
    $interprete = new InterpreteDictado($datos['colores'], $datos['alias'], $datos['codigos']);
    $m = ['lineas' => 0, 'exactas' => 0, 'falsos_positivos' => 0, 'aclaraciones_innecesarias' => 0, 'aclaraciones_justas' => 0,
        'pedidos' => 0, 'pedidos_exactos' => 0, 'detalle' => []];

    foreach ($datos['casos'] as [$id, $texto, $esperadas]) {
        $obtenidas = $interprete->interpretar($texto);
        $pedidoExacto = true;
        $m['pedidos']++;

        for ($k = 0, $n = max(count($esperadas), count($obtenidas)); $k < $n; $k++) {
            $m['lineas']++;
            $e = $esperadas[$k] ?? null;
            $o = $obtenidas[$k] ?? null;
            $aceptada = $o !== null && $o['problemas'] === [];
            $campos = $o === null ? null : [$o['linea'] === 'TP' ? 'TP' : $o['genero'], $o['color'], $o['talla_etiqueta'], $o['cantidad']];

            $clase = match (true) {
                $aceptada && ($e === null || $e[4] !== 'ok' || $campos !== array_slice($e, 0, 4)) => 'falso_positivo',
                $e === null || $o === null => 'linea_de_mas_o_faltante',
                $e[4] === 'ok' => $aceptada ? 'exacta' : 'aclaracion_innecesaria',
                $e[4] === 'opciones' && count($o['opciones']) < 2 => 'aclara_sin_opciones',
                default => 'exacta',
            };

            if ($clase === 'exacta') {
                $m['exactas']++;
                if ($e[4] !== 'ok') {
                    $m['aclaraciones_justas']++;
                }
            } else {
                $pedidoExacto = false;
                $m['falsos_positivos'] += $clase === 'falso_positivo' ? 1 : 0;
                $m['aclaraciones_innecesarias'] += $clase === 'aclaracion_innecesaria' ? 1 : 0;
            }

            $m['detalle'][] = [$id, $texto, $k, $e, $o === null ? null : [...$campos, $o['problemas']], $clase];
        }

        $m['pedidos_exactos'] += $pedidoExacto ? 1 : 0;
    }

    return $m;
}

test('evaluacion ciega: cero falsos positivos, >= 90 % de lineas exactas, <= 5 % de aclaraciones innecesarias', function () {
    $m = evaluacionCiega();
    $fallos = array_values(array_filter($m['detalle'], fn ($d) => $d[5] !== 'exacta'));

    expect(array_values(array_filter($fallos, fn ($d) => $d[5] === 'falso_positivo')))->toBe([])
        ->and($m['exactas'] / $m['lineas'])->toBeGreaterThanOrEqual(0.90)
        ->and($m['aclaraciones_innecesarias'] / $m['lineas'])->toBeLessThanOrEqual(0.05);
});
