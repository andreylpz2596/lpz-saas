<?php

namespace ByLopez\Logistica\DataObjects;

use ByLopez\Logistica\Enums\FuenteEventoLogistico;

/**
 * Solicitud de entrega o de retorno de transportadora sobre UN envio (Shipment). La usan el panel y, en el futuro,
 * una integracion: sin Request ni sesion.
 *
 * - `items`: shipment_item_id => cantidad (enteros > 0; el panel descarta las filas en 0 antes de llegar aqui).
 * - `orderId`: el pedido que el operador cree estar tocando; si no coincide con el del envio se rechaza.
 * - `carrierCode`: solo cuenta si el envio NO tiene `carrier_code` (historicos / despachos sin catalogo).
 * - `idempotencyKey`: la misma clave = la misma operacion (doble clic, reintento, integracion repetida).
 */
final class SolicitudEventoEnvio
{
    /** @param  array<int|string, mixed>  $items */
    public function __construct(
        public readonly int $shipmentId,
        public readonly ?int $orderId,
        public readonly string $fecha,
        public readonly array $items,
        public readonly FuenteEventoLogistico|string $fuente,
        public readonly string $idempotencyKey,
        public readonly ?string $carrierCode = null,
        public readonly ?string $evidenciaReferencia = null,
        public readonly ?string $motivo = null,
    ) {}
}
