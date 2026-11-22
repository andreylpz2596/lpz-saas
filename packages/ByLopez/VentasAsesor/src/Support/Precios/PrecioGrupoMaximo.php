<?php

namespace ByLopez\VentasAsesor\Support\Precios;

/**
 * Regla de negocio ByLopez (Camilo, 2026-11-22): un precio FIJO de grupo no puede ser MAYOR que el precio base de la
 * variante. Bagisto lo permitiria guardar y luego lo ignoraria (un fijo solo cuenta si es menor que el precio vigente),
 * dejando reglas engañosas como un $72.000 sobre un base de $7.500.
 *
 * - Igual al base se permite (no cambia nada; la vista previa lo advierte).
 * - Solo aplica a `fixed`: un descuento % (0–100) nunca supera el base.
 * - Se aplica en «Costos y precios» (EdicionPreciosGrupoService) y en la ficha nativa del producto
 *   (ValidarPrecioGrupoNoMayorQueBase). No corrige filas existentes: solo impide guardar nuevas o cambiadas.
 */
final class PrecioGrupoMaximo
{
    private const TOLERANCIA = 0.00005;

    public static function excede(string $tipo, float $valor, ?float $base): bool
    {
        return $tipo === 'fixed' && $base !== null && $valor > $base + self::TOLERANCIA;
    }
}
