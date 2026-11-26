<?php

/*
| Doc. 34 · Evaluación CIEGA del dictado (escrita el 2026-11-26 00:10, ANTES de implementar los cambios de la jornada y sin
| ejecutarla durante el desarrollo). Frases SINTÉTICAS redactadas para evaluar: no son transcripciones de asesoras ni audio real.
| No se usaron para programar reglas (las de desarrollo están en InterpreteDictadoRevisionTest y en la matriz común).
|
| Línea esperada: [tipo, color, talla (etiqueta), cantidad, estado]
|   'ok'       → sin problemas y con esos 4 campos exactos (tipo null = no dicho; el intérprete no lo inventa).
|   'revisar'  → con al menos un problema (los campos no se comparan).
|   'opciones' → con problema Y al menos 2 opciones visibles (nunca una elección oculta).
| Reglas: Doc. 34 §2 y el brief del 2026-11-25 (orden libre, «una», correcciones, SKU, tallas de niño sueltas).
*/

$ok = fn (?string $t, string $c, string $s, int $q) => [$t, $c, $s, $q, 'ok'];
$rev = [null, null, null, null, 'revisar'];
$opc = [null, null, null, null, 'opciones'];

return [
    'colores' => ['Beige Claro', 'Beige Oscuro', 'Café', 'Mostaza', 'Kaki', 'Negro', 'Azul Oscuro', 'Azul Rey', 'Vino Tinto', 'Rojo',
        'Fucsia', 'Morado', 'Naranja', 'Gris Rato', 'Verde Cali', 'Verde Petróleo', 'Marfil', 'Verde Militar', 'Verde Pistacho', 'Verde Oliva',
        'Verde Manzana', 'Verde Pastel', 'Verde Neón', 'Blanco', 'Verde Menta', 'Verde Botella', 'Terracota', 'Lila', 'Curuba/Salmon',
        'Amarillo Pastel', 'Azul Cielo', 'Azul Bebe', 'Rosado', 'Palo de Rosa', 'Azul Turquesa', 'Verde Agua Marina', 'Azul Bebe Oscuro'],
    'alias' => ['rosa' => ['Rosado', 'Palo de Rosa']],
    'codigos' => [106 => 'Negro', 110 => 'Rojo', 124 => 'Blanco', 119 => 'Verde Pistacho'],
    'casos' => [
        // A. Orden libre (cantidad al principio, en medio y al final; tipo antes y después).
        ['E01', 'dos rojas M de dama', [$ok('D', 'Rojo', 'M', 2)]],
        ['E02', 'M de dama roja, dos', [$ok('D', 'Rojo', 'M', 2)]],
        ['E03', 'tres azul rey caballero L', [$ok('H', 'Azul Rey', 'L', 3)]],
        ['E04', 'caballero L azul rey tres', [$ok('H', 'Azul Rey', 'L', 3)]],
        ['E05', 'L tres caballero azul rey', [$ok('H', 'Azul Rey', 'L', 3)]],
        ['E06', 'azul oscuro dama ese cuatro', [$ok('D', 'Azul Oscuro', 'S', 4)]],
        ['E07', 'cuatro ese dama azul oscuro', [$ok('D', 'Azul Oscuro', 'S', 4)]],
        ['E08', 'verde botella XL caballero 6', [$ok('H', 'Verde Botella', 'XL', 6)]],
        ['E09', 'seis verde botella caballero equis ele', [$ok('H', 'Verde Botella', 'XL', 6)]],
        ['E10', 'una lila S de dama', [$ok('D', 'Lila', 'S', 1)]],
        ['E11', 'una eme lila dama', [$ok('D', 'Lila', 'M', 1)]],
        ['E12', 'un caballero negro talla L', [$ok('H', 'Negro', 'L', 1)]],
        ['E13', 'una referencia negra L caballero', [$rev]],
        ['E14', 'negra L caballero', [$rev]],
        // Varias prendas seguidas sin contaminación.
        ['E15', 'dos negras L caballero y una blanca M dama', [$ok('H', 'Negro', 'L', 2), $ok('D', 'Blanco', 'M', 1)]],
        ['E16', 'tres fucsia dama S, dos morado dama M, uno naranja caballero L', [$ok('D', 'Fucsia', 'S', 3), $ok('D', 'Morado', 'M', 2), $ok('H', 'Naranja', 'L', 1)]],
        ['E17', 'mostaza M dama 2 y mostaza M dama 2', [$ok('D', 'Mostaza', 'M', 2), $ok('D', 'Mostaza', 'M', 2)]],
        ['E18', 'mostaza mostaza M dama 2', [$ok('D', 'Mostaza', 'M', 2)]],
        // Correcciones y negaciones.
        ['E19', 'kaki caballero XL 4, no, mejor 5', [$ok('H', 'Kaki', 'XL', 5)]],
        ['E20', 'kaki caballero XL cuatro perdón cinco', [$ok('H', 'Kaki', 'XL', 5)]],
        ['E21', 'kaki, no, terracota caballero XL 4', [$ok('H', 'Terracota', 'XL', 4)]],
        ['E22', 'terracota caballero XL 4 no', [$rev]],
        ['E23', 'terracota dama caballero XL 4', [$rev]],
        ['E24', 'terracota dama, no, caballero XL 4', [$ok('H', 'Terracota', 'XL', 4)]],
        // Niño: rangos, alias y números sueltos.
        ['E25', 'niño azul cielo talla 2 cuatro', [$rev]],
        ['E26', 'niño azul cielo talla 2, cuatro unidades', [$ok('N', 'Azul Cielo', '2-4', 4)]],
        ['E27', 'cuatro azul cielo niño 6 8', [$ok('N', 'Azul Cielo', '6-8', 4)]],
        ['E28', 'azul cielo niño 6', [$opc]],
        ['E29', 'azul cielo 14 niño', [$opc]],
        ['E30', 'azul cielo niño 14 3', [$ok('N', 'Azul Cielo', '14-16', 3)]],
        ['E31', 'azul cielo niño 6 10', [$opc]],
        ['E32', 'azul cielo niño 1416', [$opc]],
        ['E33', 'azul cielo niño 1416 2', [$ok('N', 'Azul Cielo', '14-16', 2)]],
        // Cantidades inválidas.
        ['E34', 'amarillo pastel dama M 0', [$rev]],
        ['E35', 'amarillo pastel dama M 3,5', [$rev]],
        ['E36', 'amarillo pastel dama M -2', [$rev]],
        ['E37', 'amarillo pastel dama M 12000', [$rev]],
        ['E38', 'amarillo pastel dama M doce mil', [$rev]],
        // Crop top (el catálogo decide la talla; aquí solo lo dicho).
        ['E39', 'crop top lila 3', [$ok('TP', 'Lila', 'Única', 3)]],
        ['E40', 'crop top lila M 3', [$ok('TP', 'Lila', 'M', 3)]],
        ['E41', 'tres crop top lila M', [$ok('TP', 'Lila', 'M', 3)]],
        // Tipo entre dos prendas: alcance definido, nunca retroactivo en silencio.
        ['E42', 'dama vino tinto dos S y caballero gris rato tres M', [$ok('D', 'Vino Tinto', 'S', 2), $ok('H', 'Gris Rato', 'M', 3)]],
        ['E43', 'vino tinto S dos dama, gris rato M tres caballero', [$ok('D', 'Vino Tinto', 'S', 2), $ok('H', 'Gris Rato', 'M', 3)]],
        ['E44', 'vino tinto S dos dama gris rato M tres caballero', [$ok('D', 'Vino Tinto', 'S', 2), $ok('H', 'Gris Rato', 'M', 3)]],
        ['E45', 'vino tinto S dos, dama gris rato M tres', [$ok(null, 'Vino Tinto', 'S', 2), $ok('D', 'Gris Rato', 'M', 3)]],
        ['E46', 'vino tinto S dos dama, gris rato M tres', [$ok('D', 'Vino Tinto', 'S', 2), $ok('D', 'Gris Rato', 'M', 3)]],
        ['E47', 'vino tinto S dos dama gris rato M tres', [$rev, $ok('D', 'Gris Rato', 'M', 3)]],
        // Palabras de apoyo.
        ['E48', 'dame tres blancas S de caballero por favor', [$ok('H', 'Blanco', 'S', 3)]],
        ['E49', 'quiero cinco negras de dama en talla eme', [$ok('D', 'Negro', 'M', 5)]],
        ['E50', 'roja dama M 2 y negra caballero', [$ok('D', 'Rojo', 'M', 2), $rev]],
        ['E51', 'dos rojas dama, tres M', [$rev]],
        // SKU.
        ['E52', '124H-DZ-M 5', [$ok('H', 'Blanco', 'M', 5)]],
        ['E53', 'cinco 124H-DZ-M', [$ok('H', 'Blanco', 'M', 5)]],
        ['E54', '124H-DZ 5', [$rev]],
        ['E55', '124H-DZ-M 5 dama', [$rev]],
        ['E56', '124H-DZ-M caballero 5', [$ok('H', 'Blanco', 'M', 5)]],
        ['E57', '999H-DZ-M 5', [$rev]],
        ['E58', '124H-PD-M 5', [$rev]],
        ['E59', '124H-DZ-M 5 y 110N-DZ-6 3', [$ok('H', 'Blanco', 'M', 5), $ok('N', 'Rojo', '6-8', 3)]],
        ['E60', 'blanco caballero M 5 y blanco caballero M 3', [$ok('H', 'Blanco', 'M', 5), $ok('H', 'Blanco', 'M', 3)]],
        // Niño con número suelto: se pregunta.
        ['E61', 'rojo niño 2', [$opc]],
        ['E62', 'rojo dos niño', [$opc]],
        ['E63', 'niño rojo diez', [$opc]],
        ['E64', 'roja XL dama, sin cantidad', [$rev]],
        ['E65', 'negra dama M diez, no, ocho, perdón, nueve', [$ok('D', 'Negro', 'M', 9)]],
        ['E66', 'negra dama M diez, no', [$rev]],
        ['E67', 'no, negra dama M 2', [$rev]],
        // Repeticiones.
        ['E68', 'blanco caballero M 5 blanco caballero M 5', [$ok('H', 'Blanco', 'M', 5), $ok('H', 'Blanco', 'M', 5)]],
        ['E69', 'blanco blanco caballero caballero M M 5', [$ok('H', 'Blanco', 'M', 5)]],
        // Colores compuestos y ambiguos.
        ['E70', 'beige claro M dama 3', [$ok('D', 'Beige Claro', 'M', 3)]],
        ['E71', 'beige M dama 3', [$rev]],
        ['E72', 'verde M dama 3', [$rev]],
        ['E73', 'verde menta', [$rev]],
        ['E74', 'rosado dama S 2', [$ok('D', 'Rosado', 'S', 2)]],
        ['E75', 'curuba salmon dama S 2', [$ok('D', 'Curuba/Salmon', 'S', 2)]],
        ['E76', 'azul bebe oscuro caballero M 2', [$ok('H', 'Azul Bebe Oscuro', 'M', 2)]],
        ['E77', 'azul bebe caballero M 2', [$ok('H', 'Azul Bebe', 'M', 2)]],
        ['E78', 'verde agua marina dama L 1', [$ok('D', 'Verde Agua Marina', 'L', 1)]],
        ['E79', 'cinco cafe caballero XXL', [$ok('H', 'Café', 'XXL', 5)]],
        ['E80', 'cafe caballero doble equis ele cinco', [$ok('H', 'Café', 'XXL', 5)]],
    ],
];
