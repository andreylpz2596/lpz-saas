<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * COMISION POTENCIAL DIAGNOSTICA de un pedido (COM-03). NO es una comision: no esta causada, no se puede pagar
 * ni liquidar, y no se guarda. Es una estimacion informativa:
 *
 *   monto_diagnostico = utilidad_diagnostica × porcentaje de la regla vigente
 *
 * Los tres indicadores `causada`, `pagable` y `liquidable` son SIEMPRE false en este objeto, por construccion:
 * una comision real solo nacera en COM-04 como linea persistida (entrega confirmada + pago verificado + costo
 * con autoridad). `motivosNoPagable` explica por que.
 */
final class ComisionPotencialDiagnostica
{
    public const MOTIVO_COSTO_NO_AUTORIZADO = 'costo_no_autorizado';

    public const MOTIVO_LINEAS_SIN_COSTO = 'lineas_sin_costo';

    public const MOTIVO_SIN_SNAPSHOT = 'pedido_sin_snapshot';

    public const MOTIVO_PROPIEDAD_NO_ASESOR = 'propiedad_no_asesor';

    public const MOTIVO_SIN_REGLA = 'sin_regla_vigente';

    public const MOTIVO_ENTREGA_NO_IMPLEMENTADA = 'entrega_no_implementada';

    public const MOTIVO_PAGO_NO_EVALUADO = 'pago_no_evaluado';

    public readonly bool $esDiagnostica;

    public readonly bool $causada;

    public readonly bool $pagable;

    public readonly bool $liquidable;

    /**
     * @param  array<int, ?float>  $montoPorLinea  order_item_id → monto diagnostico (null si la linea no tiene utilidad)
     * @param  list<string>  $motivosNoPagable
     */
    public function __construct(
        public readonly int $orderId,
        public readonly ?int $advisorId,
        public readonly ?string $propiedad,
        public readonly ?int $reglaId,
        public readonly ?float $porcentaje,
        public readonly float $utilidadDiagnostica,
        /** null si no aplica (sin regla o venta que no es de asesor). */
        public readonly ?float $montoDiagnostico,
        public readonly array $montoPorLinea,
        public readonly array $motivosNoPagable,
    ) {
        $this->esDiagnostica = true;
        $this->causada = false;
        $this->pagable = false;
        $this->liquidable = false;
    }
}
