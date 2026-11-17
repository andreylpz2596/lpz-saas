<?php

namespace ByLopez\VentasAsesor\Support\Comisiones;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Mes comercial de una venta: se decide en America/Bogota con el INSTANTE REAL de confirmacion (`orders.created_at`).
 * Es independiente de la fecha de cobro, habilitacion o pago: un cobro en otro mes no mueve la venta de mes.
 *
 * Desde 2026-11-16 cada asesora tiene su PERÍODO de comisión (día de ingreso, ver CalendarioComision): para las comisiones de
 * una asesora la clave `Y-m` es el mes en que EMPIEZA su período (`calendario()`). `de()` y `rango()` son el mes calendario.
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

    /**
     * Calendario de períodos de comisión de la asesora (`asesor_admin_id` = admins.id). Sin día de ingreso guardado es el mes
     * calendario: `calendario($id)->clave(...)` coincide con `de(...)`. Toda clave de comisión de UNA asesora (líneas, umbral,
     * avance, simulador) sale de aquí; `de()`/`rango()` quedan para lo que es de la empresa (panel por mes calendario).
     */
    public static function calendario(int $asesorAdminId): CalendarioComision
    {
        return new CalendarioComision(DB::table('bylopez_va_comision_ciclos')->where('asesor_admin_id', $asesorAdminId)
            ->orderByRaw('vigente_desde IS NULL DESC')->orderBy('vigente_desde')->orderBy('id')
            ->get(['dia_inicio', 'vigente_desde'])
            ->map(fn ($f) => ['dia' => (int) $f->dia_inicio, 'desde' => $f->vigente_desde])->all());
    }

    /** Período (con su etiqueta) de la clave `$mes` para la asesora; si en ese mes no empieza ninguno, el mes calendario. */
    public static function periodo(int $asesorAdminId, string $mes): PeriodoComision
    {
        if ($p = self::calendario($asesorAdminId)->rango($mes)) {
            return $p;
        }

        [$inicio, $fin] = self::rango($mes);

        return new PeriodoComision($mes, $inicio, $fin);
    }

    public static function valido(?string $mes): bool
    {
        return is_string($mes) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1;
    }
}
