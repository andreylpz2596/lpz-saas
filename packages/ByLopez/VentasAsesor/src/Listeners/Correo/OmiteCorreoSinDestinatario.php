<?php

namespace ByLopez\VentasAsesor\Listeners\Correo;

use ByLopez\VentasAsesor\Support\Correo\DestinatarioCorreo;

/**
 * Guard para los listeners de correo del Shop que heredan de
 * Webkul\Shop\Listeners\Base (RT-04B1a).
 *
 * Se engancha en prepareMail(), el punto en el que el core YA decidio enviar (paso
 * sus comprobaciones de configuracion y de `customer_notified`), asi que no se
 * duplica ninguna de esas reglas. Si no hay destinatario valido, se omite y se
 * registra; si lo hay, se ejecuta exactamente el prepareMail del core, incluido su
 * try/catch: un fallo real del mailer se comporta igual que sin este guard.
 */
trait OmiteCorreoSinDestinatario
{
    /** El mismo destinatario que usa el envelope() del mailable del core. */
    abstract protected function destinatarioDelCorreo(object $entidad, object $notificacion): mixed;

    protected function prepareMail($entity, $notification)
    {
        $motivo = DestinatarioCorreo::motivoDeOmision($this->destinatarioDelCorreo($entity, $notification));

        if ($motivo !== null) {
            DestinatarioCorreo::registrarOmision($notification::class, $entity, $motivo);

            return;
        }

        parent::prepareMail($entity, $notification);
    }
}
