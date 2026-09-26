<?php

namespace ByLopez\VentasAsesor\Enums;

/** Que encontro BuscarClienteComercialService (RT-04B1b). */
enum TipoResultadoBusquedaCliente: string
{
    /** El telefono normalizado pertenece a un Customer (principal, secundario o ficha historica). */
    case TELEFONO_EXACTO = 'telefono_exacto';

    /** Sin coincidencia por telefono; el email pertenece a un Customer del canal. */
    case EMAIL_EXACTO = 'email_exacto';

    /** Solo coincidencias por nombre: candidatos para elegir, nunca identidad automatica. */
    case CANDIDATOS_NOMBRE = 'candidatos_nombre';

    case SIN_RESULTADOS = 'sin_resultados';

    /** Datos contradictorios (telefono de A y email de B, o telefono en varias fichas). Fail closed. */
    case CONFLICTO = 'conflicto';
}
