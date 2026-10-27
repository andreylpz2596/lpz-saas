<?php

namespace ByLopez\Logistica\DataObjects;

/**
 * «Entregar en bodega»: el cliente retira un pedido de recogida. No lleva transportadora, guia ni PDF. Crea el
 * Shipment real (la salida de inventario) y la entrega con fuente `recogida_bodega` en un solo acto.
 */
final class SolicitudRecogida
{
    /** @param  array<int|string, mixed>  $unidades */
    public function __construct(
        public readonly int $orderId,
        public readonly string $idempotencyKey,
        public readonly array $unidades = [],
        public readonly ?string $quienRetira = null,
    ) {}
}
