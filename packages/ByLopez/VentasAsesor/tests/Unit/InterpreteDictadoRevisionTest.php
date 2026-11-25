<?php

use ByLopez\VentasAsesor\Support\Dictado\InterpreteDictado as I;

/*
| Doc. 34 · revision 2026-11-24 («roja 68 niño 10»). Salida COMPLETA de cada linea: cantidad de lineas, tipo, color, talla,
| cantidad, problemas, numeros sin asignar y opciones. Las expectativas se escribieron desde las reglas aprobadas, no desde lo
| obtenido. Colores = los 37 del catalogo real.
*/

const REV_COLORES = ['Beige Claro', 'Beige Oscuro', 'Café', 'Mostaza', 'Kaki', 'Negro', 'Azul Oscuro', 'Azul Rey', 'Vino Tinto', 'Rojo',
    'Fucsia', 'Morado', 'Naranja', 'Gris Rato', 'Verde Cali', 'Verde Petróleo', 'Marfil', 'Verde Militar', 'Verde Pistacho', 'Verde Oliva',
    'Verde Manzana', 'Verde Pastel', 'Verde Neón', 'Blanco', 'Verde Menta', 'Verde Botella', 'Terracota', 'Lila', 'Curuba/Salmon',
    'Amarillo Pastel', 'Azul Cielo', 'Azul Bebe', 'Rosado', 'Palo de Rosa', 'Azul Turquesa', 'Verde Agua Marina', 'Azul Bebe Oscuro'];

/** @return list<array{0: ?string, 1: ?string, 2: ?string, 3: ?int, 4: list<string>, 5: list<string>, 6: list<string>}> */
function rev(string $texto): array
{
    return array_map(fn ($l) => [
        $l['linea'] === 'TP' ? 'TP' : $l['genero'],
        $l['color'],
        $l['talla_etiqueta'],
        $l['cantidad'],
        $l['problemas'],
        $l['sin_asignar'],
        array_map(fn ($o) => ($o['talla_etiqueta'] ?? '?').'×'.($o['cantidad'] ?? '?'), $l['opciones']),
    ], (new I(REV_COLORES, ['rosa' => ['Rosado', 'Palo de Rosa']]))->interpretar($texto));
}

function ok(?string $tipo, string $color, string $talla, int $cantidad): array
{
    return [$tipo, $color, $talla, $cantidad, [], [], []];
}

test('prioridad: la contaminacion entre lineas desaparece', function () {
    expect(rev('roja 68 niño 10 y negro dama M 2'))->toBe([ok('N', 'Rojo', '6-8', 10), ok('D', 'Negro', 'M', 2)])
        ->and(rev('roja 68 niño 10, azul rey dama S 3'))->toBe([ok('N', 'Rojo', '6-8', 10), ok('D', 'Azul Rey', 'S', 3)]);
});

test('ningun numero pendiente viaja al producto siguiente', function () {
    // La linea incompleta conserva su numero y su problema; la siguiente queda exacta.
    expect(rev('roja niño 10 y negro dama M 2'))->toBe([
        ['N', 'Rojo', null, 10, [I::FALTA_TALLA], [], []],
        ok('D', 'Negro', 'M', 2),
    ])->and(rev('negro dama M 2, roja niño 68, blanco caballero L 3'))->toBe([
        ok('D', 'Negro', 'M', 2),
        ['N', 'Rojo', null, null, [I::TALLA_O_CANTIDAD], [], ['6-8×?', '?×68']],
        ok('H', 'Blanco', 'L', 3),
    ])->and(rev('negro M 10 5 blanco M 3'))->toBe([
        [null, 'Negro', 'M', 10, [I::NUMEROS_SIN_ASIGNAR], ['5'], []],
        [null, 'Blanco', 'M', 3, [], [], []],
    ]);
});

test('dos o tres productos con una linea invalida en medio', function () {
    expect(rev('negro dama M 2, roja 7 niño, blanco caballero L 3'))->toBe([
        ok('D', 'Negro', 'M', 2),
        ['N', 'Rojo', null, 7, [I::FALTA_TALLA], [], []],
        ok('H', 'Blanco', 'L', 3),
    ])->and(rev('negro dama M 2.5 blanco caballero L 3'))->toBe([
        ['D', 'Negro', 'M', null, [I::CANTIDAD_INVALIDA], [], []],
        ok('H', 'Blanco', 'L', 3),
    ]);
});

