<?php

namespace ByLopez\Logistica\Enums;

/**
 * De donde sale la guia del envio. Los tres primeros los elige la operaria para una guia EXTERNA (numero que da la
 * transportadora o plataforma); `interna` NO se elige: lo fija el servidor cuando la transportadora esta configurada
 * con guia interna ByLopez (GI-000001). La API futura escribe `generada_por = api`, no un origen nuevo.
 */
enum OrigenGuia: string
{
    case MANUAL = 'manual';
    case DIRECTA_TRANSPORTADORA = 'directa_transportadora';
    case NOVENTA_Y_NUEVE_ENVIOS = '99envios';
    case INTERNA = 'interna';

    /** Origenes que puede elegir la operaria (guia externa). */
    public static function elegibles(): array
    {
        return [self::MANUAL->value, self::DIRECTA_TRANSPORTADORA->value, self::NOVENTA_Y_NUEVE_ENVIOS->value];
    }
}
