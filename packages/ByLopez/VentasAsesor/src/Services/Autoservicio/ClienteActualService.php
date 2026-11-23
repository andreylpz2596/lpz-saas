<?php

namespace ByLopez\VentasAsesor\Services\Autoservicio;

use ByLopez\VentasAsesor\Exceptions\AutoservicioInvalidoException as Invalido;
use ByLopez\VentasAsesor\Models\ClienteAcceso;
use Webkul\Customer\Models\Customer;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Doc. 33 P3: el cliente comercial de un usuario de autoservicio lo fija SIEMPRE el servidor a partir del vinculo
 * (bylopez_va_clientes_acceso, estado activo). Nada que llegue en la peticion (customer_id, grupo) se usa nunca.
 * Falla cerrado: sin vinculo activo, o con el cliente inactivo/suspendido, no hay cliente y no se opera.
 */
class ClienteActualService
{
    public function de(?AdminContract $actor): Customer
    {
        if (! $actor?->id) {
            throw new Invalido(Invalido::SIN_VINCULO);
        }

        $id = (int) $actor->id;

        $vinculo = ClienteAcceso::query()->where('admin_id', $id)->where('estado', ClienteAcceso::ACTIVO)->first();

        if (! $vinculo) {
            throw new Invalido(Invalido::SIN_VINCULO);
        }

        $cliente = Customer::query()->find($vinculo->customer_id);

        if (! $cliente || ! $cliente->status || $cliente->is_suspended) {
            throw new Invalido(Invalido::CLIENTE_NO_APTO);
        }

        return $cliente;
    }
}
