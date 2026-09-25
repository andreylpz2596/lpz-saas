<?php

namespace ByLopez\VentasAsesor\DataObjects;

use ByLopez\VentasAsesor\Enums\ResultadoReconciliacion;

/**
 * Veredicto del analisis de UN pedido. Es un valor: contiene lo necesario para el
 * reporte y para que el modo persistente ejecute exactamente lo que el dry-run
 * anuncio. No lleva datos del cliente.
 */
final class AnalisisPedidoHistorico
{
    /**
     * @param  array<string, mixed>  $hechos  Hechos persistidos usados (sin datos personales).
     */
    public function __construct(
        public readonly int $orderId,
        public readonly ?string $incrementId,
        public readonly ?int $cartId,
        public readonly ?string $estadoPedido,
        public readonly ResultadoReconciliacion $resultado,
        public readonly string $codigo,
        public readonly string $accionPersistente,
        public readonly ?ContextoComercial $contexto = null,
        public readonly ?string $fuente = null,
        public readonly ?int $atribucionId = null,
        public readonly array $hechos = [],
        public readonly array $camposConflicto = [],
    ) {}

    public function con(ResultadoReconciliacion $resultado, string $codigo, ?string $accion = null): self
    {
        return new self(
            $this->orderId, $this->incrementId, $this->cartId, $this->estadoPedido,
            $resultado, $codigo, $accion ?? $this->accionPersistente,
            $this->contexto, $this->fuente, $this->atribucionId, $this->hechos, $this->camposConflicto,
        );
    }
}
