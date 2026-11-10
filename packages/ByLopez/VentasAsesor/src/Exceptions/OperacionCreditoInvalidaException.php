<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Una operacion de credito del cliente (D10 V1) fue rechazada. `codigo` es estable (pruebas, UI, API); `detalles` es contexto
 * para mostrar (montos, pedidos). Ninguna causa deja escrito nada: las operaciones son atomicas.
 */
class OperacionCreditoInvalidaException extends RuntimeException
{
    public const SIN_PERMISO = 'sin_permiso';

    public const INMUTABLE = 'registro_inmutable';

    public const CARTERA_INACTIVA = 'cartera_inactiva';

    public const PEDIDO_INEXISTENTE = 'pedido_inexistente';

    public const CLIENTE_DISTINTO = 'cliente_distinto';

    public const MISMO_PEDIDO = 'mismo_pedido';

    public const MONTO_INVALIDO = 'monto_invalido';

    public const CREDITO_INSUFICIENTE = 'credito_insuficiente';

    public const CREDITO_ORIGEN_SIN_RECEPCION = 'credito_origen_sin_recepcion';

    public const CREDITO_EN_CUSTODIA = 'credito_origen_en_custodia';

    public const DESTINO_NO_ADMITE = 'destino_no_admite';

    public const MONTO_EXCEDE_SALDO = 'monto_excede_saldo';

    public const RECAUDO_COMPROMETIDO = 'recaudo_comprometido';

    public const MOTIVO_REQUERIDO = 'motivo_requerido';

    public const CLAVE_INVALIDA = 'clave_idempotencia_invalida';

    public const IDEMPOTENCIA_CONFLICTO = 'idempotencia_en_conflicto';

    public const MOVIMIENTO_INEXISTENTE = 'movimiento_inexistente';

    public const NO_REVERSABLE = 'no_reversable';

    public const YA_REVERSADO = 'ya_reversado';

    public const MEDIO_INVALIDO = 'medio_devolucion_invalido';

    public const REFERENCIA_REQUERIDA = 'referencia_requerida';

    public const CAUSA_INVALIDA = 'causa_invalida';

    public const AJUSTE_EXCEDE_FLETE = 'ajuste_excede_flete';

    public const AJUSTE_INEXISTENTE = 'ajuste_inexistente';

    public const AJUSTE_YA_REVERSADO = 'ajuste_ya_reversado';

    public const REVERSO_DEJA_CREDITO_NEGATIVO = 'reverso_deja_credito_negativo';

    public const CREDITO_APLICADO_ACTIVO = 'credito_aplicado_activo';

    public const CREDITO_CONSUMIDO = 'credito_consumido';

    /** @param  array<string, mixed>  $detalles */
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
            self::PEDIDO_INEXISTENTE, self::MOVIMIENTO_INEXISTENTE, self::AJUSTE_INEXISTENTE => 404,
            self::IDEMPOTENCIA_CONFLICTO, self::CREDITO_INSUFICIENTE, self::CREDITO_ORIGEN_SIN_RECEPCION, self::CREDITO_EN_CUSTODIA,
            self::YA_REVERSADO, self::AJUSTE_YA_REVERSADO, self::NO_REVERSABLE, self::RECAUDO_COMPROMETIDO, self::MONTO_EXCEDE_SALDO,
            self::REVERSO_DEJA_CREDITO_NEGATIVO, self::CREDITO_APLICADO_ACTIVO, self::CREDITO_CONSUMIDO => 409,
            default => 422,
        };
    }
}
