<?php

namespace ByLopez\Logistica\Enums;

/**
 * Que guia usa una transportadora (eleccion EXCLUYENTE, independiente de su tipo: una empresa local tambien puede tener
 * guia externa). Se guarda en la capacidad existente `genera_guia_interna` (true = interna); no hay otro campo.
 *
 * - externa: el numero lo proporciona la transportadora o plataforma (flujo manual de siempre).
 * - interna: ByLopez genera el numero GI-000001 al despachar.
 */
enum ModoGuia: string
{
    case EXTERNA = 'externa';
    case INTERNA = 'interna';

    public static function deCapacidad(bool $generaGuiaInterna): self
    {
        return $generaGuiaInterna ? self::INTERNA : self::EXTERNA;
    }

    public function generaGuiaInterna(): bool
    {
        return $this === self::INTERNA;
    }
}
