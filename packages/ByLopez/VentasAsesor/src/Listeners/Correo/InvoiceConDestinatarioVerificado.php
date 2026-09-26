<?php

namespace ByLopez\VentasAsesor\Listeners\Correo;

use Webkul\Shop\Listeners\Invoice;

/**
 * Sustituye a Webkul\Shop\Listeners\Invoice por binding (RT-04B1a), para
 * sales.invoice.save.after y sales.invoice.send_duplicate_email. Destinatario igual
 * que Shop\Mail\Order\InvoicedNotification: el email escrito por el administrador en
 * el reenvio, o si no order.customer_email.
 */
class InvoiceConDestinatarioVerificado extends Invoice
{
    use OmiteCorreoSinDestinatario;

    protected function destinatarioDelCorreo(object $entidad, object $notificacion): mixed
    {
        return $notificacion->duplicateInvoiceEmail ?? $entidad->order->customer_email;
    }
}
