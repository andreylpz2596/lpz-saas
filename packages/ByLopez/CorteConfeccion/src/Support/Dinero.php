<?php

namespace ByLopez\CorteConfeccion\Support;

/**
 * Aritmetica monetaria exacta (cadenas decimales con bcmath, sin flotantes) y lectura de importes en formato colombiano.
 * Todos los importes de la cartera son cadenas con 2 decimales («870000.00»).
 */
class Dinero
{
    public const ESCALA = 2;

    public static function cero(): string
    {
        return '0.00';
    }

    public static function normalizar(string|int|float $valor): string
    {
        return bcadd(self::aCadena($valor), '0', self::ESCALA);
    }

    public static function sumar(string ...$valores): string
    {
        $total = '0';

        foreach ($valores as $valor) {
            $total = bcadd($total, $valor, 4);
        }

        return self::redondear($total);
    }

    public static function restar(string $a, string $b): string
    {
        return self::redondear(bcsub($a, $b, 4));
    }

    /** tarifa (hasta 4 decimales) x cantidad entera, redondeada a centavos (mitad hacia arriba). */
    public static function por(string|float|null $tarifa, int $cantidad): string
    {
        if ($tarifa === null) {
            return self::cero();
        }

        return self::redondear(bcmul(self::aCadena($tarifa), (string) $cantidad, 6));
    }

    public static function comparar(string $a, string $b): int
    {
        return bccomp($a, $b, self::ESCALA);
    }

    public static function menor(string $a, string $b): string
    {
        return self::comparar($a, $b) <= 0 ? $a : $b;
    }

    public static function mayor(string $a, string $b): string
    {
        return self::comparar($a, $b) >= 0 ? $a : $b;
    }

    public static function maxCero(string $valor): string
    {
        return self::mayor($valor, self::cero());
    }

    public static function esPositivo(string $valor): bool
    {
        return self::comparar($valor, self::cero()) > 0;
    }

    public static function esCero(string $valor): bool
    {
        return self::comparar($valor, self::cero()) === 0;
    }

    public static function negar(string $valor): string
    {
        return self::redondear(bcmul($valor, '-1', 4));
    }

    public static function absoluto(string $valor): string
    {
        return self::comparar($valor, self::cero()) < 0 ? self::negar($valor) : $valor;
    }

    /** «$ 1.015.000» (pesos sin decimales) o «$ 1.015.000,50» si hay centavos. */
    public static function formatear(string $valor): string
    {
        $negativo = self::comparar($valor, self::cero()) < 0;
        $abs = self::absoluto($valor);
        [$entero, $decimales] = explode('.', $abs) + [1 => '00'];
        $miles = number_format((int) $entero, 0, ',', '.');
        $texto = '$ '.$miles.($decimales !== '00' ? ','.$decimales : '');

        return ($negativo ? '− ' : '').$texto;
    }

    /**
     * Lee un importe escrito por una persona o leido de un comprobante: «$ 1.234.567,89», «1.234.567», «1,234,567.89»,
     * «20000». Devuelve null si no es un numero claro (nunca adivina).
     */
    public static function parsear(string|int|float|null $entrada): ?string
    {
        if ($entrada === null) {
            return null;
        }

        if (is_int($entrada) || is_float($entrada)) {
            return self::normalizar($entrada);
        }

        $texto = trim(str_replace(['$', 'COP', 'cop', ' ', "\u{00A0}"], '', $entrada));
        $negativo = str_starts_with($texto, '-') || str_starts_with($texto, '−');
        $texto = ltrim($texto, '-−+');

        if ($texto === '' || ! preg_match('/^[0-9.,]+$/', $texto)) {
            return null;
        }

        $ultimoPunto = strrpos($texto, '.');
        $ultimaComa = strrpos($texto, ',');

        if ($ultimoPunto !== false && $ultimaComa !== false) {
            $decimal = $ultimaComa > $ultimoPunto ? ',' : '.';
        } elseif ($ultimaComa !== false) {
            $decimal = (substr_count($texto, ',') === 1 && strlen($texto) - $ultimaComa - 1 <= 2) ? ',' : null;
        } elseif ($ultimoPunto !== false) {
            $decimal = (substr_count($texto, '.') === 1 && strlen($texto) - $ultimoPunto - 1 <= 2) ? '.' : null;
        } else {
            $decimal = null;
        }

        if ($decimal !== null) {
            $partes = explode($decimal, $texto);
            $decimales = array_pop($partes);
            $entero = preg_replace('/[.,]/', '', implode('', $partes));
            $numero = $entero.'.'.$decimales;
        } else {
            $numero = preg_replace('/[.,]/', '', $texto);
        }

        if (! preg_match('/^[0-9]+(\.[0-9]+)?$/', $numero)) {
            return null;
        }

        $resultado = bcadd($numero, '0', self::ESCALA);

        return $negativo ? self::negar($resultado) : $resultado;
    }

    protected static function redondear(string $valor): string
    {
        // Mitad hacia arriba en valor absoluto, a 2 decimales.
        $negativo = bccomp($valor, '0', 6) < 0;
        $abs = ltrim($valor, '-');
        $res = bcadd($abs, '0.005', 6);
        $res = bcadd(bcsub($res, '0', 2), '0', 2); // trunca a 2 decimales

        return ($negativo && bccomp($res, '0', 2) !== 0 ? '-' : '').$res;
    }

    protected static function aCadena(string|int|float $valor): string
    {
        if (is_float($valor)) {
            return rtrim(rtrim(number_format($valor, 6, '.', ''), '0'), '.') ?: '0';
        }

        return (string) $valor;
    }
}
