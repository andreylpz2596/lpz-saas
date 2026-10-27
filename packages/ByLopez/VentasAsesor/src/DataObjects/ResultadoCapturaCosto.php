<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Resultado de capturar los snapshots de costo de un pedido (COM-01). Solo conteos: el detalle vive en
 * `bylopez_va_costos_venta`.
 */
final class ResultadoCapturaCosto
{
    public function __construct(
        public readonly int $orderId,
        /** Filas nuevas creadas en esta llamada. */
        public readonly int $creados,
        /** Lineas que ya tenian snapshot (reintento): no se tocan. */
        public readonly int $existentes,
        /** De las creadas, cuantas quedaron con `sin_costo`. */
        public readonly int $sinCosto,
    ) {}

    public function esNoOp(): bool
    {
        return $this->creados === 0;
    }
}
