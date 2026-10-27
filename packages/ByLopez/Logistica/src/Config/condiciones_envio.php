<?php

/*
 * Domicilios locales F1 — condicion COMERCIAL del envio por codigo de metodo (`orders.shipping_method`). Fuente unica:
 * ningun otro archivo repite esta tabla. Un metodo que no este aqui es `sin_definir` (no se adivina por el texto ni por
 * el monto). Ademas, una CartRule de envio gratis (descuento de envio = tarifa) convierte cualquier metodo en `gratis`
 * (ver Support\CondicionesEnvio).
 */
return [
    'free_free' => 'gratis',
    'flatrate_flatrate' => 'por_pagar',
    'envio_nacional' => 'por_pagar',
    'domicilio_local' => 'cobrado_en_pedido',
    'pickup' => 'recogida',
];
