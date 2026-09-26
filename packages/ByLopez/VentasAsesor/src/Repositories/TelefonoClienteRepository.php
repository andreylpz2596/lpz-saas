<?php

namespace ByLopez\VentasAsesor\Repositories;

use ByLopez\VentasAsesor\Exceptions\TelefonoPerteneceAOtroClienteException;
use ByLopez\VentasAsesor\Models\TelefonoCliente;
use Illuminate\Database\UniqueConstraintViolationException;
use Webkul\Core\Eloquent\Repository;

/**
 * Acceso al indice comercial de telefonos. Solo datos: las decisiones (que indexar,
 * que es conflicto) viven en los servicios. Las invariantes las garantiza la base
 * (ver la migracion 000005); aqui solo se traduce su violacion a una excepcion de
 * dominio.
 */
class TelefonoClienteRepository extends Repository
{
    public function model(): string
    {
        return TelefonoCliente::class;
    }

    public function buscarPorNormalizado(string $normalizado): ?TelefonoCliente
    {
        return $this->model->newQuery()->where('telefono_normalizado', $normalizado)->first();
    }

    public function principalDe(int $customerId): ?TelefonoCliente
    {
        return $this->model->newQuery()->where('customer_id', $customerId)->principales()->first();
    }

    /**
     * @throws TelefonoPerteneceAOtroClienteException si la unica del normalizado lo rechaza
     */
    public function guardar(TelefonoCliente $telefono): TelefonoCliente
    {
        try {
            $telefono->save();
        } catch (UniqueConstraintViolationException $e) {
            $titular = $this->buscarPorNormalizado((string) $telefono->telefono_normalizado);

            if ($titular && $titular->customer_id !== $telefono->customer_id) {
                throw new TelefonoPerteneceAOtroClienteException($titular->customer_id, (int) $telefono->customer_id);
            }

            throw $e;
        }

        return $telefono;
    }
}
