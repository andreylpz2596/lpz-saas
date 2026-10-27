<?php

namespace ByLopez\VentasAsesor\Listeners\Correo;

use Bagisto\Pickup\Listeners\Shipment;

/**
 * Sustituye por binding a Bagisto\Pickup\Listeners\Shipment (paquete bagisto-eu/pickup), que escucha
 * sales.shipment.save.after por su cuenta y envia ShippedNotification/PickupNotification sin pasar por el guard del
 * Shop (RT-04B1a). Ambos mailables usan shipment.order.customer_email como destinatario.
 */
class PickupShipmentConDestinatarioVerificado extends Shipment
{
    use OmiteCorreoSinDestinatario;

    protected function destinatarioDelCorreo(object $entidad, object $notificacion): mixed
    {
        return $entidad->order->customer_email;
    }
}
