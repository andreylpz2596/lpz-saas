<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Por donde llego el cliente [M-§6.1].
 *
 * Nunca se deriva de orders.channel_id, que es el canal/tienda de Bagisto.
 * DESCONOCIDO se agrega en la Fase 02A para pedidos historicos sin evidencia:
 * es distinto de OTRO, que si es una eleccion informada.
 */
enum CanalVenta: string
{
    case WHATSAPP = 'whatsapp';

    case ECOMMERCE = 'ecommerce';

    case TELEFONO = 'telefono';

    case TIENDA_FISICA = 'tienda_fisica';

    case OTRO = 'otro';

    case DESCONOCIDO = 'desconocido';
}
