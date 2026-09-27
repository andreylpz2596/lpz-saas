<?php

use ByLopez\Logistica\Carriers\DomicilioLocal;
use ByLopez\Logistica\Carriers\EnvioNacional;
use ByLopez\Logistica\Carriers\RecogidaBodega;

/*
 * Metodos de entrega de ByLopez (D-40). Metodo de entrega != transportadora: la
 * transportadora efectiva se decide al despachar (fase de logistica).
 *
 * Son los VALORES POR DEFECTO de Bagisto: sin fila en core_config,
 * core()->getConfigData('sales.carriers.<code>.<campo>') devuelve este valor. Todos
 * nacen inactivos; titulos, descripciones y tarifas se cambian desde el panel.
 *
 * El envio gratis NO es un metodo: es una promocion con CartRule (free_shipping).
 */
return [
    // Tarifa 0: costo natural del metodo, no una promocion.
    'recogida_bodega' => [
        'code' => 'recogida_bodega',
        'title' => 'Recogida en bodega',
        'description' => 'El cliente recoge el pedido en la bodega.',
        'active' => false,
        'class' => RecogidaBodega::class,
    ],

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
