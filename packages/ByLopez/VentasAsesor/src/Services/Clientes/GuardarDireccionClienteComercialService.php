<?php

namespace ByLopez\VentasAsesor\Services\Clientes;

use ByLopez\VentasAsesor\DataObjects\DireccionComercial;
use ByLopez\VentasAsesor\Exceptions\DatosClienteComercialInvalidosException as Invalidos;
use ByLopez\VentasAsesor\Support\Clientes\ValidadorDireccionComercial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Customer\Contracts\CustomerAddress;
use Webkul\Customer\Models\CustomerProxy;
use Webkul\Customer\Repositories\CustomerAddressRepository;

/**
 * Crea o actualiza la direccion guardada de un cliente comercial sin pasar por los
 * Form Requests de la UI (RT-04B1b).
 *
 * Replica el contrato de Admin\CustomerAddress (AddressRequest + AddressController)
 * salvo dos puntos del flujo comercial: email opcional (NULL) y telefono normalizado
 * a E.164 en lugar de la regla PhoneNumber (el E.164 la cumple). El codigo postal se
 * valida con la misma regla del core (PostCode). Las lineas se unen con PHP_EOL como el
 * core, y se disparan los mismos eventos customer.addresses.*.
 *
 * A diferencia del controlador del core, la direccion predeterminada anterior se
 * desmarca por el customer_id del cliente (el core usa el customer_id del request).
 */
class GuardarDireccionClienteComercialService
{
    public function __construct(
        protected CustomerAddressRepository $direcciones,
        protected ValidadorDireccionComercial $validador,
    ) {}

    /** Sin $addressId crea; con el, actualiza una direccion QUE PERTENECE a ese cliente. */
    public function guardar(int $customerId, DireccionComercial $direccion, ?int $addressId = null): CustomerAddress
    {
        $datos = $this->validar($direccion);

        return DB::transaction(function () use ($customerId, $datos, $direccion, $addressId) {
            if (! CustomerProxy::modelClass()::query()->lockForUpdate()->find($customerId)) {
                throw new Invalidos(['customer_id' => Invalidos::REQUERIDO]);
            }

            $existente = $addressId === null ? null : $this->direcciones->getModel()->newQuery()
                ->where('customer_id', $customerId)->find($addressId);

            if ($addressId !== null && ! $existente) {
                throw new Invalidos(['address_id' => Invalidos::FORMATO_INVALIDO]);
            }

            Event::dispatch($existente ? 'customer.addresses.update.before' : 'customer.addresses.create.before', $addressId);

            if ($direccion->predeterminada) {
                $this->direcciones->getModel()->newQuery()
                    ->where('customer_id', $customerId)
                    ->where('default_address', 1)
                    ->when($existente, fn ($q) => $q->whereKeyNot($existente->id))
                    ->update(['default_address' => 0]);
            }

            if ($existente) {
                $guardada = $this->direcciones->update($datos, $existente->id);
                Event::dispatch('customer.addresses.update.after', $guardada);

                return $guardada;
            }

            $guardada = $this->direcciones->create($datos + ['customer_id' => $customerId]);
            Event::dispatch('customer.addresses.create.after', $guardada);

            return $guardada;
        });
    }

    /** @return array<string, mixed> columnas de addresses */
    private function validar(DireccionComercial $d): array
    {
        // Reglas compartidas con el borrador de venta (03D); aqui el codigo postal sigue
        // siendo obligatorio (decision de RT-04).
        return $this->validador->validar($d, exigirCodigoPostal: true)
            + ['default_address' => $d->predeterminada ? 1 : 0];
    }
}
