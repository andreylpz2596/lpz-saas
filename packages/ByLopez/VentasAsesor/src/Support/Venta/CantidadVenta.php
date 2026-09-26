<?php

namespace ByLopez\VentasAsesor\Support\Venta;

use ByLopez\VentasAsesor\Exceptions\VarianteInvalidaException;

/**
 * Cantidad de venta (solicitudes y lineas, 03C): solo enteros >= 1 o su texto decimal
 * exacto ("4"). Ni 0, ni negativos, ni decimales (tampoco 2.0), ni textos ambiguos.
 */
final class CantidadVenta
{
    /** Tope tecnico (INT UNSIGNED en cart_items y solicitudes); no es una regla comercial. */
    public const MAXIMA = 1_000_000;

    public static function normalizar(mixed $valor): int
    {
        if (is_string($valor) && preg_match('/^[1-9][0-9]{0,6}$/', $valor)) {
            $valor = (int) $valor;
        }

        if (! is_int($valor) || $valor < 1 || $valor > self::MAXIMA) {
            throw new VarianteInvalidaException(VarianteInvalidaException::CANTIDAD_INVALIDA);
        }

        return $valor;
    }
}
