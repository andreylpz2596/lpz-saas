<?php

namespace ByLopez\VentasAsesor\DataObjects;

/** Datos minimos de un cliente comercial (RT-04B1b). */
final class DatosClienteComercial
{
    public function __construct(
        /** customers.first_name */
        public readonly string $nombre,
        /** customers.last_name (NOT NULL en Bagisto) */
        public readonly string $apellido,
        /** Principal: se guarda en E.164 en customers.phone y en el indice. */
        public readonly string $telefono,
        /** Explicito: sin grupo por defecto (D-RT04-04). */
        public readonly ?int $customerGroupId,
        /** Opcional; vacio o solo espacios se guarda como NULL, nunca "". */
        public readonly ?string $email = null,
        /** null → canal por defecto. */
        public readonly ?int $channelId = null,
    ) {}
}
