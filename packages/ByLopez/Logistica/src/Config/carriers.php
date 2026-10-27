<?php

use ByLopez\Logistica\Carriers\DomicilioLocal;
use ByLopez\Logistica\Carriers\EnvioNacional;

/*
 * Metodos de entrega de ByLopez (D-40). Metodo de entrega != transportadora: la
 * transportadora efectiva se decide al despachar (fase de logistica).
 *
 * Son los VALORES POR DEFECTO de Bagisto: sin fila en core_config,
 * core()->getConfigData('sales.carriers.<code>.<campo>') devuelve este valor. Todos
 * nacen inactivos; titulos, descripciones y tarifas se cambian desde el panel.
 *
 * El envio gratis NO es un metodo: es una promocion con CartRule (free_shipping).
 *
 * La RECOGIDA ya no es de ByLopez: la da la extension de Bagisto bagisto-eu/pickup (carrier
 * `pickup`). La regla "recogida solo con envio a una bodega real" (D-41) vive en
 * Support\PuntosRecogida::metodoPermitido().
 */
return [
    // Sin tarifa configurada no se ofrece: la tarifa no se inventa.
    'domicilio_local' => [
        'code' => 'domicilio_local',
        'title' => 'Domicilio local',
        'description' => 'Entrega por domiciliario en la ciudad.',
        'active' => false,
        'default_rate' => null,
        'class' => DomicilioLocal::class,
    ],

    // Operacion inicial: flete por pagar a la transportadora al recibir, asi que ByLopez
    // cobra 0. NO es envio gratis (eso seria una CartRule con free_shipping).
    'envio_nacional' => [
        'code' => 'envio_nacional',
        'title' => 'Envío nacional',
        'description' => 'Flete por pagar a la transportadora al recibir.',
        'active' => false,
        'default_rate' => '0',
        'class' => EnvioNacional::class,
    ],
];
