<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Un pago NO se registro (PAG-C, Doc. 05 §42.5). Cada causa tiene un `codigo` estable y un estado HTTP
 * propio; ninguna deja escrito nada (la operacion es atomica). Los `detalles` son datos para mostrar al
 * usuario (p. ej. el saldo vigente), nunca reglas.
 */
class RegistroPagoInvalidoException extends RuntimeException
{
    public const SIN_PERMISO = 'sin_permiso';

    public const PEDIDO_INEXISTENTE = 'pedido_inexistente';

    public const CARTERA_INACTIVA = 'cartera_inactiva';

    public const PEDIDO_FUERA_DE_CARTERA = 'pedido_fuera_de_cartera';

    public const PEDIDO_SIN_CLIENTE = 'pedido_sin_cliente';

    public const PEDIDO_REQUIERE_REVISION = 'pedido_requiere_revision';

    public const MONTO_INVALIDO = 'monto_invalido';

    public const MONTO_EXCEDE_SALDO = 'monto_excede_saldo';

    public const MEDIO_COBRO_INVALIDO = 'medio_cobro_invalido';

    public const REFERENCIA_REQUERIDA = 'referencia_requerida';

    public const REFERENCIA_NO_ADMITIDA = 'referencia_no_admitida';

    public const FECHA_PAGO_INVALIDA = 'fecha_pago_invalida';

    public const CLAVE_IDEMPOTENCIA_INVALIDA = 'clave_idempotencia_invalida';

    public const IDEMPOTENCIA_EN_CONFLICTO = 'idempotencia_en_conflicto';

    public const PLATAFORMA_REQUERIDA = 'plataforma_requerida';

    public const PLATAFORMA_INVALIDA = 'plataforma_invalida';

    public const PAGO_COMBINADO_INVALIDO = 'pago_combinado_invalido';

    // PAG-D (abono general multipedido).
    public const CLIENTE_INEXISTENTE = 'cliente_inexistente';

    public const DISTRIBUCION_INVALIDA = 'distribucion_invalida';

    public const PEDIDO_DE_OTRO_CLIENTE = 'pedido_de_otro_cliente';

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
            self::PEDIDO_INEXISTENTE, self::CLIENTE_INEXISTENTE => 404,
            self::IDEMPOTENCIA_EN_CONFLICTO => 409,
            default => 422,
        };
    }
}
