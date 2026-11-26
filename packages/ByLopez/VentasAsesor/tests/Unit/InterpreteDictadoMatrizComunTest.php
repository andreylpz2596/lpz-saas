<?php

use ByLopez\VentasAsesor\Support\Dictado\InterpreteDictado;

/*
| Doc. 34 §6 · Matriz comun de la evaluacion de herramientas (2026-11-25): 100 frases, expectativas fijadas antes de correr.
| Contrato: CERO interpretaciones incorrectas aceptadas. Las dos diferencias conocidas («rojo 10 niño», «rojo niño 2 3») son un
| conflicto de expectativas pendiente de Camilo; ambas piden aclaracion (seguro), por eso no cuentan como aceptadas.
*/

test('matriz comun: ninguna interpretacion incorrecta se acepta como valida', function () {
    $matriz = require __DIR__.'/Datos/dictado_matriz_comun.php';
    $interprete = new InterpreteDictado(MC_COLORES, MC_ALIAS);
    $aceptadasMal = [];
    $correctas = 0;
    $total = 0;

    foreach ($matriz as [$grupo, $texto, $esperadas]) {
        $obtenidas = $interprete->interpretar($texto);
        for ($k = 0, $n = max(count($esperadas), count($obtenidas)); $k < $n; $k++) {
            $total++;
            $e = $esperadas[$k] ?? null;
            $o = $obtenidas[$k] ?? null;
            $aceptada = $o !== null && $o['problemas'] === [];
            $campos = $o === null ? null : [$o['linea'] === 'TP' ? 'TP' : $o['genero'], $o['color'], $o['talla_etiqueta'], $o['cantidad']];

            if ($aceptada && ($e === null || $e[4] !== 'ok' || $campos !== array_slice($e, 0, 4))) {
                $aceptadasMal[] = "[$grupo] {$texto} · linea $k";
            } elseif ($e !== null && $o !== null && ($e[4] === 'ok' ? $aceptada && $campos === array_slice($e, 0, 4)
                : (! $aceptada && ($e[4] !== 'opciones' || count($o['opciones']) >= 2)))) {
                $correctas++;
            }
        }
    }

    expect($aceptadasMal)->toBe([])
        ->and($total)->toBe(111)
        ->and($correctas)->toBeGreaterThanOrEqual(109);
});
