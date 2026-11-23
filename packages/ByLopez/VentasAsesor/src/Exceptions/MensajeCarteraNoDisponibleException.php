<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/** El estado de cuenta de WhatsApp no se puede generar. `codigo` es estable (UI, API, pruebas); nada queda escrito. */
class MensajeCarteraNoDisponibleException extends RuntimeException
{
    public const SIN_PERMISO = 'sin_permiso';

    public const CLIENTE_INEXISTENTE = 'cliente_inexistente';

    /** Cartera inactiva o una clave que no existe. */
    public const MENSAJE_NO_DISPONIBLE = 'mensaje_no_disponible';

    private const MENSAJES = [
        self::SIN_PERMISO => 'El estado de cuenta solo lo genera quien ve toda la cartera del cliente.',
        self::CLIENTE_INEXISTENTE => 'El cliente no existe.',
        self::MENSAJE_NO_DISPONIBLE => 'El estado de cuenta no está disponible (Cartera inactiva).',
    ];

    public function __construct(public readonly string $codigo)
    {
        parent::__construct(self::MENSAJES[$codigo] ?? 'Mensaje no disponible: '.$codigo);
    }

    public function estadoHttp(): int
    {
        return match ($this->codigo) {
            self::SIN_PERMISO => 403,
            self::CLIENTE_INEXISTENTE => 404,
            default => 409,
        };
    }
}
