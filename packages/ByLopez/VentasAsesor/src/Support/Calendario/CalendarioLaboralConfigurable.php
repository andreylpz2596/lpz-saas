<?php

namespace ByLopez\VentasAsesor\Support\Calendario;

use ByLopez\VentasAsesor\Contracts\CalendarioLaboral;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Calendario por configuracion (ventasasesor.calendario_laboral): dias laborales ISO y
 * lista de festivos. No trae festivos codificados ni dependencias externas: el
 * proyecto no tenia ningun calendario ni helper de dias habiles (03B).
 */
class CalendarioLaboralConfigurable implements CalendarioLaboral
{
    /** Tope de seguridad: una configuracion sin dias laborables no debe colgar el proceso. */
    private const MAX_DIAS_BUSQUEDA = 366;

    public function esLaborable(DateTimeInterface $fecha): bool
    {
        $dia = CarbonImmutable::instance($fecha);

        return in_array($dia->isoWeekday(), $this->diasLaborales(), true)
            && ! in_array($dia->format('Y-m-d'), $this->festivos(), true);
    }

    public function sumarDiasHabiles(DateTimeInterface $desde, int $dias): CarbonImmutable
    {
        $fecha = CarbonImmutable::instance($desde);

        if (! $this->esLaborable($fecha)) {
            $fecha = $this->siguienteLaborable($fecha->startOfDay());
        }

        for ($i = 0; $i < max(0, $dias); $i++) {
            $fecha = $this->siguienteLaborable($fecha->addDay());
        }

        return $fecha;
    }

    private function siguienteLaborable(CarbonImmutable $fecha): CarbonImmutable
    {
        for ($i = 0; $i < self::MAX_DIAS_BUSQUEDA; $i++) {
            if ($this->esLaborable($fecha)) {
                return $fecha;
            }

            $fecha = $fecha->addDay();
        }

        throw new \RuntimeException('El calendario laboral no tiene dias laborables configurados.');
    }

    /** @return list<int> */
    private function diasLaborales(): array
    {
        return array_map('intval', (array) config('ventasasesor.calendario_laboral.dias_laborales', [1, 2, 3, 4, 5, 6]));
    }

    /** @return list<string> */
    private function festivos(): array
    {
        return array_map('strval', (array) config('ventasasesor.calendario_laboral.festivos', []));
    }
}
