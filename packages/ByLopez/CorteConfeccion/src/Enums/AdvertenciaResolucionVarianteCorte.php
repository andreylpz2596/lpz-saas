<?php

namespace ByLopez\CorteConfeccion\Enums;

/**
 * Avisos que acompanan una resolucion VALIDA. Un producto puede estar en
 * produccion antes de tener fotos, stock terminado o publicacion, por eso
 * "inactivo en tienda" nunca bloquea la resolucion.
 */
enum AdvertenciaResolucionVarianteCorte: string
{
    case ConfigurableInactivoEnTienda = 'configurable_inactivo_en_tienda';
    case VarianteInactivaEnTienda = 'variante_inactiva_en_tienda';

    public function mensaje(): string
    {
        return match ($this) {
            self::ConfigurableInactivoEnTienda => 'El producto configurable esta inactivo en tienda (no bloquea produccion).',
            self::VarianteInactivaEnTienda => 'La variante esta inactiva en tienda (no bloquea produccion).',
        };
    }
}
