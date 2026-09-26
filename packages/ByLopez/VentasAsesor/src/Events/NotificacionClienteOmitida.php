<?php

namespace ByLopez\VentasAsesor\Events;

/**
 * Se omitio un correo AL CLIENTE porque no hay destinatario valido (D-RT04-06).
 *
 * No es un fallo del mailer: es ausencia de destinatario. Queda como punto de
 * enganche para notificar por otro canal en el futuro (p. ej. WhatsApp) sin tocar
 * los listeners. No transporta el email ni otros datos del cliente.
 */
final class NotificacionClienteOmitida
{
    public function __construct(
        /** Clase del mailable del core que no se envio. */
        public readonly string $notificacion,
        /** Clase de la entidad del evento (Order, Invoice, Shipment, Refund, OrderComment, CustomerNote). */
        public readonly string $entidad,
        public readonly ?int $entidadId,
        public readonly ?int $orderId,
        public readonly ?int $customerId,
        /** sin_email | email_vacio | email_invalido */
        public readonly string $motivo,
    ) {}
}
