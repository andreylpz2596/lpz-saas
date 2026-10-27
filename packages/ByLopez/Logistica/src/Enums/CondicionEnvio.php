<?php

namespace ByLopez\Logistica\Enums;

/**
 * Condicion COMERCIAL del envio de un pedido, deducida de `orders.shipping_method` (nunca del monto: `free_free` y
 * `flatrate_flatrate` cuestan 0 los dos). De ella sale quien asume el costo del domicilio.
 *
 * - gratis: el cliente no paga envio; ByLopez asume el costo real.
 * - por_pagar: el flete esta FUERA del pedido; el cliente lo paga directo a quien entrega.
 * - cobrado_en_pedido: el envio esta DENTRO del grand_total; ByLopez le paga al transportador.
 * - recogida: el cliente retira en bodega (flujo «Entregar en bodega», sin despacho).
 * - sin_definir: metodo desconocido; no se adivina: la operaria elige el pagador con motivo.
 */
enum CondicionEnvio: string
{
    case GRATIS = 'gratis';
    case POR_PAGAR = 'por_pagar';
    case COBRADO_EN_PEDIDO = 'cobrado_en_pedido';
    case RECOGIDA = 'recogida';
    case SIN_DEFINIR = 'sin_definir';

    /** Pagador del flete que corresponde a la condicion; null = no se puede deducir (hay que elegir con motivo). */
    public function pagador(): ?PagadorFlete
    {
        return match ($this) {
            self::GRATIS, self::COBRADO_EN_PEDIDO => PagadorFlete::BYLOPEZ,
            self::POR_PAGAR => PagadorFlete::CLIENTE_AL_RECIBIR,
            self::RECOGIDA, self::SIN_DEFINIR => null,
        };
    }
}
