<?php

namespace ByLopez\VentasAsesor\Support\Clientes;

use ByLopez\VentasAsesor\Exceptions\GrupoComercialInvalidoException as Invalido;
use Webkul\Customer\Contracts\CustomerGroup;
use Webkul\Customer\Repositories\CustomerGroupRepository;

/**
 * Grupo explicito y valido para un cliente comercial (D-RT04-04, RT-04B1a).
 *
 * Bagisto no tiene grupos activos o inactivos (`customer_groups`: id, code, name,
 * is_user_defined), asi que no se inventa esa regla. El invitado se identifica como
 * lo hace el propio core, con core()->getGuestCustomerGroup() (code 'guest',
 * Core.php:731-738), no por su nombre visible. No hay grupo por defecto.
 *
 * RT-03 no cambia: este validador solo impide que nazca un cliente comercial sin
 * grupo apto; el precio lo sigue calculando RT-03 con el grupo actual del cliente.
 */
class ValidadorGrupoComercial
{
    public function __construct(protected CustomerGroupRepository $grupos) {}

    /** @throws Invalido */
    public function validar(?int $customerGroupId): CustomerGroup
    {
        if ($customerGroupId === null || $customerGroupId <= 0) {
            throw new Invalido(Invalido::GRUPO_REQUERIDO);
        }

        $grupo = $this->grupos->find($customerGroupId);

        if (! $grupo) {
            throw new Invalido(Invalido::GRUPO_INEXISTENTE);
        }

        $invitado = core()->getGuestCustomerGroup();

        if (! $invitado) {
            throw new Invalido(Invalido::INVITADO_NO_IDENTIFICABLE);
        }

        if ((int) $grupo->id === (int) $invitado->id) {
            throw new Invalido(Invalido::GRUPO_INVITADO_NO_PERMITIDO);
        }

        return $grupo;
    }
}
