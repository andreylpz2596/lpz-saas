<?php

/*
 * Configuracion > Ventas Asesor > Cotizaciones: lo que el administrador edita sin tocar codigo (datos de pago, condiciones,
 * vigencia y encabezado del PDF). Se lee con ByLopez\VentasAsesor\Support\Cotizaciones\AjustesCotizacion; un campo vacio simplemente no sale en el PDF.
 */
$texto = fn (string $name, string $title, ?string $default = null, ?string $info = null, string $validation = 'max:191') => array_filter([
    'name' => $name,
    'title' => $title,
    'info' => $info,
    'type' => 'text',
    'default' => $default,
    'validation' => $validation,
    'channel_based' => false,
    'locale_based' => false,
], fn ($v) => $v !== null);

return [
    [
        'key' => 'ventas_asesor',
        'name' => 'ventasasesor::app.cotizacion.config.titulo',
        'info' => 'ventasasesor::app.cotizacion.config.info',
        'sort' => 20,
    ], [
        'key' => 'ventas_asesor.cotizacion',
        'name' => 'ventasasesor::app.cotizacion.config.cotizaciones',
        'info' => 'ventasasesor::app.cotizacion.config.cotizaciones-info',
        'icon' => 'settings/store.svg',
        'sort' => 1,
    ], [
        'key' => 'ventas_asesor.cotizacion.encabezado',
        'name' => 'ventasasesor::app.cotizacion.config.encabezado',
        'info' => 'ventasasesor::app.cotizacion.config.encabezado-info',
        'sort' => 1,
        'fields' => [
            $texto('empresa_nombre', 'ventasasesor::app.cotizacion.config.empresa-nombre', 'LÓPEZ LÓPEZ', null, 'required|max:60'),
            $texto('empresa_detalle', 'ventasasesor::app.cotizacion.config.empresa-detalle', null, 'ventasasesor::app.cotizacion.config.empresa-detalle-info'),
            $texto('empresa_contacto', 'ventasasesor::app.cotizacion.config.empresa-contacto', null, 'ventasasesor::app.cotizacion.config.empresa-contacto-info'),
        ],
    ], [
        'key' => 'ventas_asesor.cotizacion.pago',
        'name' => 'ventasasesor::app.cotizacion.config.pago',
        'info' => 'ventasasesor::app.cotizacion.config.pago-info',
        'sort' => 2,
        'fields' => [
            $texto('nequi_numero', 'ventasasesor::app.cotizacion.config.nequi-numero', '3157941278', 'ventasasesor::app.cotizacion.config.nequi-numero-info', 'max:30'),
            $texto('nequi_titular', 'ventasasesor::app.cotizacion.config.nequi-titular'),
            $texto('llaves', 'ventasasesor::app.cotizacion.config.llaves', null, 'ventasasesor::app.cotizacion.config.llaves-info'),
            $texto('banco_nombre', 'ventasasesor::app.cotizacion.config.banco-nombre', 'Bancolombia'),
            $texto('banco_tipo_cuenta', 'ventasasesor::app.cotizacion.config.banco-tipo-cuenta', null, 'ventasasesor::app.cotizacion.config.banco-tipo-cuenta-info', 'max:40'),
            $texto('banco_numero', 'ventasasesor::app.cotizacion.config.banco-numero', null, null, 'max:40'),
            $texto('banco_titular', 'ventasasesor::app.cotizacion.config.banco-titular'),
            $texto('otros_medios', 'ventasasesor::app.cotizacion.config.otros-medios', null, 'ventasasesor::app.cotizacion.config.otros-medios-info'),
        ],
    ], [
        'key' => 'ventas_asesor.cotizacion.condiciones',
        'name' => 'ventasasesor::app.cotizacion.config.condiciones',
        'info' => 'ventasasesor::app.cotizacion.config.condiciones-info',
        'sort' => 3,
        'fields' => [
            [
                'name' => 'vigencia_dias',
                'title' => 'ventasasesor::app.cotizacion.config.vigencia-dias',
                'info' => 'ventasasesor::app.cotizacion.config.vigencia-dias-info',
                'type' => 'number',
                'default' => 7,
                'validation' => 'required|integer|min:1|max:90',
                'channel_based' => false,
                'locale_based' => false,
            ], [
                'name' => 'condiciones',
                'title' => 'ventasasesor::app.cotizacion.config.condiciones-lista',
                'info' => 'ventasasesor::app.cotizacion.config.condiciones-lista-info',
                'type' => 'textarea',
                'default' => "Precios en pesos colombianos (COP).\nEl domicilio no está incluido en el total y se cobra por aparte.\nDisponibilidad sujeta a inventario al momento del pago.\nSe confirma el pedido con el pago (total o anticipo).",
                'validation' => 'max:1500',
                'channel_based' => false,
                'locale_based' => false,
            ], [
                'name' => 'aviso',
                'title' => 'ventasasesor::app.cotizacion.config.aviso',
                'type' => 'textarea',
                'default' => 'Esta cotización no es una factura ni reserva inventario. Para continuar, responde este mensaje con tu confirmación y enviaremos los datos de pago.',
                'validation' => 'max:500',
                'channel_based' => false,
                'locale_based' => false,
            ],
        ],
    ], [
        // Mensajes de WhatsApp al cliente (texto para copiar y pegar). Plantillas controladas por codigo: solo emojis y frase final.
        'key' => 'ventas_asesor.mensajes',
        'name' => 'ventasasesor::app.mensajes.config.titulo',
        'info' => 'ventasasesor::app.mensajes.config.info',
        'icon' => 'settings/store.svg',
        'sort' => 2,
    ], [
        'key' => 'ventas_asesor.mensajes.general',
        'name' => 'ventasasesor::app.mensajes.config.general',
        'info' => 'ventasasesor::app.mensajes.config.general-info',
        'sort' => 1,
        'fields' => [
            [
                'name' => 'usar_emojis',
                'title' => 'ventasasesor::app.mensajes.config.usar-emojis',
                'info' => 'ventasasesor::app.mensajes.config.usar-emojis-info',
                'type' => 'boolean',
                'default' => true,
                'channel_based' => false,
                'locale_based' => false,
            ],
            $texto('frase_final', 'ventasasesor::app.mensajes.config.frase-final', null, 'ventasasesor::app.mensajes.config.frase-final-info', 'max:200'),
        ],
    ],
];
