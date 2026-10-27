<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Municipio DIVIPOLA resuelto por el BACKEND a partir de su codigo (D-58). Es la unica fuente de
 * `city` / `state` / `country` de las direcciones colombianas nuevas: lo que mande el navegador
 * en esos campos se ignora.
 */
final class UbicacionResuelta
{
    public function __construct(
        public readonly string $municipioCodigo,
        public readonly string $municipio,
        public readonly string $departamentoCodigo,
        public readonly string $departamento,
        /** country_states.code de Bagisto: lo que Bagisto guarda en addresses.state. */
        public readonly string $stateCode,
        public readonly string $pais = 'CO',
    ) {}

    /** «Boyacá — Pesca»: departamento primero, municipio despues (formato aprobado por Camilo). */
    public function etiqueta(): string
    {
        return $this->departamento.' — '.$this->municipio;
    }
}
