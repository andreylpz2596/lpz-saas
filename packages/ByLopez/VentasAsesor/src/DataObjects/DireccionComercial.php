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
        /** Guardadas: obligatorio solo si Bagisto lo exige (customer.address.requirements.postcode, D-58); borrador: opcional (D-46). */
        public readonly ?string $codigoPostal,
        public readonly string $telefono,
        public readonly ?string $email = null,
        public readonly ?string $empresa = null,
        public readonly bool $predeterminada = false,
        /**
         * Codigo DIVIPOLA (5 caracteres) del municipio elegido en el autocomplete (D-58). Si viene,
         * el BACKEND resuelve ciudad, departamento (`state` de Bagisto) y pais desde el catalogo e
         * IGNORA `ciudad`, `departamento` y `pais`. Sin el, se usan los textos recibidos (llamadores
         * programaticos y direcciones historicas); la UI de Nueva venta siempre lo exige.
         */
        public readonly ?string $municipioCodigo = null,
    ) {}

    /** La misma direccion marcada como predeterminada del cliente (copia todos los campos, incluido el municipio). */
    public function comoPredeterminada(): self
    {
        return new self(
            nombre: $this->nombre, apellido: $this->apellido, lineas: $this->lineas, ciudad: $this->ciudad,
            departamento: $this->departamento, pais: $this->pais, codigoPostal: $this->codigoPostal,
            telefono: $this->telefono, email: $this->email, empresa: $this->empresa,
            predeterminada: true, municipioCodigo: $this->municipioCodigo,
        );
    }
}
