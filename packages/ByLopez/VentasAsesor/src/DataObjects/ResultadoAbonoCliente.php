<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Resultado de registrar un abono multipedido (PAG-D). `duplicado = true` si la misma clave con la misma solicitud
 * ya lo habia registrado: se devuelve el existente y no se escribe nada.
 */
final class ResultadoAbonoCliente
{
    /**
     * @param  list<array{order_id: int, increment_id: string, monto: float}>  $aplicaciones
     */
    public function __construct(
        public readonly int $pagoId,
        public readonly string $estado,
        public readonly float $monto,
        public readonly bool $duplicado,
        public readonly array $aplicaciones,
    ) {}

    public function toArray(): array
    {
        return [
            'pago_id' => $this->pagoId,
            'estado' => $this->estado,
            'monto' => $this->monto,
            'duplicado' => $this->duplicado,
            'pedidos_aplicados' => count($this->aplicaciones),
            'aplicaciones' => $this->aplicaciones,
        ];
    }
}
