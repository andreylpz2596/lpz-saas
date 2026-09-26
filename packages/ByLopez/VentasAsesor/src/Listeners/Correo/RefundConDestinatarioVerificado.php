<?php

namespace ByLopez\VentasAsesor\Listeners\Correo;

use Webkul\Shop\Listeners\Refund;

/**
 * Sustituye a Webkul\Shop\Listeners\Refund por binding (RT-04B1a). Destinatario
 * igual que Shop\Mail\Order\RefundedNotification (refund.order.customer_email).
 */
class RefundConDestinatarioVerificado extends Refund
{
    use OmiteCorreoSinDestinatario;

    protected function destinatarioDelCorreo(object $entidad, object $notificacion): mixed
    {
        return $entidad->order->customer_email;
    }
}