test('todos los pares infantiles y sus representaciones', function (string $frase, string $talla) {
    expect(rev($frase))->toBe([ok('N', 'Rojo', $talla, 5)]);
})->with([
    ['niño rojo 2-4 5', '2-4'], ['niño rojo 2 4 5', '2-4'], ['niño rojo 24 5', '2-4'], ['niño rojo dos cuatro cinco', '2-4'], ['niño rojo 2 a 4 5', '2-4'], ['niño rojo 2.4 5', '2-4'], ['niño rojo talla 2 5', '2-4'],
    ['niño rojo 6-8 5', '6-8'], ['niño rojo 6 8 5', '6-8'], ['niño rojo 68 5', '6-8'], ['niño rojo seis ocho cinco', '6-8'], ['niño rojo de 6 a 8 5', '6-8'], ['niño rojo 6,8 5', '6-8'], ['niño rojo talla 6 5', '6-8'],
    ['niño rojo 10-12 5', '10-12'], ['niño rojo 10 12 5', '10-12'], ['niño rojo 1012 5', '10-12'], ['niño rojo diez doce cinco', '10-12'], ['niño rojo talla 10 5', '10-12'],
    ['niño rojo 14-16 5', '14-16'], ['niño rojo 14 16 5', '14-16'], ['niño rojo 1416 5', '14-16'], ['niño rojo catorce dieciseis cinco', '14-16'], ['niño rojo talla 14 5', '14-16'],
]);

test('el caso reportado en todas sus formas', function (string $frase) {
    expect(rev($frase))->toBe([ok('N', 'Rojo', '6-8', 10)]);
})->with(['roja 68 niño 10', 'roja seis ocho niño diez', 'roja sesenta y ocho niño diez', 'Roja, 68, niño, 10', 'ROJA 6/8 NIÑO 10', 'niño rojo 6.8 10', 'roja 6:08 niño 10']);

test('«seis y ocho», «6, 8» y «6:08» solo son talla si el resto de la linea queda completo', function () {
    expect(rev('niño rojo seis y ocho diez'))->toBe([ok('N', 'Rojo', '6-8', 10)])
        ->and(rev('niño rojo 6, 8, 10'))->toBe([ok('N', 'Rojo', '6-8', 10)])
        // Sin cantidad no hay contexto: se pide revisar, sin inventar talla ni cantidad.
        ->and(rev('niño rojo 6 y 8'))->toBe([['N', 'Rojo', null, null, [I::FALTA_TALLA, I::FALTA_CANTIDAD, I::NUMEROS_SIN_ASIGNAR], ['6', '8'], []]])
        ->and(rev('rojo 6, 8'))->toBe([[null, 'Rojo', null, null, [I::FALTA_TALLA, I::FALTA_CANTIDAD, I::NUMEROS_SIN_ASIGNAR], ['6', '8'], []]]);
});

test('cantidad al principio y al final; marcadores', function () {
    expect(rev('10 camisetas niño rojo talla 6-8'))->toBe([ok('N', 'Rojo', '6-8', 10)])
        ->and(rev('10 niño rojo talla 6-8'))->toBe([ok('N', 'Rojo', '6-8', 10)])
        ->and(rev('niño rojo 6-8 10'))->toBe([ok('N', 'Rojo', '6-8', 10)])
        ->and(rev('niño rojo cantidad 68 talla 6 8'))->toBe([ok('N', 'Rojo', '6-8', 68)])
        ->and(rev('niño rojo 6-8 cantidad sesenta y ocho'))->toBe([ok('N', 'Rojo', '6-8', 68)])
        ->and(rev('niño rojo talla 68 10'))->toBe([ok('N', 'Rojo', '6-8', 10)])
        // Orden antiguo con la cantidad antes del producto siguiente: unica lectura.
        ->and(rev('caballero negro M 10, 5 blanco M'))->toBe([ok('H', 'Negro', 'M', 10), ok('H', 'Blanco', 'M', 5)]);
});

