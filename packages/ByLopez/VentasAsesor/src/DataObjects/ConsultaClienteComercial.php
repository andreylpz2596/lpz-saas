<?php

namespace ByLopez\VentasAsesor\DataObjects;

/** Criterios de busqueda comercial. Todos opcionales; el telefono manda sobre los demas. */
final class ConsultaClienteComercial
{
    public function __construct(
        public readonly ?string $telefono = null,
        public readonly ?string $email = null,
        public readonly ?string $nombre = null,
        /** null → canal por defecto (la unicidad del email en Bagisto es por canal). */
        public readonly ?int $channelId = null,
        public readonly int $limiteCandidatos = 20,
    ) {}
}
