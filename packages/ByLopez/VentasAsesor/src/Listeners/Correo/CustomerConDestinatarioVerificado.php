<?php

namespace ByLopez\VentasAsesor\Listeners\Correo;

use ByLopez\VentasAsesor\Support\Correo\DestinatarioCorreo;
use Webkul\Shop\Listeners\Customer;
use Webkul\Shop\Mail\Customer\NoteNotification;

/**
 * Sustituye a Webkul\Shop\Listeners\Customer por binding (RT-04B1a).
 *
 * Solo protege afterNoteCreated: es el unico camino del core que puede llegar con un
 * cliente sin email (nota del panel con "notificar al cliente"). Los demas metodos
 * se heredan sin cambios porque ningun flujo del core los alcanza sin email:
 * afterCreated solo lo dispara customer.registration.after (registro de tienda y alta
 * del panel, ambos exigen email) y afterPasswordUpdated exige un cliente que inicio
 * sesion o restablecio su contrasena por email. afterSubscribed usa el email del
 * suscriptor, que siempre existe.
 *
 * Este metodo no usa prepareMail() en el core, asi que la comprobacion de
 * `customer_notified` se lee igual que el core antes de decidir la omision.
 */
class CustomerConDestinatarioVerificado extends Customer
{
    public function afterNoteCreated($note)
    {
        if ($note->customer_notified) {
            $motivo = DestinatarioCorreo::motivoDeOmision($note->customer?->email);

            if ($motivo !== null) {
                DestinatarioCorreo::registrarOmision(NoteNotification::class, $note, $motivo);

                return;
            }
        }

        parent::afterNoteCreated($note);
    }
}
