<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Direccion comercial: la guardada de un cliente (addresses.address_type = customer) o
 * una puntual del borrador de venta (03D).
 *
 * `telefono` es el contacto de ESA direccion y lo indica el llamador: no se asume el
 * principal del cliente (quien recibe puede ser otra persona). No entra en el indice
 * comercial de telefonos. `email` puede ser NULL. Nada de entrega aqui: metodo,
 * transportadora o tarifa son de fases posteriores.
 */
final class DireccionComercial
{
    /** @param  list<string>  $lineas */
    public function __construct(
        public readonly string $nombre,
        public readonly string $apellido,
        public readonly array $lineas,
        public readonly string $ciudad,
        /** Codigo de departamento/estado tal como lo usa Bagisto (addresses.state). */
        public readonly string $departamento,
        /** ISO 3166-1 alfa-2 (addresses.country). */
        public readonly string $pais,
        /** Obligatorio en direcciones guardadas (RT-04); opcional en el borrador (D-46). */
        public readonly ?string $codigoPostal,
        public readonly string $telefono,
        public readonly ?string $email = null,
        public readonly ?string $empresa = null,
        public readonly bool $predeterminada = false,
    ) {}
}
