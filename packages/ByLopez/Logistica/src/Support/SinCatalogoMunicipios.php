<?php

namespace ByLopez\Logistica\Support;

use ByLopez\Logistica\Contracts\BuscaMunicipios;

/** Implementacion por defecto: sin catalogo geografico no se puede habilitar ninguna ciudad nueva. */
class SinCatalogoMunicipios implements BuscaMunicipios
{
    public function buscar(string $texto, int $limite = 15): array
    {
        return [];
    }

    public function resolver(string $municipioCodigo): ?array
    {
        return null;
    }
}
