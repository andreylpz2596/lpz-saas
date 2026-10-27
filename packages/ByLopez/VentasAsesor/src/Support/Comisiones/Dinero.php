<?php

namespace ByLopez\VentasAsesor\Support\Comisiones;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Aritmetica decimal exacta para rentabilidad y comisiones (sin `float`, Doc. 21 R-10). Escala interna 4; los importes
 * que se guardan o se pagan se redondean HALF_UP a 2 por linea y los totales suman lineas ya redondeadas.
 */
final class Dinero
{
    public const ESCALA = 4;

    public static function de(mixed $valor): BigDecimal
    {
        if ($valor instanceof BigDecimal) {
            return $valor;
        }

        if ($valor === null || $valor === '') {
            return BigDecimal::zero();
        }

        // Los decimales de MySQL llegan como string; un float del motor de Bagisto se pasa por su representacion corta.
        return BigDecimal::of(is_float($valor) ? self::floatATexto($valor) : (string) $valor);
    }

    public static function o(mixed $valor): ?BigDecimal
    {
        return $valor === null || $valor === '' ? null : self::de($valor);
    }

    public static function escala(BigDecimal $v, int $escala = self::ESCALA): BigDecimal
    {
        return $v->toScale($escala, RoundingMode::HALF_UP);
    }

    public static function importe(BigDecimal $v): BigDecimal
    {
        return $v->toScale(2, RoundingMode::HALF_UP);
    }

    public static function texto(?BigDecimal $v, int $escala = 2): ?string
    {
        return $v === null ? null : (string) $v->toScale($escala, RoundingMode::HALF_UP);
    }

    public static function dividir(BigDecimal $a, BigDecimal $b, int $escala = self::ESCALA): BigDecimal
    {
        return $a->dividedBy($b, $escala, RoundingMode::HALF_UP);
    }

    /** Redondeo comercial SIEMPRE hacia arriba al escalon (p. ej. 100); sin escalon, hacia arriba al centavo. */
    public static function redondearArriba(BigDecimal $v, ?int $escalon): BigDecimal
    {
        if ($escalon === null || $escalon <= 1) {
            return $v->toScale(2, RoundingMode::CEILING);
        }

        $e = BigDecimal::of($escalon);

        return $v->dividedBy($e, 0, RoundingMode::CEILING)->multipliedBy($e)->toScale(2);
    }

    public static function maximo(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->isGreaterThan($b) ? $a : $b;
    }

    /** Fraccion de un porcentaje en puntos (20.0000 → 0.2). */
    public static function fraccion(mixed $porcentaje): BigDecimal
    {
        return self::de($porcentaje)->dividedBy(100, 8, RoundingMode::HALF_UP);
    }

    private static function floatATexto(float $f): string
    {
        $t = rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');

        return $t === '' || $t === '-' ? '0' : $t;
    }
}
