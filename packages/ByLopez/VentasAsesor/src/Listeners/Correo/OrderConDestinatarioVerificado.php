<?php

namespace ByLopez\VentasAsesor\Listeners\Correo;

use Webkul\Sales\Contracts\OrderComment;
use Webkul\Shop\Listeners\Order;

/**
 * Sustituye a Webkul\Shop\Listeners\Order por binding (RT-04B1a). Cubre
 * afterCreated, afterCanceled y afterCommented. Destinatario igual que
 * Shop\Mail\Order\{Created,Canceled}Notification (order.customer_email) y
 * CommentedNotification (comment.order.customer_email).
 */
class OrderConDestinatarioVerificado extends Order
{
    use OmiteCorreoSinDestinatario;

    protected function destinatarioDelCorreo(object $entidad, object $notificacion): mixed
    {
        return $entidad instanceof OrderComment ? $entidad->order->customer_email : $entidad->customer_email;
    }
}
