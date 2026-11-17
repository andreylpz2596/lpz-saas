<?php

namespace ByLopez\VentasAsesor\Support\Comisiones;

use Carbon\CarbonImmutable;

/**
 * Un período de comisión de UNA asesora: [inicio, fin) en America/Bogota, a medianoche.
 * `clave` es el mes (Y-m) en que EMPIEZA el período: es lo que se guarda en `mes_comercial` y en los libros del umbral.
 * Con día de inicio 1 coincide con el mes calendario (compatibilidad total con lo ya guardado).
 */
final class PeriodoComision
{
    public function __construct(
        public readonly string $clave,
        public readonly CarbonImmutable $inicio,
        public readonly CarbonImmutable $fin,
    ) {}

    /** Último día que pertenece al período (fin es exclusivo). */
    public function hasta(): CarbonImmutable
    {
        return $this->fin->subDay();
    }

    public function esMesCalendario(): bool
    {
        return $this->inicio->day === 1 && $this->fin->equalTo($this->inicio->addMonthNoOverflow());
    }

    /** «noviembre 2026» para el mes calendario; «14 nov 2026 – 13 dic 2026» para cualquier otro período. */
    public function etiqueta(?string $idioma = null): string
    {
        $idioma ??= app()->getLocale();

        if ($this->esMesCalendario()) {
            return $this->inicio->locale($idioma)->isoFormat('MMMM YYYY');
        }

        return $this->inicio->locale($idioma)->isoFormat('D MMM YYYY').' – '.$this->hasta()->locale($idioma)->isoFormat('D MMM YYYY');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function rango(): array
    {
        return [$this->inicio, $this->fin];
    }

    /** @return array{clave: string, desde: string, hasta: string, etiqueta: string, mes_calendario: bool} */
    public function aArreglo(): array
    {
        return [
            'clave' => $this->clave,
            'desde' => $this->inicio->toDateString(),
            'hasta' => $this->hasta()->toDateString(),
            'etiqueta' => $this->etiqueta(),
            'mes_calendario' => $this->esMesCalendario(),
        ];
    }
}
