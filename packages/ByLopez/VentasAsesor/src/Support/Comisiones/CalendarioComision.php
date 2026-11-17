<?php

namespace ByLopez\VentasAsesor\Support\Comisiones;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Calendario de períodos de comisión de UNA asesora (cálculo puro: no lee la base).
 *
 * Reglas (aprobación pendiente del responsable, ver docs/31_PERIODO_COMISION_POR_ASESORA.md del paquete):
 *  - El período va del día de inicio de un mes al día anterior del mes siguiente (14 → del 14 al 13).
 *  - Día 29, 30 o 31 en un mes que no lo tiene: el período empieza el ÚLTIMO día de ese mes (31 → 28/29 feb, 30 abr…).
 *  - Sin día de inicio = día 1 = mes calendario.
 *  - Clave del período = mes (Y-m) en que empieza. Es única por asesora y ordenada en el tiempo.
 *  - Cambio de día con comisiones ya calculadas: los períodos vividos NO se reescriben. El período en curso se ALARGA hasta
 *    el primer inicio del día nuevo que no sea anterior a su fin natural; desde ahí rige el día nuevo. Ese tramo alargado
 *    nunca se parte (si se partiera, dos períodos podrían empezar el mismo mes y compartir clave).
 *
 * Versiones: lista de ['dia' => 1..31, 'desde' => 'Y-m-d' | null], ordenada; `desde` null = desde siempre. `desde` de una
 * versión es siempre un inicio de período de su propio día (lo garantiza CicloComisionService al guardarla).
 */
final class CalendarioComision
{
    /** @var list<array{dia: int, desde: ?CarbonImmutable}> */
    private array $versiones;

    /** @param  list<array{dia: int|string, desde: ?string}>  $versiones */
    public function __construct(array $versiones = [])
    {
        $normalizadas = array_map(fn (array $v) => [
            'dia' => max(1, min(31, (int) $v['dia'])),
            'desde' => $v['desde'] === null ? null : self::fecha((string) $v['desde']),
        ], $versiones);

        usort($normalizadas, fn ($a, $b) => ($a['desde']?->getTimestamp() ?? PHP_INT_MIN) <=> ($b['desde']?->getTimestamp() ?? PHP_INT_MIN));

        if ($normalizadas === [] || $normalizadas[0]['desde'] !== null) {
            array_unshift($normalizadas, ['dia' => 1, 'desde' => null]);
        }

        $this->versiones = array_values($normalizadas);
    }

    public static function fecha(string $ymd): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10), MesComercial::zona());
    }

    /** Inicio del período del día `$dia` en el mes de `$mes` (con el ajuste al último día del mes). */
    public static function inicioEnMes(int $dia, CarbonInterface $mes): CarbonImmutable
    {
        $primero = CarbonImmutable::create($mes->year, $mes->month, 1, 0, 0, 0, MesComercial::zona());

        return $primero->setDay(min($dia, $primero->daysInMonth));
    }

    /** Primer inicio del día `$dia` que no es anterior a `$desde`. */
    public static function primerInicioDesde(int $dia, CarbonImmutable $desde): CarbonImmutable
    {
        $b = self::inicioEnMes($dia, $desde);

        return $b->lessThan($desde) ? self::inicioEnMes($dia, $desde->startOfMonth()->addMonth()) : $b;
    }

    /** Día de inicio vigente en ese instante. */
    public function diaEn(CarbonInterface|string $instante): int
    {
        return $this->versiones[$this->indiceEn($this->dia($instante))]['dia'];
    }

    /** Hay un cambio de día ya guardado que empieza después de `$instante`. @return ?array{dia: int, desde: CarbonImmutable} */
    public function cambioProgramado(CarbonInterface|string $instante): ?array
    {
        $siguiente = $this->versiones[$this->indiceEn($this->dia($instante)) + 1] ?? null;

        return $siguiente === null ? null : ['dia' => $siguiente['dia'], 'desde' => $siguiente['desde']];
    }

    public function clave(CarbonInterface|string $instante): string
    {
        return $this->periodoEn($instante)->clave;
    }

    public function periodoEn(CarbonInterface|string $instante): PeriodoComision
    {
        $d = $this->dia($instante);
        $i = $this->indiceEn($d);
        $dia = $this->versiones[$i]['dia'];
        $primera = $this->versiones[$i]['desde'];
        $limite = $this->versiones[$i + 1]['desde'] ?? null;

        $s = self::inicioEnMes($dia, $d);
        if ($s->greaterThan($d)) {
            $s = self::inicioEnMes($dia, $d->startOfMonth()->subMonth());
        }
        $n = $this->siguiente($dia, $s);

        if ($limite !== null) {
            if ($s->lessThan($limite) && $limite->lessThan($n) && ! ($primera !== null && $s->equalTo($primera))) {
                // Tramo final de un período alargado por un cambio de día: pertenece al período anterior.
                $s = self::inicioEnMes($dia, $s->startOfMonth()->subMonth());
                $n = $limite;
            } elseif ($n->lessThan($limite) && $limite->lessThan($this->siguiente($dia, $n))) {
                // Período en curso cuando se cambió el día: se alarga hasta el primer inicio del día nuevo.
                $n = $limite;
            } elseif ($n->greaterThan($limite)) {
                $n = $limite;
            }
        }

        return new PeriodoComision($s->format('Y-m'), $s, $n);
    }

    /** Período cuya clave es `$clave` (el que EMPIEZA en ese mes), o null si en ese mes no empieza ninguno (mes absorbido por un cambio). */
    public function rango(string $clave): ?PeriodoComision
    {
        if (! MesComercial::valido($clave)) {
            return null;
        }

        $mes = CarbonImmutable::createFromFormat('!Y-m-d', $clave.'-01', MesComercial::zona());

        foreach ($this->versiones as $i => $v) {
            $s = self::inicioEnMes($v['dia'], $mes);
            $limite = $this->versiones[$i + 1]['desde'] ?? null;

            if (($v['desde'] === null || $s->greaterThanOrEqualTo($v['desde'])) && ($limite === null || $s->lessThan($limite))) {
                $p = $this->periodoEn($s);

                if ($p->clave === $clave) {
                    return $p;
                }
            }
        }

        return null;
    }

    public function anterior(PeriodoComision $p): PeriodoComision
    {
        return $this->periodoEn($p->inicio->subDay());
    }

    /**
     * Sugerencia para una liquidación: el último período YA TERMINADO de la asesora (o el actual si aún no termina ninguno).
     *
     * @return array{desde: string, hasta: string, etiqueta: string}
     */
    public function sugerenciaLiquidacion(CarbonInterface|string $hoy): array
    {
        $p = $this->anterior($this->periodoEn($hoy));

        return ['desde' => $p->inicio->toDateString(), 'hasta' => $p->hasta()->toDateString(), 'etiqueta' => $p->etiqueta()];
    }

    private function siguiente(int $dia, CarbonImmutable $inicio): CarbonImmutable
    {
        return self::inicioEnMes($dia, $inicio->startOfMonth()->addMonth());
    }

    private function dia(CarbonInterface|string $instante): CarbonImmutable
    {
        return MesComercial::instante($instante)->startOfDay();
    }

    private function indiceEn(CarbonImmutable $d): int
    {
        $i = 0;
        foreach ($this->versiones as $k => $v) {
            if ($v['desde'] === null || $v['desde']->lessThanOrEqualTo($d)) {
                $i = $k;
            }
        }

        return $i;
    }
}
