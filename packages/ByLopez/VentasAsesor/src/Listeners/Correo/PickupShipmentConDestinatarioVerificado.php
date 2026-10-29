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

    /**
     * El paquete de terceros escucha el mismo evento que el listener del Shop y, en un envio que NO es de recogida, envia OTRA VEZ la misma
     * ShippedNotification que ya envia el Shop: cada despacho llegaba duplicado al cliente (demostrado con Rt04b1aGuardNotificacionesTest, que ya fallaba
     * en el respaldo). Para un envio normal basta el aviso del Shop; el paquete solo conserva lo suyo: el aviso de RECOGIDA.
     */
    public function handle($shipment)
    {
        if ((string) $shipment->order->shipping_method !== 'pickup') {
            return;
        }

        parent::handle($shipment);
    }

    protected function destinatarioDelCorreo(object $entidad, object $notificacion): mixed
    {
        return $entidad->order->customer_email;
    }
}
