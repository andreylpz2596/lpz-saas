<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Una variante dentro de MatrizVariantes: la variante REAL (stock y precio de Bagisto) mas su
 * posicion de talla y la senal informativa de ventas simultaneas (Doc. 05 §40).
 */
final class CeldaMatriz
{
    public function __construct(
        public readonly VarianteVenta $variante,
        public readonly int $ordenTalla,
        public readonly ?VentasSimultaneasVariante $senal,
    ) {}

    public function toArray(): array
    {
        return [
            'variante' => $this->variante->toArray(),
            'orden_talla' => $this->ordenTalla,
            'senal' => $this->senal?->toArray(),
        ];
    }
}
