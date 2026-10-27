<?php

namespace ByLopez\Cargues\Extensiones\ClientesHistoricos;

use Illuminate\Support\Str;

/**
 * Nombres de personas para «Clientes historicos». NO es una calificacion: solo comparar sin ruido y repartir un nombre completo en
 * nombre y apellido (customers los exige separados). El reparto es una CONVENCION documentada (docs/14 §3), no un dato verificado.
 */
final class Nombres
{
    /** @return list<string> palabras sin espacios sobrantes, tal como llegaron */
    public static function palabras(string $nombre): array
    {
        return preg_split('/\s+/u', trim($nombre), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Clave de comparacion: SOLO normaliza mayusculas, tildes y espacios (sin reordenar ni quitar palabras ni signos).
     * «  juan   PÉREZ » = «Juan Perez»; «Juan Perez» ≠ «Juan Carlos Perez» ≠ «Perez Juan».
     */
    public static function clave(string $nombre): string
    {
        return Str::lower(Str::ascii(implode(' ', self::palabras($nombre))));
    }

    /**
     * Convencion (SOLO una PROPUESTA editable, nunca una identificacion segura): 2 palabras → 1 + 1; 3 → 1 nombre + 2 apellidos;
     * 4 o mas → 2 nombres + el resto. Una sola palabra NO se rechaza: [palabra, null] (el apellido queda pendiente de completar,
     * no se inventa). Sin palabras: null.
     *
     * @return array{0:string, 1:?string}|null [nombre, apellido|null]
     */
    public static function partir(string $nombre): ?array
    {
        $w = self::palabras($nombre);
        $n = count($w);

        if ($n === 0) {
            return null;
        }

        if ($n === 1) {
            return [$w[0], null];
        }

        $corte = $n >= 4 ? 2 : 1;

        return [implode(' ', array_slice($w, 0, $corte)), implode(' ', array_slice($w, $corte))];
    }

    /**
     * Dos nombres son «el mismo» SOLO si son iguales tras normalizar mayusculas, tildes y espacios. Un nombre con palabras anadidas u omitidas
     * («Juan Perez» / «Juan Carlos Perez»), en otro orden o con otra ortografia NO coincide: queda para revision, nunca se une por parecido.
     */
    public static function compatibles(string $a, string $b): bool
    {
        $x = self::clave($a);

        return $x !== '' && $x === self::clave($b);
    }
}
