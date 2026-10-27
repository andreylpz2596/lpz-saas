<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Resultado de registrar un pago (PAG-C). `duplicado = true` cuando la misma clave de idempotencia con la misma
 * solicitud ya habia registrado el pago: se devuelve el existente y no se escribe nada.
 */
final class ResultadoRegistroPago
{
    public function __construct(
        public readonly int $pagoId,
        public readonly string $estado,
        public readonly float $monto,
        public readonly bool $duplicado,
        public readonly SaldoPedido $saldo,
    ) {}

    public function toArray(): array
    {
        return [
            'pago_id' => $this->pagoId,
            'estado' => $this->estado,
            'monto' => $this->monto,
            'duplicado' => $this->duplicado,
            'saldo' => $this->saldo->toArray(),
        ];
    }
}