test('C: un numero que puede ser talla o cantidad se pregunta', function () {
    expect(rev('niño rojo 68'))->toBe([['N', 'Rojo', null, null, [I::TALLA_O_CANTIDAD], [], ['6-8×?', '?×68']]])
        ->and(rev('niño rojo 24'))->toBe([['N', 'Rojo', null, null, [I::TALLA_O_CANTIDAD], [], ['2-4×?', '?×24']]])
        ->and(rev('niño rojo 68 24'))->toBe([['N', 'Rojo', null, null, [I::TALLA_O_CANTIDAD], [], ['6-8×24', '2-4×68']]]);
});

test('cantidades invalidas nunca se vuelven validas', function (string $frase, string $dicho) {
    $l = (new I(REV_COLORES))->interpretar($frase);

    expect($l)->toHaveCount(1)
        ->and($l[0]['cantidad'])->toBeNull()
        ->and($l[0]['problemas'])->toBe([I::CANTIDAD_INVALIDA])
        ->and($l[0]['detalle']['cantidad_invalida'])->toBe($dicho);
})->with([
    ['negro dama M 0', '0'], ['negro dama M -3', '-3'], ['negro dama M menos tres', '-3'], ['negro dama M 2.5', '2.5'],
    ['negro dama M 2,5', '2.5'], ['negro dama M cantidad 2.4', '2.4'], ['negro dama M 99999', '99999'], ['negro dama M 2.5 unidades', '2.5'],
]);

test('numeros sobrantes, frases incompletas y atributos contradictorios', function () {
    expect(rev('negro dama M 2 4'))->toBe([['D', 'Negro', 'M', null, [I::FALTA_CANTIDAD, I::NUMEROS_SIN_ASIGNAR], ['2', '4'], []]])
        ->and(rev('negro dama M 2, 5'))->toBe([['D', 'Negro', 'M', null, [I::FALTA_CANTIDAD, I::NUMEROS_SIN_ASIGNAR], ['2', '5'], []]])
        ->and(rev('rojo niño 10'))->toBe([['N', 'Rojo', null, 10, [I::FALTA_TALLA], [], []]])
        ->and(rev('roja niño talla'))->toBe([['N', 'Rojo', null, null, [I::FALTA_TALLA, I::FALTA_CANTIDAD], [], []]])
        ->and(rev('niño rojo talla 8 5'))->toBe([['N', 'Rojo', null, 5, [I::TALLA_NO_RECONOCIDA], [], []]])
        ->and(rev('azul S 3'))->toBe([[null, null, 'S', 3, [I::COLOR_AMBIGUO], [], []]])
        // E: el tipo dicho no se reemplaza para que coincida la talla (el catalogo dira que no existe).
        ->and(rev('dama roja 2-4 5'))->toBe([ok('D', 'Rojo', '2-4', 5)])
        ->and(rev('dama roja 68 5'))->toBe([['D', 'Rojo', null, null, [I::FALTA_TALLA, I::FALTA_CANTIDAD, I::NUMEROS_SIN_ASIGNAR], ['68', '5'], []]]);
});

test('A: «una camiseta» expresa 1; con otra cantidad se pide revisar; «una referencia» no es cantidad', function () {
    expect(rev('una camiseta roja talla M de hombre'))->toBe([ok('H', 'Rojo', 'M', 1)])
        ->and(rev('una blanca talla M dama'))->toBe([ok('D', 'Blanco', 'M', 1)])
        ->and(rev('una camiseta roja talla M de hombre 5 unidades'))->toBe([['H', 'Rojo', 'M', null, [I::CANTIDAD_CONTRADICTORIA], [], []]])
        ->and(rev('una camiseta roja talla M de hombre 5'))->toBe([['H', 'Rojo', 'M', null, [I::CANTIDAD_CONTRADICTORIA], [], []]])
        ->and(rev('una referencia roja talla M dama'))->toBe([['D', 'Rojo', 'M', null, [I::FALTA_CANTIDAD], [], []]]);
});

test('B: «talla 6» se reconoce como 6-8 (equivalencia del catalogo) y se muestra 6-8', function () {
    [$l] = (new I(REV_COLORES))->interpretar('niño rojo talla 6');

    expect($l['talla'])->toBe('6')->and($l['talla_etiqueta'])->toBe('6-8')->and($l['fuentes']['talla'])->toBe('talla 6')
        ->and($l['problemas'])->toBe([I::FALTA_CANTIDAD]);
});

