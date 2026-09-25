<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Como se decidio la propiedad comercial. Sirve para auditar por que una venta
 * quedo de un asesor, de la empresa o sin asignar.
 */
enum OrigenPropiedadComercial: string
{
    /** El creador es asesor por permiso explicito y se asigno a si mismo (regla B). */
    case AUTO_ASESOR = 'auto_asesor';

    /** Alguien eligio expresamente empresa, a si mismo u otro asesor (regla C). */
    case SELECCION_EXPLICITA = 'seleccion_explicita';

    /** Preferencia comercial del cliente; el pedido guarda su decision historica. */
    case PREFERENCIA_CLIENTE = 'preferencia_cliente';

    /** Pedido creado por el cliente en la tienda: pertenece a la empresa (regla A). */
    case REGLA_STOREFRONT = 'regla_storefront';

    /** No habia contexto comercial: queda sin asignar (reglas C y D). */
    case SIN_CONTEXTO = 'sin_contexto';

    /** La propiedad cambio despues, con motivo e historial. */
    case REASIGNACION = 'reasignacion';
}
