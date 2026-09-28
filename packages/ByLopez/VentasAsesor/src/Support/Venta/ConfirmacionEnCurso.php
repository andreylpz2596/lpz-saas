<?php

namespace ByLopez\VentasAsesor\Support\Venta;

use Closure;
use InvalidArgumentException;

/**
 * Intento de confirmacion ByLopez en curso, por carrito (03F). Transporta borrador y token
 * desde ConfirmarVentaService hasta el cerco (`checkout.order.save.before`) y el enlace
 * (`checkout.order.save.after`), que corren dentro de OrderRepository.
 *
 * Mismo patron que ContextoComercialDePeticion: `scoped` (nunca singleton ni estado
 * estatico), clave `cart_id` (el order_id cambia en cada reintento del repositorio),
 * lectura sin consumo y retiro en `finally`.
 *
 * Que exista un intento aqui NO autoriza nada por si solo: el cerco lo contrasta con la
 * fila del borrador bajo FOR UPDATE (token vigente, `confirmando`, sin pedido).
 */
class ConfirmacionEnCurso
{
    /** @var array<int, array{borrador_id: int, token: string}> */
    protected array $intentos = [];

    public function durante(int $cartId, int $borradorId, string $token, Closure $operacion): mixed
    {
        if ($cartId <= 0 || $borradorId <= 0 || $token === '') {
            throw new InvalidArgumentException('Intento de confirmacion sin carrito, borrador o token.');
        }

        if (isset($this->intentos[$cartId])) {
            throw new InvalidArgumentException("El carrito {$cartId} ya tiene un intento de confirmacion en curso.");
        }

        $this->intentos[$cartId] = ['borrador_id' => $borradorId, 'token' => $token];

        try {
            return $operacion();
        } finally {
            unset($this->intentos[$cartId]);
        }
    }

    /** @return array{borrador_id: int, token: string}|null */
    public function para(int $cartId): ?array
    {
        return $this->intentos[$cartId] ?? null;
    }
}
