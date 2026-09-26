<?php

namespace ByLopez\VentasAsesor\Support\Correo;

use ByLopez\VentasAsesor\Events\NotificacionClienteOmitida;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Webkul\Sales\Contracts\Order;

/**
 * Decide si un correo al cliente tiene destinatario (D-RT04-06) y registra la
 * omision cuando no lo tiene.
 *
 * La validez se comprueba con el MISMO criterio que usa el envio: el constructor de
 * Symfony\Component\Mime\Address, que es quien rechazaria la direccion. Asi nunca se
 * omite un correo que el mailer habria aceptado (no se desactivan correos validos).
 */
final class DestinatarioCorreo
{
    public const SIN_EMAIL = 'sin_email';

    public const EMAIL_VACIO = 'email_vacio';

    public const EMAIL_INVALIDO = 'email_invalido';

    /** null si el destinatario es valido; si no, el motivo de la omision. */
    public static function motivoDeOmision(mixed $email): ?string
    {
        if ($email === null) {
            return self::SIN_EMAIL;
        }

        if (! is_string($email)) {
            return self::EMAIL_INVALIDO;
        }

        if (trim($email) === '') {
            return self::EMAIL_VACIO;
        }

        try {
            new Address($email);
        } catch (RfcComplianceException) {
            return self::EMAIL_INVALIDO;
        }

        return null;
    }

    /** Registro sin datos personales + evento propio. Nunca lanza por si mismo. */
    public static function registrarOmision(string $notificacion, object $entidad, string $motivo): void
    {
        $pedido = $entidad instanceof Order ? $entidad : ($entidad->order ?? null);

        $evento = new NotificacionClienteOmitida(
            notificacion: $notificacion,
            entidad: $entidad::class,
            entidadId: isset($entidad->id) ? (int) $entidad->id : null,
            orderId: isset($pedido->id) ? (int) $pedido->id : null,
            customerId: ($id = $entidad->customer_id ?? $pedido->customer_id ?? null) === null ? null : (int) $id,
            motivo: $motivo,
        );

        Log::info('ventas_asesor.notificacion_cliente.omitida', [
            'notificacion' => $evento->notificacion,
            'entidad' => $evento->entidad,
            'entidad_id' => $evento->entidadId,
            'order_id' => $evento->orderId,
            'customer_id' => $evento->customerId,
            'motivo' => $evento->motivo,
        ]);

        event($evento);
    }
}
