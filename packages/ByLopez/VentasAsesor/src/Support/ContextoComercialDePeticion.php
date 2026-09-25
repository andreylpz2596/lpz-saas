<?php

namespace ByLopez\VentasAsesor\Support;

use ByLopez\VentasAsesor\DataObjects\ContextoComercial;
use ByLopez\VentasAsesor\Exceptions\ContextoDePeticionInvalidoException;
use Closure;

/**
 * Transporte del contexto comercial entre quien CREA el pedido y el listener que
 * lo atribuye, dentro de una MISMA peticion (mecanismo M-3 de [D-§8]).
 *
 * Se registra como `scoped` en el contenedor, nunca como `singleton`: con Octane
 * un singleton sobrevive entre peticiones y una venta terminaria atribuida al
 * asesor de la peticion anterior. Tampoco usa estado estatico, sesion ni
 * variables globales, por la misma razon.
 *
 * La clave es el `cart_id`, no el `order_id`: durante los reintentos de
 * OrderRepository::createOrderIfNotThenRetry() cada intento crea un pedido con un
 * id DISTINTO (medido en [D-§13]), mientras el carrito es el mismo. Atar el
 * contexto al primer `order_id` temporal lo perderia en el segundo intento.
 *
 * Consultar NO consume: el listener puede leerlo tres veces si el pedido se
 * reintenta tres veces. Quien lo registro es el unico responsable de retirarlo,
 * y para eso existe durante().
 */
class ContextoComercialDePeticion
{
    /** @var array<int, ContextoComercial> */
    protected array $contextos = [];

    /**
     * Deja disponible el contexto de un carrito. Repetir el registro con un
     * contexto equivalente es valido (reintento); hacerlo con uno distinto es un
     * error, porque el reemplazo silencioso cambiaria el dueño de la venta.
     */
    public function registrar(int $cartId, ContextoComercial $contexto): void
    {
        $this->exigirClaveValida($cartId);

        if ($contexto->cartId !== $cartId) {
            throw new ContextoDePeticionInvalidoException(sprintf(
                'El contexto declara cart_id %s pero se quiere registrar bajo la clave %d.',
                $contexto->cartId === null ? 'nulo' : (string) $contexto->cartId,
                $cartId,
            ));
        }

        $previo = $this->contextos[$cartId] ?? null;

        if ($previo !== null && ! $this->sonEquivalentes($previo, $contexto)) {
            throw new ContextoDePeticionInvalidoException(sprintf(
                'El carrito %d ya tiene un contexto comercial distinto en esta peticion.',
                $cartId,
            ));
        }

        $this->contextos[$cartId] = $contexto;
    }

    /** Lectura sin consumo: sobrevive a todos los reintentos del repositorio. */
    public function para(int $cartId): ?ContextoComercial
    {
        return $this->contextos[$cartId] ?? null;
    }

    public function tieneContextoPara(int $cartId): bool
    {
        return isset($this->contextos[$cartId]);
    }

    /** Retira SOLO el contexto de ese carrito; los demas carritos siguen intactos. */
    public function olvidar(int $cartId): void
    {
        unset($this->contextos[$cartId]);
    }

    /** @return list<int> Carritos con contexto vivo. Existe para diagnostico y pruebas. */
    public function cartsRegistrados(): array
    {
        return array_values(array_map('intval', array_keys($this->contextos)));
    }

    /**
     * Unica forma segura de usar el contexto: lo registra, ejecuta la operacion
     * (con todos los reintentos que haga el repositorio por dentro) y lo retira en
     * `finally`, tambien si la operacion lanza.
     *
     * Si ya habia un contexto para ese carrito —anidamiento valido, es decir
     * equivalente— se restaura al salir en lugar de borrarlo: el nivel externo
     * sigue necesitandolo.
     */
    public function durante(int $cartId, ContextoComercial $contexto, Closure $operacion): mixed
    {
        $this->exigirClaveValida($cartId);

        $previo = $this->contextos[$cartId] ?? null;

        $this->registrar($cartId, $contexto);

        try {
            return $operacion();
        } finally {
            if ($previo !== null) {
                $this->contextos[$cartId] = $previo;
            } else {
                $this->olvidar($cartId);
            }
        }
    }

    protected function exigirClaveValida(int $cartId): void
    {
        if ($cartId <= 0) {
            throw new ContextoDePeticionInvalidoException(
                'El contexto comercial de la peticion se indexa por un cart_id positivo.'
            );
        }
    }

    protected function sonEquivalentes(ContextoComercial $uno, ContextoComercial $otro): bool
    {
        return $uno->valoresComparables() === $otro->valoresComparables()
            && $uno->codigoTecnico === $otro->codigoTecnico;
    }
}
