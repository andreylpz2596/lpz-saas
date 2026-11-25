<?php

use ByLopez\VentasAsesor\Support\Dictado\InterpreteDictado;

/*
| Matriz de reproducción (revisión 2026-11-24, caso «roja 68 niño 10»). Resultados esperados fijados ANTES de mirar lo obtenido.
| Cada línea esperada: [tipo, color, talla, cantidad]; null = el campo debe quedar PENDIENTE (con problema), nunca inventado.
| Colores = los 37 del catálogo real.
*/

function dicm(string $texto): array
{
    $colores = ['Beige Claro', 'Beige Oscuro', 'Café', 'Mostaza', 'Kaki', 'Negro', 'Azul Oscuro', 'Azul Rey', 'Vino Tinto', 'Rojo',
        'Fucsia', 'Morado', 'Naranja', 'Gris Rato', 'Verde Cali', 'Verde Petróleo', 'Marfil', 'Verde Militar', 'Verde Pistacho', 'Verde Oliva',
        'Verde Manzana', 'Verde Pastel', 'Verde Neón', 'Blanco', 'Verde Menta', 'Verde Botella', 'Terracota', 'Lila', 'Curuba/Salmon',
        'Amarillo Pastel', 'Azul Cielo', 'Azul Bebe', 'Rosado', 'Palo de Rosa', 'Azul Turquesa', 'Verde Agua Marina', 'Azul Bebe Oscuro'];

    return array_map(fn ($l) => [
        $l['linea'] === 'TP' ? 'TP' : $l['genero'],
        $l['color'],
        $l['talla_etiqueta'],
        $l['cantidad'],
    ], (new InterpreteDictado($colores, ['rosa' => ['Rosado', 'Palo de Rosa']]))->interpretar($texto));
}

dataset('matriz', [
    // Caso reportado y variantes de la talla de niño.
    'roja 68 niño 10' => ['roja 68 niño 10', [['N', 'Rojo', '6-8', 10]]],
    'roja seis ocho niño diez' => ['roja seis ocho niño diez', [['N', 'Rojo', '6-8', 10]]],
    'roja sesenta y ocho niño diez' => ['roja sesenta y ocho niño diez', [['N', 'Rojo', '6-8', 10]]],
    'niño rojo 24 5' => ['niño rojo 24 5', [['N', 'Rojo', '2-4', 5]]],
    'niño rojo 10 12 5' => ['niño rojo 10 12 5', [['N', 'Rojo', '10-12', 5]]],
    'niño rojo 1012 5' => ['niño rojo 1012 5', [['N', 'Rojo', '10-12', 5]]],
    'niño rojo 14 16 4' => ['niño rojo 14 16 4', [['N', 'Rojo', '14-16', 4]]],
    'niño rojo talla 6 8' => ['niño rojo talla 6 8', [['N', 'Rojo', '6-8', null]]],
    'niño rojo cantidad 68 talla 6 8' => ['niño rojo cantidad 68 talla 6 8', [['N', 'Rojo', '6-8', 68]]],
    '10 camisetas niño rojo talla 6–8' => ['10 camisetas niño rojo talla 6–8', [['N', 'Rojo', '6-8', 10]]],
    'niño rojo 6-8 cantidad sesenta y ocho' => ['niño rojo 6-8 cantidad sesenta y ocho', [['N', 'Rojo', '6-8', 68]]],
    'roja 6.8 niño 10 (punto)' => ['roja 6.8 niño 10', [['N', 'Rojo', '6-8', 10]]],
    'roja 6,8 niño 10 (coma)' => ['roja 6,8 niño 10', [['N', 'Rojo', '6-8', 10]]],
    'roja de 6 a 8 niño 10' => ['roja de 6 a 8 niño 10', [['N', 'Rojo', '6-8', 10]]],
    // Adultos.
    'negro dama M 2' => ['negro dama M 2', [['D', 'Negro', 'M', 2]]],
    'negro caballero XL 12' => ['negro caballero XL 12', [['H', 'Negro', 'XL', 12]]],
    'caballero negro doble equis ele 3' => ['caballero negro doble equis ele 3', [['H', 'Negro', 'XXL', 3]]],
    'negro caballero 2XL 3' => ['negro caballero 2XL 3', [['H', 'Negro', 'XXL', 3]]],
    // Faltantes: no inventar.
    'rojo niño 10' => ['rojo niño 10', [['N', 'Rojo', null, 10]]],
    'azul S 3' => ['azul S 3', [[null, null, 'S', 3]]],
    'una camiseta roja talla M de hombre' => ['una camiseta roja talla M de hombre', [['H', 'Rojo', 'M', null]]],
    'niño rojo talla 6 (parcial)' => ['niño rojo talla 6', [['N', 'Rojo', null, null]]],
    // Cantidades inválidas: nunca partir ni descartar en silencio.
    'negro dama M 2.5' => ['negro dama M 2.5', [['D', 'Negro', 'M', null]]],
    'negro dama M -3' => ['negro dama M -3', [['D', 'Negro', 'M', null]]],
    // Varias líneas.
    'roja 68 niño 10 y negro dama M 2' => ['roja 68 niño 10 y negro dama M 2', [['N', 'Rojo', '6-8', 10], ['D', 'Negro', 'M', 2]]],
    'roja 68 niño 10, azul rey dama S 3' => ['roja 68 niño 10, azul rey dama S 3', [['N', 'Rojo', '6-8', 10], ['D', 'Azul Rey', 'S', 3]]],
    'negro dama M 2, negro dama M 3' => ['negro dama M 2, negro dama M 3', [['D', 'Negro', 'M', 2], ['D', 'Negro', 'M', 3]]],
    'roja 2.4, 2 unidades, roja 6.8, 10 unidades' => ['roja 2.4, 2 unidades, roja 6.8, 10 unidades', [['N', 'Rojo', '2-4', 2], ['N', 'Rojo', '6-8', 10]]],
    'negro M 10, 5 blanco M (orden antiguo)' => ['caballero negro M 10, 5 blanco M', [['H', 'Negro', 'M', 10], ['H', 'Blanco', 'M', 5]]],
    'top negro M 5' => ['top negro M 5', [['TP', 'Negro', 'M', 5]]],
]);

/*
| Expectativas REVISADAS por decision de Camilo (2026-11-24); la matriz queda intacta como evidencia del diagnostico y estos
| dos casos se prueban con la expectativa nueva en InterpreteDictadoRevisionTest:
|  - A: «una camiseta …» expresa 1 unidad (antes se esperaba cantidad pendiente).
|  - B: «talla 6» = 6-8: equivalencia unica confirmada en el catalogo (codigo del SKU ↔ grupo_de_edad_ninos).
*/
const DICM_REVISADAS = ['una camiseta roja talla M de hombre' => 'decision A', 'niño rojo talla 6' => 'decision B'];

test('matriz de dictado', function (string $frase, array $esperado) {
    if (isset(DICM_REVISADAS[$frase])) {
        $this->markTestSkipped('Expectativa revisada ('.DICM_REVISADAS[$frase].'): ver InterpreteDictadoRevisionTest.');
    }

    expect(dicm($frase))->toBe($esperado);
})->with('matriz')->group('dictado-matriz');
