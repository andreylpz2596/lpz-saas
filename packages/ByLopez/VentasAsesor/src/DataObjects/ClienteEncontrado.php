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
        public readonly bool $activo = true,
        public readonly bool $suspendido = false,
        public readonly bool $grupoApto = false,
    ) {}

    /**
     * Espera el Customer con la relacion `group` ya cargada (sin N+1). `grupoApto` = tiene grupo
     * y no es el invitado (`$invitadoId`); es solo informativo: la regla la aplica el validador.
     */
    public static function desde(Customer $cliente, string $via, ?int $invitadoId = null): self
    {
        $grupoId = $cliente->customer_group_id === null ? null : (int) $cliente->customer_group_id;

        return new self(
            (int) $cliente->id,
            (string) $cliente->first_name,
            (string) $cliente->last_name,
            $cliente->email,
            $cliente->phone,
            $cliente->customer_group_id === null ? null : (int) $cliente->customer_group_id,
            $cliente->group?->name,
            $via,
            (bool) $cliente->status,
            (bool) $cliente->is_suspended,
            $grupoId !== null && $grupoId > 0 && $invitadoId !== null && $grupoId !== $invitadoId,
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
