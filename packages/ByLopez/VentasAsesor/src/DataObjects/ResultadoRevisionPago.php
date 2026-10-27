<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Resultado de confirmar o rechazar un pago (PAG-E). `yaProcesado = true` cuando el pago ya estaba en ese estado
 * (doble clic, reintento): se devuelve el resultado estable y no se escribe nada.
 */
final class ResultadoRevisionPago
{
    /**
     * @param  list<array{aplicacion_id: int, order_id: int, order_transaction_id: int, invoice_id: int, monto: float}>  $transacciones
     */
    public function __construct(
        public readonly int $pagoId,
        public readonly string $estado,
        public readonly bool $yaProcesado,
        public readonly array $transacciones = [],
        public readonly ?string $motivo = null,
    ) {}

    public function toArray(): array
    {
        return [
            'pago_id' => $this->pagoId,
            'estado' => $this->estado,
            'ya_procesado' => $this->yaProcesado,
            'transacciones' => $this->transacciones,
            'motivo' => $this->motivo,
        ];
    }
}
