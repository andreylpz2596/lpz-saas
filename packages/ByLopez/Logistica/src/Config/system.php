<?php

/*
 * Campos del panel (Configuracion > Ventas > Metodos de envio) para los carriers de
 * ByLopez, con la misma forma que los de flatrate en Admin/Config/system.php.
 */
$titulo = [
    'name' => 'title',
    'title' => 'logistica::app.configuracion.titulo',
    'type' => 'text',
    'depends' => 'active:1',
    'validation' => 'required_if:active,1',
    'channel_based' => true,
    'locale_based' => true,
];

$descripcion = [
    'name' => 'description',
    'title' => 'logistica::app.configuracion.descripcion',
    'type' => 'textarea',
    'channel_based' => true,
    'locale_based' => true,
];

$tarifa = [
    'name' => 'default_rate',
    'title' => 'logistica::app.configuracion.tarifa',
    'info' => 'logistica::app.configuracion.tarifa-info',
    'type' => 'text',
    'depends' => 'active:1',
    'validation' => 'required_if:active,1|numeric|min:0',
    'channel_based' => true,
    'locale_based' => false,
];

$estado = [
    'name' => 'active',
    'title' => 'logistica::app.configuracion.estado',
    'type' => 'boolean',
    'channel_based' => true,
    'locale_based' => false,
];

return [
    [
        'key' => 'sales.carriers.recogida_bodega',
        'name' => 'logistica::app.carriers.recogida_bodega.nombre',
        'info' => 'logistica::app.carriers.recogida_bodega.info',
        'sort' => 3,
        'fields' => [$titulo, $descripcion, $estado],
    ], [
        'key' => 'sales.carriers.domicilio_local',
        'name' => 'logistica::app.carriers.domicilio_local.nombre',
        'info' => 'logistica::app.carriers.domicilio_local.info',
        'sort' => 4,
        'fields' => [$titulo, $descripcion, $tarifa, $estado],
    ], [
        'key' => 'sales.carriers.envio_nacional',
        'name' => 'logistica::app.carriers.envio_nacional.nombre',
        'info' => 'logistica::app.carriers.envio_nacional.info',
        'sort' => 5,
        'fields' => [$titulo, $descripcion, $tarifa, $estado],
    ],
];
