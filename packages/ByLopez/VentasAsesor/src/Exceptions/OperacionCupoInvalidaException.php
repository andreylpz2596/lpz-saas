<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Rechazo de una operacion sobre el cupo de cartera de un cliente (Doc. 35): administrar la politica o consultar su historial.
 * Codigos estables; el controlador los traduce a HTTP sin repetir ninguna regla.
 *
 * El rechazo de una VENTA por cupo NO es esta excepcion: lo hace el cerco de creacion de pedidos
 * (PedidoRechazadoPorCercoException::CUPO_*) para que ocurra dentro de la transaccion del pedido.
 */
class OperacionCupoInvalidaException extends RuntimeException
{
    /** Sin la hoja ACL o sin ser el administrador autorizado (un rol `all` cualquiera no basta). */
    public const SIN_PERMISO = 'cupo_sin_permiso';

    public const CLIENTE_INEXISTENTE = 'cupo_cliente_inexistente';

    /** Monto no numerico, negativo, con mas de 2 decimales o fuera de rango. */
    public const MONTO_INVALIDO = 'cupo_monto_invalido';

    public const ESTADO_INVALIDO = 'cupo_estado_invalido';

    public const MOTIVO_REQUERIDO = 'cupo_motivo_requerido';

    /** El cupo y el estado pedidos ya son los vigentes: no se escribe una fila de historial vacia. */
    public const SIN_CAMBIOS = 'cupo_sin_cambios';

    /** Otra persona cambio el cupo mientras se editaba (control optimista por valores esperados). */
    public const CAMBIO_CONCURRENTE = 'cupo_cambio_concurrente';

    /** Historial de solo adicion: no se edita ni se borra. */
    public const INMUTABLE = 'cupo_historial_inmutable';

    /** Un cliente BLOQUEADO no admite excepcion de monto: primero se desbloquea (cambio de cupo auditado). */
    public const CLIENTE_BLOQUEADO = 'cupo_cliente_bloqueado';

    /** Ya hay una autorizacion de exceso vigente para el cliente: se consume o se anula antes de dar otra. */
    public const EXCESO_VIGENTE_EXISTENTE = 'cupo_exceso_vigente_existente';

    public const EXCESO_INEXISTENTE = 'cupo_exceso_inexistente';

    /** Solo una autorizacion VIGENTE se puede anular (la consumida es historia; la vencida ya no sirve). */
    public const EXCESO_NO_ANULABLE = 'cupo_exceso_no_anulable';

    /** @param  array<string, mixed>  $detalles */
    public function __construct(
        public readonly string $codigo,
        string $mensaje = '',
        public readonly array $detalles = [],
    ) {
        parent::__construct($mensaje !== '' ? $mensaje : 'Operacion de cupo no valida: '.$codigo);
    }

    public function estadoHttp(): int
    {
        return match ($this->codigo) {
            self::SIN_PERMISO => 403,
            self::CLIENTE_INEXISTENTE => 404,
            self::CAMBIO_CONCURRENTE, self::SIN_CAMBIOS => 409,
            default => 422,
        };
    }
}
