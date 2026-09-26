<?php

namespace ByLopez\VentasAsesor\Support;

use ByLopez\VentasAsesor\Exceptions\ContextoPrecioComercialInvalidoException;
use Closure;
use Webkul\Checkout\Models\CartProxy;
use Webkul\Customer\Contracts\CustomerGroup;
use Webkul\Customer\Models\CustomerProxy;

/**
 * Contexto de precio comercial (RT-03B, estrategia C).
 *
 * Dice al motor de precios de Bagisto QUIEN es el cliente comercial de una
 * operacion: el cliente del carrito, no el cliente de la sesion ni el invitado.
 * No calcula precios: solo decide que grupo devuelve
 * CustomerRepositoryConContextoComercial::getCurrentGroup().
 *
 * Sin contexto, el core se comporta exactamente como siempre. El contexto nunca se
 * deduce de un carrito "activo" ni de la ruta: lo declara explicitamente quien
 * inicia la operacion. Este archivo no conoce rutas.
 *
 * Dos formas de declararlo, con las MISMAS reglas de validacion y resolucion:
 *
 *  A. durante(): delimitado por un callback, con restauracion en `finally` y
 *     soporte de anidamiento. Es la forma de los servicios (futuro
 *     CrearPedidoComercialService, API, POS, n8n, WhatsApp, jobs).
 *  B. fijarParaPeticion(): para el adaptador HTTP del panel actual de Bagisto
 *     (RouteMatched), que no puede envolver al controlador en un callback. Vive
 *     hasta olvidarPeticion(), que el adaptador llama al empezar y al terminar
 *     cada peticion; ademas la instancia es `scoped`.
 *
 * Contexto de CREACION (reorder): cuando se conoce al cliente pero su carrito aun
 * no existe, duranteCreacionDeCarrito() / fijarCreacionParaPeticion() declaran un
 * MarcoCreacionDeCarrito. Es un tipo distinto, no un cart_id nulo: en cuanto se
 * crea el carrito de ese cliente, carritoCreado() lo convierte en el contexto
 * normal (cart_id + customer_id) conservando el grupo ya resuelto; un carrito de
 * otro cliente creado mientras tanto se rechaza.
 *
 * Grupo: se resuelve UNA vez por operacion (primera consulta) desde el cliente
 * ACTUAL en la base (customer -> group; sin grupo, el invitado, como el core) y se
 * reutiliza dentro de esa operacion para que todo collectTotals sea coherente. Una
 * operacion nueva vuelve a leerlo, asi que un cambio de grupo se refleja en la
 * siguiente operacion. No hay cache global ni persistente, ni estado estatico.
 */
class ContextoPrecioComercial
{
    /** @var list<MarcoDePrecio> Marcos abiertos con durante(), el ultimo manda. */
    protected array $pila = [];

    protected ?MarcoDePrecio $dePeticion = null;

    /**
     * Ejecuta la operacion con el cliente comercial del carrito y restaura el
     * contexto anterior al salir, tambien si la operacion lanza.
     */
    public function durante(int $cartId, int $customerId, Closure $operacion): mixed
    {
        $this->pila[] = $this->validar($cartId, $customerId);

        try {
            return $operacion();
        } finally {
            array_pop($this->pila);
        }
    }

    /**
     * Ejecuta la creacion de un carrito para un cliente conocido cuyo carrito aun no
     * existe. Al crearse el carrito, el contexto pasa a ser el normal.
     */
    public function duranteCreacionDeCarrito(int $customerId, Closure $operacion): mixed
    {
        $this->pila[] = $this->validarCreacion($customerId);

        try {
            return $operacion();
        } finally {
            array_pop($this->pila);
        }
    }

    /** Contexto de creacion para la peticion actual (solo el adaptador del panel). */
    public function fijarCreacionParaPeticion(int $customerId): void
    {
        $this->dePeticion = $this->validarCreacion($customerId);
    }

    /**
     * Aviso de que se creo un carrito. Solo actua si el contexto vigente es de
     * CREACION: si el carrito es de ese cliente, el contexto pasa a normal (con el
     * grupo ya resuelto); si es de otro cliente o sin cliente, fail closed.
     */
    public function carritoCreado(int $cartId, ?int $cartCustomerId): void
    {
        $marco = $this->marcoActual();

        if (! $marco instanceof MarcoCreacionDeCarrito) {
            return;
        }

        if ($cartCustomerId !== $marco->customerId) {
            throw new ContextoPrecioComercialInvalidoException(
                ContextoPrecioComercialInvalidoException::CARRITO_DE_OTRO_CLIENTE,
                "Se creo el carrito {$cartId} para otro cliente durante la creacion del carrito del cliente {$marco->customerId}.",
            );
        }

        $normal = new MarcoPrecioComercial($cartId, $marco->customerId, $marco->grupo);

        if ($this->pila !== []) {
            $this->pila[array_key_last($this->pila)] = $normal;
        } else {
            $this->dePeticion = $normal;
        }
    }

