<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Tipo de linea deducido por las reglas del pais. No se persiste: se recalcula
 * normalizando de nuevo cuando haga falta.
 */
enum TipoTelefono: string
{
    case MOVIL = 'movil';

    case FIJO = 'fijo';

    case DESCONOCIDO = 'desconocido';
}
