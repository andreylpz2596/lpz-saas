<?php

namespace ByLopez\Logistica\Enums;

/**
 * F6: como se resuelve el despacho de un pedido que NO es contra entrega y aun debe dinero. «Esperar el pago confirmado» no
 * es una fila: es la ausencia de decision (el despacho queda bloqueado hasta que Cartera tenga el pago confirmado).
 */
enum TipoDisposicionCobro: string
{
    /** La guia lleva el saldo como recaudo (regla C1 por guia); el metodo original del pedido no cambia. */
    case COBRAR_AL_ENTREGAR = 'cobrar_al_entregar';

    /** Sale sin recaudo: credito autorizado por alguien con `logistica.despachos.autorizar_credito`, con motivo. */
    case CREDITO_AUTORIZADO = 'credito_autorizado';
}
