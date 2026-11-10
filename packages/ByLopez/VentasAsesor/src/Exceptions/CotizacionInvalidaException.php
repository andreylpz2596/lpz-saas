<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/** Una cotizacion fue rechazada por una regla del dominio. `codigo` es estable (UI, API, pruebas); nada queda escrito. */
class CotizacionInvalidaException extends RuntimeException
{
    public const BORRADOR_INEXISTENTE = 'borrador_inexistente';

    public const COTIZACION_INEXISTENTE = 'cotizacion_inexistente';

    public const SIN_PERMISO = 'sin_permiso';

    /** Solo se cotiza una venta en curso: confirmada o descartada ya no admite cotizacion nueva. */
    public const BORRADOR_NO_ACTIVO = 'borrador_no_activo';

    public const SIN_CLIENTE = 'sin_cliente';

    public const SIN_LINEAS = 'sin_lineas';

    private const MENSAJES = [
        self::BORRADOR_INEXISTENTE => 'La venta en curso no existe.',
        self::COTIZACION_INEXISTENTE => 'La cotizacion no existe.',
        self::SIN_PERMISO => 'No tienes permiso para cotizar esta venta.',
        self::BORRADOR_NO_ACTIVO => 'Solo se puede cotizar una venta en curso.',
        self::SIN_CLIENTE => 'Elige el cliente antes de cotizar.',
        self::SIN_LINEAS => 'Agrega al menos un producto antes de cotizar.',
    ];

    public function __construct(public readonly string $codigo)
    {
        parent::__construct(self::MENSAJES[$codigo] ?? 'Cotizacion no valida: '.$codigo);
    }

    public function estadoHttp(): int
    {
        return match ($this->codigo) {
            self::SIN_PERMISO => 403,
            self::BORRADOR_INEXISTENTE, self::COTIZACION_INEXISTENTE => 404,
            self::BORRADOR_NO_ACTIVO => 409,
            default => 422,
        };
    }
}
