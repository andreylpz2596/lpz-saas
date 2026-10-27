<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Un pago NO se pudo confirmar ni rechazar (PAG-E, Doc. 05 §42.5). Cada causa tiene un `codigo` estable y un estado
 * HTTP propio; ninguna deja escrito nada (la operacion es atomica). Los `detalles` son datos para mostrar, no reglas.
 */
class RevisionPagoInvalidaException extends RuntimeException
{
    public const SIN_PERMISO = 'sin_permiso';

    public const PAGO_INEXISTENTE = 'pago_inexistente';

    public const CARTERA_INACTIVA = 'cartera_inactiva';

    /** El pago no esta `sin_revisar` (ya fue rechazado o reversado, o se intenta rechazar uno confirmado). */
    public const ESTADO_NO_REVISABLE = 'estado_no_revisable';

    /** Confirmado → rechazado es un REVERSO (PAG-I), no un rechazo. */
    public const PAGO_CONFIRMADO_NO_RECHAZABLE = 'pago_confirmado_no_rechazable';

    public const APLICACIONES_INCONSISTENTES = 'aplicaciones_inconsistentes';

    public const PEDIDO_NO_CONFIRMABLE = 'pedido_no_confirmable';

    public const SIN_FACTURA_DISPONIBLE = 'sin_factura_disponible';

    public const METODO_PAGO_PEDIDO_INVALIDO = 'metodo_pago_pedido_invalido';

    public const MOTIVO_OBLIGATORIO = 'motivo_obligatorio';

    public const CATEGORIA_INVALIDA = 'categoria_invalida';

    /**
     * @param  array<string, mixed>  $detalles
     */
    public function __construct(
        public readonly string $codigo,
        string $mensaje,
        public readonly array $detalles = [],
    ) {
        parent::__construct($mensaje);
    }

    public function estadoHttp(): int
    {
        return match ($this->codigo) {
            self::SIN_PERMISO => 403,
            self::PAGO_INEXISTENTE => 404,
            self::ESTADO_NO_REVISABLE, self::PAGO_CONFIRMADO_NO_RECHAZABLE, self::APLICACIONES_INCONSISTENTES => 409,
            default => 422,
        };
    }
}
