<?php

return [
    'carriers' => [
        'recogida_bodega' => [
            'nombre' => 'Recogida en bodega (ByLopez)',
            'info' => 'El cliente recoge en una bodega activa del canal. Tarifa 0 por naturaleza del método.',
        ],

        'domicilio_local' => [
            'nombre' => 'Domicilio local (ByLopez)',
            'info' => 'Entrega por domiciliario. Sin tarifa configurada no se ofrece.',
        ],

        'envio_nacional' => [
            'nombre' => 'Envío nacional (ByLopez)',
            'info' => 'Envío por transportadora. La transportadora se elige al despachar.',
        ],
    ],

    'configuracion' => [
        'titulo' => 'Título',
        'descripcion' => 'Descripción',
        'tarifa' => 'Tarifa por pedido',
        'tarifa-info' => 'Valor cobrado por ByLopez. 0 si el flete se paga por fuera (no es envío gratis: las promociones van en reglas de carrito).',
        'estado' => 'Activo',
    ],
];
