<?php

namespace ByLopez\VentasAsesor\DataObjects;

use Webkul\Customer\Contracts\Customer;

/**
 * Vista de solo lectura de un Customer para los consumidores comerciales (panel,
 * API, n8n...). `via` dice por que coincidio: indice_principal | indice_secundario |
 * ficha_sin_indexar | email | nombre.
 */
final class ClienteEncontrado
{
    public function __construct(
        public readonly int $customerId,
        public readonly string $nombre,
        public readonly string $apellido,
        public readonly ?string $email,
        public readonly ?string $telefonoPrincipal,
        public readonly ?int $grupoId,
        public readonly ?string $grupoNombre,
        public readonly string $via,
    ) {}

    /** Espera el Customer con la relacion `group` ya cargada (sin N+1). */
    public static function desde(Customer $cliente, string $via): self
    {
        return new self(
            (int) $cliente->id,
            (string) $cliente->first_name,
            (string) $cliente->last_name,
            $cliente->email,
            $cliente->phone,
            $cliente->customer_group_id === null ? null : (int) $cliente->customer_group_id,
            $cliente->group?->name,
            $via,
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
