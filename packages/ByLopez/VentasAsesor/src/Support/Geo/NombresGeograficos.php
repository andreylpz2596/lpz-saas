<?php

namespace ByLopez\VentasAsesor\Support\Geo;

use Illuminate\Support\Str;

/**
 * Nombres geograficos de DIVIPOLA (D-58).
 *
 * DANE publica los nombres en MAYUSCULAS («BOYACÁ», «PESCA»). Se guardan en formato de
 * presentacion («Boyacá», «Pesca») con una conversion determinista y se busca sobre una forma
 * normalizada («boyaca»): minusculas, sin tildes y sin signos, para que «Chí», «chi» y «CHÍ»
 * encuentren Chía. Una sola implementacion para el importador y para la busqueda: si difirieran,
 * el catalogo no se encontraria a si mismo.
 */
final class NombresGeograficos
{
    /** Particulas que van en minuscula salvo al inicio del nombre («La Guajira», «Valle del Cauca»). */
    private const PARTICULAS = ['de', 'del', 'la', 'las', 'los', 'el', 'y'];

    /** «Boyacá» → «boyaca». Solo letras, digitos y espacios simples; es lo que se compara con LIKE. */
    public static function busqueda(string $texto): string
    {
        $normalizado = Str::lower(Str::ascii($texto));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $normalizado));
    }

    /** «ARCHIPIÉLAGO DE SAN ANDRÉS, PROVIDENCIA Y SANTA CATALINA» → «Archipiélago de San Andrés, Providencia y Santa Catalina». */
    public static function presentacion(string $oficial): string
    {
        $palabras = preg_split('/\s+/u', trim($oficial), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $salida = [];

        foreach ($palabras as $i => $palabra) {
            $coma = str_ends_with($palabra, ',') ? ',' : '';
            $base = rtrim($palabra, ',');

            if ($base === '-' || preg_match('/^(\p{Lu}\.)+$/u', $base) === 1) {
                $salida[] = $base.$coma;           // separador « - » y siglas («D.C.») tal cual

                continue;
            }

            $minuscula = mb_strtolower($base);

            $salida[] = ($i > 0 && in_array($minuscula, self::PARTICULAS, true) ? $minuscula : mb_strtoupper(mb_substr($minuscula, 0, 1)).mb_substr($minuscula, 1)).$coma;
        }

        return implode(' ', $salida);
    }
}
