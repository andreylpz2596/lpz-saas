<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Clasificacion de un borrador frente a su confirmacion (03F, Doc. 05 §38.7). Se calcula
 * con la evidencia persistida (estado, order_id, cart_id, lease y orders.cart_id); nunca
 * repara nada por su cuenta.
 */
enum EstadoReconciliacionConfirmacion: string
{
    /** `confirmado` con `order_id`: se devuelve ese pedido. */
    case YA_CONFIRMADO = 'ya_confirmado';

    /** `confirmando` sin pedido y con lease vigente. */
    case CONFIRMACION_EN_PROCESO = 'confirmacion_en_proceso';

    /** `confirmando` sin pedido y lease vencido: otro intento puede tomarlo con token nuevo. */
    case RECUPERABLE = 'recuperable';

    /** Evidencia contradictoria (ver motivo). Revision humana. */
    case INCONSISTENTE = 'inconsistente';

    /** `activo` sin anomalias: puede iniciar una confirmacion. */
    case CONFIRMABLE = 'confirmable';

    /** `descartado`: final, nunca se confirma. */
    case DESCARTADO = 'descartado';
}
