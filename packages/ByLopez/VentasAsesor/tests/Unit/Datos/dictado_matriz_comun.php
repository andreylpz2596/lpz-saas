<?php

/*
| Matriz comun para comparar enfoques (2026-11-25). Expectativas fijadas ANTES de ejecutar cualquiera de los dos.
| Linea esperada: [tipo, color, talla, cantidad, estado]
|   estado 'ok'       → debe salir sin problemas y con esos 4 campos exactos.
|   estado 'revisar'  → debe salir con al menos un problema (los campos no se comparan).
|   estado 'opciones' → debe salir con problema Y con >= 2 opciones visibles (ambiguedad, nunca eleccion oculta).
| Grupo 'previa' = matriz del 2026-11-24 (con las decisiones A y B ya aprobadas). 'reglas' = obligatorias del brief.
| 'nueva' = frases redactadas hoy, no usadas al programar las reglas (NO son transcripciones reales de asesoras).
*/

// Se puede incluir varias veces en la misma corrida (matriz comun y propiedades): constantes y funciones con guarda.
defined('MC_COLORES') || define('MC_COLORES', ['Beige Claro', 'Beige Oscuro', 'Café', 'Mostaza', 'Kaki', 'Negro', 'Azul Oscuro', 'Azul Rey', 'Vino Tinto', 'Rojo',
    'Fucsia', 'Morado', 'Naranja', 'Gris Rato', 'Verde Cali', 'Verde Petróleo', 'Marfil', 'Verde Militar', 'Verde Pistacho', 'Verde Oliva',
    'Verde Manzana', 'Verde Pastel', 'Verde Neón', 'Blanco', 'Verde Menta', 'Verde Botella', 'Terracota', 'Lila', 'Curuba/Salmon',
    'Amarillo Pastel', 'Azul Cielo', 'Azul Bebe', 'Rosado', 'Palo de Rosa', 'Azul Turquesa', 'Verde Agua Marina', 'Azul Bebe Oscuro']);

defined('MC_ALIAS') || define('MC_ALIAS', ['rosa' => ['Rosado', 'Palo de Rosa']]);

if (! function_exists('mcOk')) {
    function mcOk($t, $c, $s, $q) { return [$t, $c, $s, $q, 'ok']; }
    function mcRev() { return [null, null, null, null, 'revisar']; }
    function mcOpc() { return [null, null, null, null, 'opciones']; }
}

