<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Veredicto del indexador historico de telefonos (RT-04B1a) para un Customer.
 * Describe que encontro y que hizo (o haria) el comando; customers.phone NUNCA se
 * modifica.
 */
enum ResultadoIndexacionTelefono: string
{
    /** Normalizable, sin titular: el modo persistente crearia su fila principal. */
    case INDEXABLE = 'indexable';

    /** Igual que INDEXABLE, ya escrito (solo modo persistente). */
    case INDEXADO = 'indexado';

    /** Ya tiene su fila principal identica a customers.phone. */
    case YA_INDEXADO = 'ya_indexado';

    /** customers.phone no es normalizable (caracteres, longitud o numeracion). */
    case INVALIDO = 'invalido';

    /** customers.phone es plausible pero no se puede normalizar sin adivinar. */
    case AMBIGUO = 'ambiguo';

    /** Dos o mas Customers tienen en customers.phone el mismo numero normalizado: no se elige titular. */
    case DUPLICADO = 'duplicado';

    /** El numero ya esta indexado para otro Customer, o como secundario del mismo. */
    case CONFLICTO = 'conflicto';

    /** La fila principal no coincide con customers.phone (cambio externo): el modo persistente la re-deriva. */
    case DIVERGENTE = 'divergente';

    /** Igual que DIVERGENTE, ya corregido (solo modo persistente). */
    case REINDEXADO = 'reindexado';

    /** El analisis o la escritura de ESE Customer fallo; los demas no se ven afectados. */
    case ERROR = 'error';

    /** Resultados que en modo persistente terminan con una escritura. */
    public function escribiria(): bool
    {
        return $this === self::INDEXABLE || $this === self::DIVERGENTE;
    }
}
