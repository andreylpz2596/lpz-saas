<?php

use ByLopez\VentasAsesor\Support\Dictado\InterpreteDictado;

/*
| Doc. 34 · pedido por voz: reglas del interprete (sin base de datos ni servicios externos). Colores = los 37 del catalogo real.
*/

const DIC_COLORES = ['Beige Claro', 'Beige Oscuro', 'Café', 'Mostaza', 'Kaki', 'Negro', 'Azul Oscuro', 'Azul Rey', 'Vino Tinto', 'Rojo',
    'Fucsia', 'Morado', 'Naranja', 'Gris Rato', 'Verde Cali', 'Verde Petróleo', 'Marfil', 'Verde Militar', 'Verde Pistacho', 'Verde Oliva',
    'Verde Manzana', 'Verde Pastel', 'Verde Neón', 'Blanco', 'Verde Menta', 'Verde Botella', 'Terracota', 'Lila', 'Curuba/Salmon',
    'Amarillo Pastel', 'Azul Cielo', 'Azul Bebe', 'Rosado', 'Palo de Rosa', 'Azul Turquesa', 'Verde Agua Marina', 'Azul Bebe Oscuro'];

/** @return list<array{0: mixed, 1: ?string, 2: string, 3: ?string, 4: ?int}> [color o candidatos, genero, linea, talla, cantidad] */
function dic(string $texto): array
{
    $interprete = new InterpreteDictado(DIC_COLORES, ['rosa' => ['Rosado', 'Palo de Rosa']]);

    return array_map(fn ($l) => [$l['color'] ?? $l['candidatos'], $l['genero'], $l['linea'], $l['talla_etiqueta'], $l['cantidad']], $interprete->interpretar($texto));
}

test('el ejemplo de Camilo, escrito o dictado con numeros en palabras, da tres lineas exactas', function () {
    $esperado = [['Rojo', 'N', 'DZ', '2-4', 2], ['Rojo', 'N', 'DZ', '6-8', 10], ['Azul Rey', 'D', 'DZ', 'S', 15]];

    expect(dic('roja 2-4, 2 unidades, roja 6-8, 10 unidades, azul rey talla S de dama, 15 unidades'))->toBe($esperado)
        ->and(dic('roja 2 a 4 dos unidades roja 6 8 diez unidades azul rey talla s de dama quince unidades'))->toBe($esperado)
        ->and(dic('Roja dos cuatro, dos unidades. Seis ocho diez unidades. Azul rey talla ese de mujer, quince'))->toBe($esperado);
});

test('el color y el genero se heredan a la siguiente linea hasta que se dice otro', function () {
    expect(dic('caballero negro M 10, L 5, XL 3 y blanco talla M 4'))->toBe([
        ['Negro', 'H', 'DZ', 'M', 10], ['Negro', 'H', 'DZ', 'L', 5], ['Negro', 'H', 'DZ', 'XL', 3], ['Blanco', 'H', 'DZ', 'M', 4],
    ])->and(dic('dama: azul rey S 10, roja 2-4 5, negra M 3'))->toBe([
        ['Azul Rey', 'D', 'DZ', 'S', 10], ['Rojo', 'N', 'DZ', '2-4', 5], ['Negro', 'D', 'DZ', 'M', 3],
    ]);
});

test('sinonimos de genero, tallas deletreadas, femeninos y plurales del color', function () {
    expect(dic('hombre gris equis ele 6'))->toBe([['Gris Rato', 'H', 'DZ', 'XL', 6]])
        ->and(dic('mujer turquesa talla eme veinticinco unidades'))->toBe([['Azul Turquesa', 'D', 'DZ', 'M', 25]])
        ->and(dic('negras talla L 20 señora, una blanca talla M'))->toBe([['Negro', 'D', 'DZ', 'L', 20], ['Blanco', 'D', 'DZ', 'M', 1]])
        ->and(dic('10 unidades azul rey M de caballero'))->toBe([['Azul Rey', 'H', 'DZ', 'M', 10]])
        ->and(dic('una camiseta roja talla M de hombre 5 unidades'))->toBe([['Rojo', 'H', 'DZ', 'M', 5]])
        ->and(dic('niña verde botella talla 10 cuatro unidades'))->toBe([['Verde Botella', 'N', 'DZ', '10-12', 4]]);
});

test('crop top: linea TP, dama y talla unica', function () {
    expect(dic('crop top negro 5 y blanco 3'))->toBe([['Negro', 'D', 'TP', 'Única', 5], ['Blanco', 'D', 'TP', 'Única', 3]]);
});

test('lo que falta o es ambiguo queda marcado: nunca se adivina el color', function () {
    $interprete = new InterpreteDictado(DIC_COLORES, ['rosa' => ['Rosado', 'Palo de Rosa']]);

    [$azul] = $interprete->interpretar('azul S 10');
    [$rosa] = $interprete->interpretar('mujer rosa M 3');
    [$sinTalla] = $interprete->interpretar('roja de dama 5');
    [$sinCantidad] = $interprete->interpretar('negro talla M caballero');

    expect($azul['color'])->toBeNull()->and($azul['problemas'])->toBe([InterpreteDictado::COLOR_AMBIGUO])
        ->and($azul['candidatos'])->toContain('Azul Rey', 'Azul Oscuro', 'Azul Turquesa')
        ->and($rosa['candidatos'])->toBe(['Rosado', 'Palo de Rosa'])
        ->and($sinTalla['problemas'])->toBe([InterpreteDictado::FALTA_TALLA])
        ->and($sinCantidad['problemas'])->toBe([InterpreteDictado::FALTA_CANTIDAD])
        ->and($interprete->interpretar('hola buenos dias'))->toBe([]);
});
