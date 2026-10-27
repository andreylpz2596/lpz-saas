<?php

namespace ByLopez\Logistica\Support;

/**
 * Formato monetario de LECTURA de ByLopez en el servidor: «$ 420.000», «$ 1.250.000», «$ 7.300,50». Mismas reglas que
 * `ByLopezMoneda.formatear()` (parcial `corteconfeccion::admin.partials.moneda`), que se sigue usando en el navegador con
 * este mismo prefijo. Solo presentacion: el payload y la base conservan el valor numerico real.
 */
class Moneda
{
    /**
     * Simbolo de la moneda base. Si la moneda no tiene simbolo configurado, Bagisto devuelve el CODIGO («COP»): para COP
     * se muestra «$», que es su simbolo real (no se toca la configuracion de monedas).
     */
    public static function prefijo(): string
    {
        $codigo = (string) core()->getBaseCurrencyCode();
        $simbolo = trim((string) core()->currencySymbol($codigo));

        return ($simbolo === '' || $simbolo === $codigo) ? '$' : $simbolo;
    }

    public static function formatear(float|int|string|null $valor, bool $conSigno = false): string
    {
        $numero = round((float) $valor, 2);
        $centavos = (int) round(abs($numero) * 100) % 100;
        $texto = number_format(abs($numero), $centavos === 0 ? 0 : 2, ',', '.');
        $signo = $conSigno ? ($numero > 0 ? '+' : ($numero < 0 ? '-' : '')) : '';

        return $signo.self::prefijo().' '.$texto;
    }
}
