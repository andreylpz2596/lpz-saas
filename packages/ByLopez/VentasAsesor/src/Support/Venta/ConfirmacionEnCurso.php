<?php

namespace ByLopez\VentasAsesor\Support\Venta;

use ByLopez\VentasAsesor\Enums\TipoOperacionPedido;
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
    /** @var array<int, array{borrador_id: int, token: string, tipo_operacion: TipoOperacionPedido, actor_id: ?int}> */
    protected array $intentos = [];

    /**
     * G-11: `tipo_operacion` (ya autorizado por ConfirmarVentaService) y `actor_id` viajan con el intento hasta
     * ClasificarOperacionPedido, que escribe la clasificacion dentro de la transaccion del pedido.
     */
    public function durante(
        int $cartId,
        int $borradorId,
        string $token,
        Closure $operacion,
        TipoOperacionPedido $tipoOperacion = TipoOperacionPedido::VENTA_NORMAL,
        ?int $actorId = null,
    ): mixed {
        if ($cartId <= 0 || $borradorId <= 0 || $token === '') {
            throw new InvalidArgumentException('Intento de confirmacion sin carrito, borrador o token.');
        }

        if (isset($this->intentos[$cartId])) {
            throw new InvalidArgumentException("El carrito {$cartId} ya tiene un intento de confirmacion en curso.");
        }

        $this->intentos[$cartId] = [
            'borrador_id' => $borradorId, 'token' => $token, 'tipo_operacion' => $tipoOperacion, 'actor_id' => $actorId,
        ];

        try {
            return $operacion();
        } finally {
            unset($this->intentos[$cartId]);
        }
    }

    /** @return array{borrador_id: int, token: string, tipo_operacion: TipoOperacionPedido, actor_id: ?int}|null */
    public function para(int $cartId): ?array
    {
        return $this->intentos[$cartId] ?? null;
    }
}
