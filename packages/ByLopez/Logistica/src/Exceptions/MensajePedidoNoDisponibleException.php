<?php

namespace ByLopez\Logistica\Exceptions;

use RuntimeException;

/** Un mensaje al cliente no se puede generar. `codigo` es estable (UI, API, pruebas); nada queda escrito. */
class MensajePedidoNoDisponibleException extends RuntimeException
{
    public const SIN_PERMISO = 'sin_permiso';

    public const PEDIDO_INEXISTENTE = 'pedido_inexistente';

    /** La clave pedida no corresponde al estado actual del pedido (p. ej. «entregado» sin entrega validada). */
    public const MENSAJE_NO_DISPONIBLE = 'mensaje_no_disponible';

    private const MENSAJES = [
        self::SIN_PERMISO => 'No tienes permiso para generar mensajes de este pedido.',
        self::PEDIDO_INEXISTENTE => 'El pedido no existe.',
        self::MENSAJE_NO_DISPONIBLE => 'Ese mensaje ya no corresponde al estado actual del pedido. Vuelve a abrir la lista.',
    ];

    public function __construct(public readonly string $codigo)
    {
        parent::__construct(self::MENSAJES[$codigo] ?? 'Mensaje no disponible: '.$codigo);
    }

    public function estadoHttp(): int
    {
        return match ($this->codigo) {
            self::SIN_PERMISO => 403,
            self::PEDIDO_INEXISTENTE => 404,
            default => 409,
        };
    }
}
