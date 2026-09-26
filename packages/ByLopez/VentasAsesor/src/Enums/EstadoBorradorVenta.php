<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Estados de negocio del borrador (03B). Pocos a proposito:
 *
 *   activo ──► confirmando ──► confirmado
 *     │             │
 *     │             └──► activo   (si la confirmacion falla; etapa posterior)
 *     └──► descartado
 *
 * Solo `activo` admite cambios. `confirmando` es el candado de la confirmacion (la
 * implementara la etapa de confirmacion): mientras exista, nadie mas edita ni confirma.
 * `confirmado` y `descartado` son finales.
 */
enum EstadoBorradorVenta: string
{
    case ACTIVO = 'activo';

    case CONFIRMANDO = 'confirmando';

    case CONFIRMADO = 'confirmado';

    case DESCARTADO = 'descartado';

    public function esEditable(): bool
    {
        return $this === self::ACTIVO;
    }

    public function esFinal(): bool
    {
        return $this === self::CONFIRMADO || $this === self::DESCARTADO;
    }
}
