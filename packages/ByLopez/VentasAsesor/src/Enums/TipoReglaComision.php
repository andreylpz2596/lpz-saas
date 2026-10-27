<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Tipo de regla de comision (COM-03). Fuente unica de los valores de `tipo`, `base` y `alcance` de
 * `bylopez_va_comision_reglas`: hoy un solo caso de cada uno (COM-D-01/03/06).
 */
enum TipoReglaComision: string
{
    /** Porcentaje sobre la utilidad (precio neto − costo congelado), llevada a 0 por unidad si es negativa. */
    case PORCENTAJE_UTILIDAD = 'porcentaje_utilidad';

    public const BASE_UTILIDAD_POSITIVA = 'utilidad_positiva';

    public const ALCANCE_GLOBAL = 'global';
}