    /** Contexto de la peticion HTTP actual (solo lo usa el adaptador del panel). */
    public function fijarParaPeticion(int $cartId, int $customerId): void
    {
        $this->dePeticion = $this->validar($cartId, $customerId);
    }

    public function olvidarPeticion(): void
    {
        $this->dePeticion = null;
    }

    public function activo(): bool
    {
        return $this->marcoActual() !== null;
    }

    /** Carrito y cliente del contexto vigente, para diagnostico y pruebas. */
    public function identificacion(): ?array
    {
        $marco = $this->marcoActual();

        return match (true) {
            $marco instanceof MarcoPrecioComercial => ['tipo' => 'carrito', 'cart_id' => $marco->cartId, 'customer_id' => $marco->customerId],
            $marco instanceof MarcoCreacionDeCarrito => ['tipo' => 'creacion', 'customer_id' => $marco->customerId],
            default => null,
        };
    }

    /**
     * Grupo comercial de la operacion vigente, o null si no hay contexto (el llamador
     * debe entonces usar el comportamiento del core).
     */
    public function grupo(): ?CustomerGroup
    {
        $marco = $this->marcoActual();

        if ($marco === null) {
            return null;
        }

        return $marco->grupo ??= $this->resolverGrupo($marco);
    }

    protected function marcoActual(): ?MarcoDePrecio
    {
        return $this->pila === [] ? $this->dePeticion : $this->pila[array_key_last($this->pila)];
    }

    /**
     * Fail closed: carrito y cliente deben existir y el carrito debe pertenecer a ese
     * cliente. Ninguna inconsistencia se degrada a sesion o invitado.
     */
    protected function validar(int $cartId, int $customerId): MarcoPrecioComercial
    {
        if ($cartId <= 0) {
            throw new ContextoPrecioComercialInvalidoException(
                ContextoPrecioComercialInvalidoException::IDENTIFICADOR_INVALIDO,
                'El contexto de precio exige cart_id y customer_id positivos.',
            );
        }

        $carrito = CartProxy::modelClass()::query()->select(['id', 'customer_id'])->find($cartId);

        if (! $carrito) {
            throw new ContextoPrecioComercialInvalidoException(
                ContextoPrecioComercialInvalidoException::CARRITO_INEXISTENTE,
                "El carrito {$cartId} no existe.",
            );
        }

        if ($carrito->customer_id === null) {
            throw new ContextoPrecioComercialInvalidoException(
                ContextoPrecioComercialInvalidoException::CARRITO_SIN_CLIENTE,
                "El carrito {$cartId} no tiene cliente comercial.",
            );
        }

        if ($customerId <= 0 || (int) $carrito->customer_id !== $customerId) {
            throw new ContextoPrecioComercialInvalidoException(
                ContextoPrecioComercialInvalidoException::CLIENTE_NO_COINCIDE,
                "El carrito {$cartId} no pertenece al cliente {$customerId}.",
            );
        }

        $this->exigirCliente($customerId);

        return new MarcoPrecioComercial($cartId, $customerId);
    }

    protected function validarCreacion(int $customerId): MarcoCreacionDeCarrito
    {
        if ($customerId <= 0) {
            throw new ContextoPrecioComercialInvalidoException(
                ContextoPrecioComercialInvalidoException::IDENTIFICADOR_INVALIDO,
                'El contexto de creacion exige un customer_id positivo.',
            );
        }

        $this->exigirCliente($customerId);

        return new MarcoCreacionDeCarrito($customerId);
    }

    /** Grupo ACTUAL del cliente; sin grupo (o grupo borrado), el invitado, como el core. */
    protected function resolverGrupo(MarcoDePrecio $marco): CustomerGroup
    {
        $cliente = $this->exigirCliente($marco->customerId);

        return $cliente->group ?? core()->getGuestCustomerGroup();
    }

    protected function exigirCliente(int $customerId): object
    {
        $cliente = CustomerProxy::modelClass()::query()->with('group')->find($customerId);

        if (! $cliente) {
            throw new ContextoPrecioComercialInvalidoException(
                ContextoPrecioComercialInvalidoException::CLIENTE_INEXISTENTE,
                "El cliente {$customerId} no existe.",
            );
        }

        return $cliente;
    }
}
