<?php

namespace ByLopez\Logistica\Enums;

/**
 * Clasificacion del catalogo (LOG-D-04): QUE CLASE de transportador es. No decide comportamiento: eso lo hacen las
 * CAPACIDADES de la transportadora (`liquida_cuenta_corriente`, `genera_guia_interna`), independientes del tipo.
 */
enum TipoTransportadora: string
{
    case PROPIA = 'propia';
    case TRANSPORTADORA = 'transportadora';
    case DOMICILIARIO = 'domiciliario';
    case PLATAFORMA = 'plataforma';
    case RECOGIDA = 'recogida';
    case OTRA = 'otra';
}
