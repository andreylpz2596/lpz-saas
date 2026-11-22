<?php

namespace ByLopez\VentasAsesor\Support\Logistica;

use ByLopez\Logistica\Contracts\BuscaMunicipios;
use ByLopez\VentasAsesor\Services\Geo\CatalogoUbicaciones;

/** Catalogo DIVIPOLA local (D-58) para elegir destinos locales en Logistica. Solo lectura: reutiliza CatalogoUbicaciones. */
class MunicipiosDesdeCatalogo implements BuscaMunicipios
{
    public function __construct(protected CatalogoUbicaciones $catalogo) {}

    public function buscar(string $texto, int $limite = 15): array
    {
        return array_map(fn (array $u) => [
            'municipio_codigo' => $u['municipio_codigo'], 'municipio' => $u['municipio'], 'departamento' => $u['departamento'],
            'etiqueta' => $u['municipio'].' — '.$u['departamento'],
        ], $this->catalogo->buscar($texto, $limite));
    }

    public function resolver(string $municipioCodigo): ?array
    {
        $u = $this->catalogo->resolver($municipioCodigo);

        return $u === null ? null : [
            'municipio_codigo' => $u->municipioCodigo, 'municipio' => $u->municipio, 'departamento' => $u->departamento,
            'etiqueta' => $u->municipio.' — '.$u->departamento,
        ];
    }
}
