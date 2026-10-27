<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Clasificacion comercial POR PEDIDO (COM-D-15, G-11). Explicita: nunca se infiere de precio, cliente ni comentario.
 * Solo `venta_normal` es comisionable; muestra, reposicion y garantia NO comisionan aunque tengan precio. Un pedido sin
 * clasificacion (historico u otro canal) tampoco es comisionable por si mismo (COM-D-13).
 */
enum TipoOperacionPedido: string
{
    case VENTA_NORMAL = 'venta_normal';
    case MUESTRA = 'muestra';
    case REPOSICION = 'reposicion';
    case GARANTIA = 'garantia';

    public function comisionable(): bool
    {
        return $this === self::VENTA_NORMAL;
    }

    /** Reposicion y garantia deberian ir normalmente a precio 0 (regla operativa): con total > 0 se advierte. */
    public function esperaPrecioCero(): bool
    {
        return $this === self::REPOSICION || $this === self::GARANTIA;
    }
}
