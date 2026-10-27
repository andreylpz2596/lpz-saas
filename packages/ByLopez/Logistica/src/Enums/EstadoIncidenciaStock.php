<?php

namespace ByLopez\Logistica\Enums;

/** solicitada → regularizada (el inventario se corrigio al conteo fisico) | rechazada (no se toco nada). Se transita una sola vez. */
enum EstadoIncidenciaStock: string
{
    case SOLICITADA = 'solicitada';
    case REGULARIZADA = 'regularizada';
    case RECHAZADA = 'rechazada';
}
