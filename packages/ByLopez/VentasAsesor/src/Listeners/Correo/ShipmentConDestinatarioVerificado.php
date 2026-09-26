<?php

namespace ByLopez\VentasAsesor\Listeners\Correo;

use Webkul\Shop\Listeners\Shipment;

/**
 * Sustituye a Webkul\Shop\Listeners\Shipment por binding (RT-04B1a). Destinatario
 * igual que Shop\Mail\Order\ShippedNotification (shipment.order.customer_email).
 */
class ShipmentConDestinatarioVerificado extends Shipment
{
    use OmiteCorreoSinDestinatario;

    protected function destinatarioDelCorreo(object $entidad, object $notificacion): mixed
    {
        return $entidad->order->customer_email;
    }
}
