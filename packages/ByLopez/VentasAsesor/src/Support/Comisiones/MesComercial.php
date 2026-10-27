<?php

namespace ByLopez\VentasAsesor\Support\Comisiones;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Mes comercial de una venta: mes calendario en America/Bogota del INSTANTE REAL de confirmacion (`orders.created_at`).
 * Es independiente de la fecha de cobro, habilitacion o pago: un cobro en otro mes no mueve la venta de mes.
 */
final class MesComercial
{
    public static function zona(): string
    {
        return (string) config('ventasasesor.comisiones.zona_horaria', 'America/Bogota');
    }

    public static function de(CarbonInterface|string $instante): string
    {
        return self::instante($instante)->format('Y-m');
    }

    public static function instante(CarbonInterface|string $instante): CarbonImmutable
    {
        // `orders.created_at` se guarda en la zona de la aplicacion (APP_TIMEZONE); se interpreta en esa zona y se lleva a Bogota.
        $c = $instante instanceof CarbonInterface
            ? CarbonImmutable::instance($instante)
            : CarbonImmutable::parse($instante, (string) config('app.timezone'));

        return $c->setTimezone(self::zona());
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} [inicio, fin) del mes en Bogota */
    public static function rango(string $mes): array
    {
        $inicio = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $mes.'-01 00:00:00', self::zona());

        return [$inicio, $inicio->addMonth()];
    }

    public static function valido(?string $mes): bool
    {
        return is_string($mes) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1;
    }
}
