<?php

namespace ByLopez\Cargues\Services\Lectura;

/** Resultado de revisar un archivo ANTES de leerlo entero. */
final class InspeccionArchivo
{
    public function __construct(
        public readonly string $formato,
        public readonly array $hojas = [],
        public readonly ?string $hojaSeleccionada = null,
        public readonly ?int $celdas = null,
        public readonly ?int $filas = null,
        public readonly ?string $dimensionDeclarada = null,
        public readonly ?string $delimitador = null,
        public readonly ?string $codificacion = null,
        public readonly array $advertencias = [],
        public readonly ?int $memoriaEstimada = null,
    ) {}

    public function aArray(): array
    {
        return get_object_vars($this);
    }
}
