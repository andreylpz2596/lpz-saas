<?php

namespace ByLopez\Logistica\Enums;

/**
 * Concepto de un movimiento de la CUENTA CORRIENTE de una transportadora (custodia/obligacion transportador ↔ ByLopez).
 *
 * Convencion UNICA de signo (importe firmado, una sola columna `importe`):
 *   POSITIVO (+) = la transportadora debe dinero a ByLopez.
 *   NEGATIVO (-) = ByLopez debe dinero a la transportadora.
 *
 * - recaudo: dinero del pedido que el transportador cobro al cliente (+). Nace de la ENTREGA, nunca del despacho.
 * - servicio: costo del domicilio que ByLopez le paga al transportador (-). Cubre tambien el «flete recibido por ByLopez»
 *   (envio cobrado en el pedido): es el MISMO dinero, por eso no existe un segundo concepto que lo duplique.
 * - costo_devolucion: servicio por una devolucion (-). Solo por registro explicito autorizado (no hay tarifa aprobada).
 * - ajuste: correccion manual del Administrador (+ o -), con motivo.
 * - pago_liquidacion: el dinero que se entrego al liquidar (cierra el saldo dentro de la liquidacion).
 * - diferencia_liquidacion: lo que no cuadro en una liquidacion; se cierra en ella y se abre en la cuenta siguiente.
 */
enum ConceptoMovimientoCuenta: string
{
    case RECAUDO = 'recaudo';
    case SERVICIO = 'servicio';
    case COSTO_DEVOLUCION = 'costo_devolucion';
    case AJUSTE = 'ajuste';
    case PAGO_LIQUIDACION = 'pago_liquidacion';
    case DIFERENCIA_LIQUIDACION = 'diferencia_liquidacion';

    /** Grupo del resumen: recaudos | servicios | ajustes | pagos. */
    public function grupo(): string
    {
        return match ($this) {
            self::RECAUDO => 'recaudos',
            self::SERVICIO, self::COSTO_DEVOLUCION => 'servicios',
            self::AJUSTE, self::DIFERENCIA_LIQUIDACION => 'ajustes',
            self::PAGO_LIQUIDACION => 'pagos',
        };
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
