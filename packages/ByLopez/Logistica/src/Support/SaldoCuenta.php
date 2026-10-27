<?php

namespace ByLopez\Logistica\Support;

/**
 * Traduce el saldo firmado de una cuenta corriente a LENGUAJE HUMANO (Bodega nunca interpreta signos):
 *   saldo > 0 → «Jeison debe entregar a ByLopez $ 41.000»   (sentido `debe_entregar`)
 *   saldo < 0 → «ByLopez debe pagar a Jeison $ 20.000»     (sentido `bylopez_paga`)
 *   saldo = 0 → «Cuenta al día»                             (sentido `al_dia`)
 */
class SaldoCuenta
{
    public const DEBE_ENTREGAR = 'debe_entregar';

    public const BYLOPEZ_PAGA = 'bylopez_paga';

    public const AL_DIA = 'al_dia';

    public static function sentido(float $saldo): string
    {
        return match (true) {
            $saldo > 0.004 => self::DEBE_ENTREGAR,
            $saldo < -0.004 => self::BYLOPEZ_PAGA,
            default => self::AL_DIA,
        };
    }

    /** @return array{valor: float, sentido: string, texto: string, situacion: string, valor_texto: string} */
    public static function describir(float $saldo, string $nombre): array
    {
        $sentido = self::sentido($saldo);
        $valor = round(abs($saldo), 2);
        $monto = Moneda::formatear($valor);

        return [
            'valor' => $sentido === self::AL_DIA ? 0.0 : round($saldo, 2),
            'sentido' => $sentido,
            'valor_texto' => $monto,
            'texto' => (string) trans('logistica::app.cuentas.sentido.'.$sentido.'-texto', ['nombre' => $nombre, 'valor' => $monto]),
            'situacion' => (string) trans('logistica::app.cuentas.sentido.'.$sentido, ['nombre' => $nombre]),
        ];
    }
}
