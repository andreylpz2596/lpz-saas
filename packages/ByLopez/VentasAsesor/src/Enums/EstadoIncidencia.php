<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Estado de una incidencia de atribucion. Es una cola OPERACIONAL y mutable, a
 * diferencia del historial de propiedad comercial, que es inmutable.
 */
enum EstadoIncidencia: string
{
    case ABIERTA = 'open';

    case RESUELTA = 'resolved';
}
