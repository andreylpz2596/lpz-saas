<?php

namespace ByLopez\Logistica\Contracts;

/**
 * Catalogo de municipios (DIVIPOLA) para elegir destinos locales. Logistica no es dueno del catalogo geografico: trae una
 * implementacion que no encuentra nada y VentasAsesor, que si lo es, la reemplaza. Solo lectura.
 *
 * @phpstan-type Municipio array{municipio_codigo: string, municipio: string, departamento: string, etiqueta: string}
 */
interface BuscaMunicipios
{
    /**
     * Autocompletado (sin tildes ni mayusculas); maximo `$limite` resultados, nunca el catalogo completo.
     *
     * @return list<array{municipio_codigo: string, municipio: string, departamento: string, etiqueta: string}>
     */
    public function buscar(string $texto, int $limite = 15): array;

    /** Municipio activo por codigo DIVIPOLA de 5 digitos; null si no existe. El BACKEND es la autoridad. */
    public function resolver(string $municipioCodigo): ?array;
}
