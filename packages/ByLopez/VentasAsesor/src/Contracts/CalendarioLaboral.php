<?php

namespace ByLopez\VentasAsesor\Contracts;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Calendario laboral del negocio (03B). La implementacion actual lee dias laborales y
 * festivos de la configuracion; una fuente futura (tabla, API de festivos) se enlaza
 * por binding sin cambiar a los consumidores.
 */
interface CalendarioLaboral
{
    public function esLaborable(DateTimeInterface $fecha): bool;

    /**
     * Suma $dias habiles completos. Desde un dia laborable conserva la hora (con lunes a
     * sabado, D-35: viernes 10:00 + 1 → sabado 10:00; sabado 10:00 + 1 → lunes 10:00). Desde
     * un dia no laborable, cuenta a partir del inicio del siguiente laborable.
     */
    public function sumarDiasHabiles(DateTimeInterface $desde, int $dias): CarbonImmutable;
}
