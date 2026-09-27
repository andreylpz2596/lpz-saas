<?php

return [
    'carriers' => [
        'recogida_bodega' => [
            'nombre' => 'Warehouse pickup (ByLopez)',
            'info' => 'The customer picks up at an active warehouse of the channel. Rate 0 by nature of the method.',
        ],

        'domicilio_local' => [
            'nombre' => 'Local delivery (ByLopez)',
            'info' => 'Delivery by courier. Not offered without a configured rate.',
        ],

        'envio_nacional' => [
            'nombre' => 'National shipping (ByLopez)',
            'info' => 'Shipping by carrier. The carrier is chosen when dispatching.',
        ],
    ],

    'configuracion' => [
        'titulo' => 'Title',
        'descripcion' => 'Description',
        'tarifa' => 'Rate per order',
        'tarifa-info' => 'Amount charged by ByLopez. 0 if freight is paid separately (not free shipping: promotions belong in cart rules).',
        'estado' => 'Active',
    ],
];
