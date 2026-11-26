<?php

use ByLopez\VentasAsesor\Support\Dictado\InterpreteDictado as I;

/*
| Doc. 34 · Propiedades del interprete (jornada 2026-11-26). No prueban frases sueltas: prueban reglas que deben valer para
| TODAS las frases de la matriz comun y de la evaluacion ciega, y para combinaciones generadas.
| Lo que NO se exige: que un reordenamiento con numeros ambiguos se resuelva solo (ahi debe pedir aclaracion).
*/

function propInterprete(): I
{
    $datos = require __DIR__.'/Datos/dictado_evaluacion_ciega.php';

    return new I($datos['colores'], $datos['alias'], $datos['codigos']);
}

/** @return list<string> todas las frases conocidas (matriz comun + evaluacion ciega) */
function propFrases(): array
{
    static $frases = null;
    if ($frases === null) {
        $comun = require __DIR__.'/Datos/dictado_matriz_comun.php';
        $ciega = require __DIR__.'/Datos/dictado_evaluacion_ciega.php';
        $frases = [...array_column($comun, 1), ...array_column($ciega['casos'], 1)];
    }

    return $frases;
}

/** Linea reducida a lo que importa para comparar. */
function propLinea(array $l): array
{
    return [$l['linea'] === 'TP' ? 'TP' : $l['genero'], $l['color'], $l['talla'], $l['cantidad'], $l['problemas']];
}

test('mayusculas, espacios extra y el orden de lectura no cambian el resultado (todas las frases conocidas)', function () {
    $i = propInterprete();
    foreach (propFrases() as $f) {
        $base = array_map('propLinea', $i->interpretar($f));
        expect(array_map('propLinea', $i->interpretar(mb_strtoupper($f))))->toBe($base, "mayusculas: $f")
            ->and(array_map('propLinea', $i->interpretar('  '.str_replace(' ', '   ', $f).'  ')))->toBe($base, "espacios: $f")
            // Determinismo: mismo texto, mismo catalogo, otra instancia → mismo resultado.
            ->and(array_map('propLinea', propInterprete()->interpretar($f)))->toBe($base, "determinismo: $f");
    }
});

test('reordenar los cuatro atributos de una prenda inequivoca no cambia el resultado (24 ordenes por prenda)', function (array $piezas, array $esperado) {
    $i = propInterprete();
    $ordenes = [[]];
    foreach ($piezas as $_) {
        $nuevos = [];
        foreach ($ordenes as $o) {
            foreach ($piezas as $k => $p) {
                if (! in_array($k, $o, true)) {
                    $nuevos[] = [...$o, $k];
                }
            }
        }
        $ordenes = $nuevos;
    }

    foreach ($ordenes as $o) {
        $frase = implode(' ', array_map(fn ($k) => $piezas[$k], $o));
        expect(array_map('propLinea', $i->interpretar($frase)))->toBe([$esperado], $frase);
    }
})->with([
    'rojo dama M dos' => [['roja', 'dama', 'M', 'dos'], ['D', 'Rojo', 'M', 2, []]],
    'azul rey caballero L 15' => [['azul rey', 'caballero', 'L', '15'], ['H', 'Azul Rey', 'L', 15, []]],
    'verde botella caballero XXL quince' => [['verde botella', 'caballero', 'doble equis ele', 'quince'], ['H', 'Verde Botella', 'XXL', 15, []]],
    'lila dama ese 3' => [['lila', 'dama', 'ese', '3'], ['D', 'Lila', 'S', 3, []]],
    'beige claro dama equis ele 7' => [['beige claro', 'de dama', 'equis ele', '7'], ['D', 'Beige Claro', 'XL', 7, []]],
    'niño rojo talla 10 cantidad 2' => [['niño', 'roja', 'talla 10', 'cantidad 2'], ['N', 'Rojo', '10', 2, []]],
]);

test('agregar otra prenda no cambia las anteriores (con «y», con coma y sin separador)', function () {
    $i = propInterprete();
    $prendas = ['roja dama M 2', 'azul rey caballero L 15', 'niño blanco talla 6-8 4', 'verde botella caballero XXL 3', 'una lila dama S',
        'crop top negro M 5', 'dos negras de caballero en XL', '124H-DZ-M 5'];

    foreach ($prendas as $a) {
        foreach ($prendas as $b) {
            $solaA = array_map('propLinea', $i->interpretar($a));
            $solaB = array_map('propLinea', $i->interpretar($b));
            foreach ([' y ', ', ', ' '] as $sep) {
                $juntas = array_map('propLinea', $i->interpretar($a.$sep.$b));
                expect(array_slice($juntas, 0, count($solaA)))->toBe($solaA, "«{$a}{$sep}{$b}» cambio la primera")
                    ->and(array_slice($juntas, count($solaA)))->toBe($solaB, "«{$a}{$sep}{$b}» cambio la segunda");
            }
        }
    }
});

test('toda linea valida tiene color, talla y una cantidad entera de 1 a 9999; un numero nunca vale por dos campos', function () {
    $i = propInterprete();
    foreach (propFrases() as $f) {
        foreach ($i->interpretar($f) as $l) {
            if ($l['problemas'] === []) {
                expect($l['color'])->not->toBeNull($f)
                    ->and($l['talla'])->not->toBeNull($f)
                    ->and($l['cantidad'])->toBeInt($f)
                    ->and($l['cantidad'])->toBeGreaterThanOrEqual(1)
                    ->and($l['cantidad'])->toBeLessThanOrEqual(9999);
                if ($l['linea'] === 'TP') {
                    expect($l['genero'])->toBe('D', $f);
                }
            }
            // Si talla y cantidad salen del mismo numero, ese numero tiene que estar dicho dos veces.
            $t = $l['fuentes']['talla'];
            $c = $l['fuentes']['cantidad'];
            if ($t !== null && $c !== null && $t === $c && ctype_digit((string) $t)) {
                expect(substr_count(' '.$l['texto'].' ', ' '.$t.' '))->toBeGreaterThanOrEqual(2, $f);
            }
        }
    }
});

test('ningun dato desaparece en silencio: toda palabra que no es de apoyo queda en el texto de alguna linea', function () {
    $i = propInterprete();
    $r = new ReflectionClass($i);
    $tokens = $r->getMethod('tokens');
    $numeros = $r->getMethod('numeros');
    $apoyo = $r->getConstant('APOYO');

    foreach (propFrases() as $f) {
        $lineas = $i->interpretar($f);
        $texto = ' '.implode(' ', array_column($lineas, 'texto')).' ';
        foreach ($numeros->invoke($i, $tokens->invoke($i, $f)) as $tok) {
            if ($tok === '|' || in_array($tok, $apoyo, true)) {
                continue;
            }
            expect(str_contains($texto, ' '.$tok.' ') || str_contains($texto, $tok))->toBeTrue("«{$tok}» se perdio en «{$f}»");
        }
    }
});