test('D: XXL con sus alias, nunca degradado a XL', function (string $frase) {
    expect(rev($frase))->toBe([ok('H', 'Negro', 'XXL', 3)]);
})->with(['caballero negro XXL 3', 'caballero negro 2XL 3', 'caballero negro doble equis ele 3', 'caballero negro doble XL 3', 'caballero negro talla doble x l 3', 'caballero negro equis equis ele 3']);

test('repeticiones legitimas y varias lineas seguidas', function () {
    expect(rev('negro dama M 2, negro dama M 2'))->toBe([ok('D', 'Negro', 'M', 2), ok('D', 'Negro', 'M', 2)])
        ->and(rev('niño rojo 6-8 10 y 2-4 5'))->toBe([ok('N', 'Rojo', '6-8', 10), ok('N', 'Rojo', '2-4', 5)])
        ->and(rev('roja 2.4, 2 unidades, roja 6.8, 10 unidades'))->toBe([ok('N', 'Rojo', '2-4', 2), ok('N', 'Rojo', '6-8', 10)])
        // El crop top existe en M y en Única: sin talla dicha no se copia la M de la linea anterior.
        ->and(rev('crop top negro M 5 y blanco 3'))->toBe([ok('TP', 'Negro', 'M', 5), ['TP', 'Blanco', null, 3, [I::FALTA_TALLA], [], []]]);
});

test('cada campo conserva las palabras que lo sustentan', function () {
    [$l] = (new I(REV_COLORES))->interpretar('roja 68 niño 10');

    expect($l['fuentes'])->toBe(['color' => 'roja', 'genero' => 'nino', 'talla' => '68', 'cantidad' => '10'])
        ->and($l['texto'])->toBe('roja 68 nino 10');
});

test('lo que escribe el navegador: «ese/eme/ele» por S/M/L y «de» intermedio en el color (prueba en iPhone 2026-11-25)', function () {
    expect(rev('rojo ese hombre 20 u'))->toBe([ok('H', 'Rojo', 'S', 20)])
        ->and(rev('negro eme dama 5'))->toBe([ok('D', 'Negro', 'M', 5)])
        ->and(rev('caballero blanco ele 3'))->toBe([ok('H', 'Blanco', 'L', 3)])
        ->and(rev('verde de petróleo XL de dama 10 u'))->toBe([ok('D', 'Verde Petróleo', 'XL', 10)])
        ->and(rev('palo de rosa M dama 2'))->toBe([ok('D', 'Palo de Rosa', 'M', 2)])
        // «ese» que no es talla: antes de un color, o sin color ni tipo en la linea.
        ->and(rev('ese rojo talla M de dama 4'))->toBe([ok('D', 'Rojo', 'M', 4)])
        ->and(rev('negro dama M 2, ese blanco L de caballero 3'))->toBe([ok('D', 'Negro', 'M', 2), ok('H', 'Blanco', 'L', 3)]);
});

test('prueba en iPhone 2026-11-25: «ese» antes del color, «una» seguido de «de», tipo dicho despues y cantidad antes del producto', function () {
    expect(rev('Listos una azul rey 68 una lila M dama una de ese marfil, Caballero, 20 beige claro, En caballero talla L,'))->toBe([
        ok('N', 'Azul Rey', '6-8', 1),
        ok('D', 'Lila', 'M', 1),
        ok('H', 'Marfil', 'S', 1),
        ok('H', 'Beige Claro', 'L', 20),
    ])->and(rev('una azul rey 68, una lila M dama'))->toBe([ok('N', 'Azul Rey', '6-8', 1), ok('D', 'Lila', 'M', 1)])
        ->and(rev('20 beige claro en caballero talla L'))->toBe([ok('H', 'Beige Claro', 'L', 20)]);
});

test('listado de Camilo 2026-11-25: S/M/L/XL sin decir «talla», con cantidad al principio o al final', function () {
    expect(rev('blanco S caballero, 20 unidades. Verde pistacho XL de dama, 15 unidades. 208 talla M de hombre, blancas.'))->toBe([
        ok('H', 'Blanco', 'S', 20),
        ok('D', 'Verde Pistacho', 'XL', 15),
        ok('H', 'Blanco', 'M', 208),
    ]);
});
