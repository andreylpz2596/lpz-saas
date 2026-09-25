<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Codigo estable de incidencia. Nunca se usan mensajes humanos ni textos
 * traducidos: estos valores se persisten, se consultan y se cuentan.
 */
enum CodigoIncidencia: string
{
    /** Ya existia una atribucion incompatible con el contexto recibido. */
    case ATRIBUCION_CONFLICTO = 'atribucion_conflicto';

    /** El historial de la atribucion no cumple lo que el modulo garantiza. */
    case HISTORIAL_INCOHERENTE = 'historial_incoherente';

    /** El contexto comercial no pudo construirse o es invalido. */
    case CONTEXTO_INVALIDO = 'contexto_invalido';

    /** Error inesperado dentro del listener. */
    case LISTENER_ERROR = 'listener_error';
}