return [
    // ---- previa (matriz 2026-11-24; A y B revisadas ese dia) ----
    ['previa', 'roja 68 niño 10', [mcOk('N', 'Rojo', '6-8', 10)]],
    ['previa', 'roja seis ocho niño diez', [mcOk('N', 'Rojo', '6-8', 10)]],
    ['previa', 'roja sesenta y ocho niño diez', [mcOk('N', 'Rojo', '6-8', 10)]],
    ['previa', 'niño rojo 24 5', [mcOk('N', 'Rojo', '2-4', 5)]],
    ['previa', 'niño rojo 10 12 5', [mcOk('N', 'Rojo', '10-12', 5)]],
    ['previa', 'niño rojo 1012 5', [mcOk('N', 'Rojo', '10-12', 5)]],
    ['previa', 'niño rojo 14 16 4', [mcOk('N', 'Rojo', '14-16', 4)]],
    ['previa', 'niño rojo talla 6 8', [mcRev()]],
    ['previa', 'niño rojo cantidad 68 talla 6 8', [mcOk('N', 'Rojo', '6-8', 68)]],
    ['previa', '10 camisetas niño rojo talla 6–8', [mcOk('N', 'Rojo', '6-8', 10)]],
    ['previa', 'niño rojo 6-8 cantidad sesenta y ocho', [mcOk('N', 'Rojo', '6-8', 68)]],
    ['previa', 'roja 6.8 niño 10', [mcOk('N', 'Rojo', '6-8', 10)]],
    ['previa', 'roja 6,8 niño 10', [mcOk('N', 'Rojo', '6-8', 10)]],
    ['previa', 'roja de 6 a 8 niño 10', [mcOk('N', 'Rojo', '6-8', 10)]],
    ['previa', 'negro dama M 2', [mcOk('D', 'Negro', 'M', 2)]],
    ['previa', 'negro caballero XL 12', [mcOk('H', 'Negro', 'XL', 12)]],
    ['previa', 'caballero negro doble equis ele 3', [mcOk('H', 'Negro', 'XXL', 3)]],
    ['previa', 'negro caballero 2XL 3', [mcOk('H', 'Negro', 'XXL', 3)]],
    ['previa', 'rojo niño 10', [mcRev()]],
    ['previa', 'azul S 3', [mcRev()]],
    ['previa', 'una camiseta roja talla M de hombre', [mcOk('H', 'Rojo', 'M', 1)]],
    ['previa', 'niño rojo talla 6', [mcRev()]],
    ['previa', 'negro dama M 2.5', [mcRev()]],
    ['previa', 'negro dama M -3', [mcRev()]],
    ['previa', 'roja 68 niño 10 y negro dama M 2', [mcOk('N', 'Rojo', '6-8', 10), mcOk('D', 'Negro', 'M', 2)]],
    ['previa', 'roja 68 niño 10, azul rey dama S 3', [mcOk('N', 'Rojo', '6-8', 10), mcOk('D', 'Azul Rey', 'S', 3)]],
    ['previa', 'negro dama M 2, negro dama M 3', [mcOk('D', 'Negro', 'M', 2), mcOk('D', 'Negro', 'M', 3)]],
    ['previa', 'roja 2.4, 2 unidades, roja 6.8, 10 unidades', [mcOk('N', 'Rojo', '2-4', 2), mcOk('N', 'Rojo', '6-8', 10)]],
    ['previa', 'caballero negro M 10, 5 blanco M', [mcOk('H', 'Negro', 'M', 10), mcOk('H', 'Blanco', 'M', 5)]],
    ['previa', 'top negro M 5', [mcOk('TP', 'Negro', 'M', 5)]],

    // ---- reglas obligatorias del brief ----
    ['reglas', 'una XL dama roja', [mcOk('D', 'Rojo', 'XL', 1)]],
    ['reglas', 'una ESE dama roja', [mcOk('D', 'Rojo', 'S', 1)]],
    ['reglas', 'una equis ele dama roja', [mcOk('D', 'Rojo', 'XL', 1)]],
    ['reglas', 'una 2XL caballero roja', [mcOk('H', 'Rojo', 'XXL', 1)]],
    ['reglas', 'una referencia roja dama M', [mcRev()]],
    ['reglas', 'roja dama M', [mcRev()]],
    ['reglas', 'una camiseta roja dama M 5', [mcRev()]],
    ['reglas', 'negro caballero XXL 3', [mcOk('H', 'Negro', 'XXL', 3)]],
    ['reglas', 'niño rojo 68', [mcOpc()]],
    ['reglas', 'ROJO ESE DAMA 4', [mcOk('D', 'Rojo', 'S', 4)]],
    ['reglas', 'Rojo Eme Caballero 3', [mcOk('H', 'Rojo', 'M', 3)]],
    ['reglas', 'rojo ELE dama 2', [mcOk('D', 'Rojo', 'L', 2)]],
    ['reglas', 'una EME dama roja', [mcOk('D', 'Rojo', 'M', 1)]],

    // ---- nuevas (no usadas al programar) ----
    ['nueva', 'me regalas 3 negras dama talla M', [mcOk('D', 'Negro', 'M', 3)]],
    ['nueva', 'necesito 12 blancas caballero L y 6 en XL', [mcOk('H', 'Blanco', 'L', 12), mcOk('H', 'Blanco', 'XL', 6)]],
    ['nueva', 'dos rojas niño talla 10', [mcOk('N', 'Rojo', '10-12', 2)]],
    ['nueva', 'cinco azul rey dama ese', [mcOk('D', 'Azul Rey', 'S', 5)]],
    ['nueva', 'una equis ele caballero negra', [mcOk('H', 'Negro', 'XL', 1)]],
    ['nueva', 'tres doble equis ele caballero blanca', [mcOk('H', 'Blanco', 'XXL', 3)]],
    ['nueva', 'vino tinto dama M, cuatro', [mcOk('D', 'Vino Tinto', 'M', 4)]],
    ['nueva', 'azul M 3', [mcRev()]],
    ['nueva', 'negro dama M 2 y 3', [mcRev()]],
    ['nueva', 'negro dama 2 M', [mcOk('D', 'Negro', 'M', 2)]],
    ['nueva', 'rojo niño 6 8 por 4', [mcOk('N', 'Rojo', '6-8', 4)]],
    ['nueva', 'rojo niño seis a ocho, diez unidades', [mcOk('N', 'Rojo', '6-8', 10)]],
    ['nueva', 'blanco caballero eme veinte', [mcOk('H', 'Blanco', 'M', 20)]],
    ['nueva', 'blanco caballero ele 0', [mcRev()]],
    ['nueva', 'verde menta dama L 1000000', [mcRev()]],
    ['nueva', 'marfil dama talla 7 3', [mcRev()]],
    ['nueva', 'beige oscuro caballero XL x 6', [mcOk('H', 'Beige Oscuro', 'XL', 6)]],
    ['nueva', 'palo de rosa dama S 2', [mcOk('D', 'Palo de Rosa', 'S', 2)]],
    ['nueva', 'rosa dama S 2', [mcRev()]],
    ['nueva', 'gris rato caballero M dos, negro caballero M uno', [mcOk('H', 'Gris Rato', 'M', 2), mcOk('H', 'Negro', 'M', 1)]],
    ['nueva', 'la referencia negra dama M, 4 unidades', [mcOk('D', 'Negro', 'M', 4)]],
    ['nueva', '10 negras', [mcRev()]],
    ['nueva', 'negro dama M', [mcRev()]],
    ['nueva', 'negro dama m 3', [mcOk('D', 'Negro', 'M', 3)]],
    ['nueva', 'terracota dama xl 5', [mcOk('D', 'Terracota', 'XL', 5)]],
    ['nueva', 'azul turquesa niño 14 16 3', [mcOk('N', 'Azul Turquesa', '14-16', 3)]],
    ['nueva', 'verde agua marina dama ese tres', [mcOk('D', 'Verde Agua Marina', 'S', 3)]],
    ['nueva', 'curuba dama M 2', [mcOk('D', 'Curuba/Salmon', 'M', 2)]],
    ['nueva', 'una camiseta negra dama M 3 unidades', [mcRev()]],
    ['nueva', 'doscientas blancas caballero M', [mcOk('H', 'Blanco', 'M', 200)]],
    ['nueva', 'dieciséis negras dama S', [mcOk('D', 'Negro', 'S', 16)]],
    ['nueva', 'niño blanco 1416', [mcOpc()]],
    ['nueva', 'rojo dama XXL 2', [mcOk('D', 'Rojo', 'XXL', 2)]],
    ['nueva', 'negro dama ELE 4', [mcOk('D', 'Negro', 'L', 4)]],
    ['nueva', 'dame 4 negras de dama en M y 2 blancas en S', [mcOk('D', 'Negro', 'M', 4), mcOk('D', 'Blanco', 'S', 2)]],
    ['nueva', 'crop top negro 2', [mcOk('TP', 'Negro', 'Única', 2)]],
    ['nueva', 'negro 5 M caballero', [mcOk('H', 'Negro', 'M', 5)]],
    ['nueva', 'roja niño 2 4 6', [mcOk('N', 'Rojo', '2-4', 6)]],
    ['nueva', 'blanca 1 dama L', [mcOk('D', 'Blanco', 'L', 1)]],
    ['nueva', 'quiero una negra dama M y otra blanca', [mcOk('D', 'Negro', 'M', 1), mcRev()]],

    // ---- adversas: buscan aceptar algo incorrecto (expectativas fijadas antes de correr) ----
    ['adversa', 'negro dama M 10 12', [mcRev()]],
    ['adversa', 'rojo 10 niño', [mcOpc()]],
    ['adversa', 'blanco caballero M talla 6', [mcRev()]],
    ['adversa', 'negro dama 3 M 4', [mcRev()]],
    ['adversa', 'azul rey caballero M 2 2', [mcRev()]],
    ['adversa', 'negro dama talla M talla L 3', [mcRev()]],
    ['adversa', 'rojo niño 68 68', [mcRev()]],
    ['adversa', 'negro dama M 2, blanco 3', [mcOk('D', 'Negro', 'M', 2), mcRev()]],
    ['adversa', 'verde dama M 3', [mcRev()]],
    ['adversa', 'negro XXL caballero, una', [mcOk('H', 'Negro', 'XXL', 1)]],
    ['adversa', 'dos negras dama M 3', [mcRev()]],
    ['adversa', 'negro dama M 2 unidades 3 unidades', [mcRev()]],
    ['adversa', 'rojo niño 2 3', [mcOk('N', 'Rojo', '2-4', 3)]],
    ['adversa', 'roja 12 niño', [mcRev()]],
    ['adversa', 'negro 2XL dama 1', [mcOk('D', 'Negro', 'XXL', 1)]],
    ['adversa', 'beige caballero M 4', [mcRev()]],
    ['adversa', 'blanco caballero S 20 negro caballero S 10', [mcOk('H', 'Blanco', 'S', 20), mcOk('H', 'Negro', 'S', 10)]],
];
