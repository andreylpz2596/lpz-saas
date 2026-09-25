<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Tipo de operacion que produjo una fila de historial.
 *
 * Es un valor ESTABLE de dominio: se persiste y se consulta, y nunca depende de
 * textos traducidos ni del motivo escrito por una persona.
 */
enum TipoOperacionAtribucion: string
{
    /** Primera atribucion del pedido, incluida la que nace en fallo tecnico. */
    case ASIGNACION_INICIAL = 'initial_assignment';

    /** Se asigno asesor a una venta que no lo tenia. */
    case ASIGNAR_ASESOR = 'assign_advisor';

    /** Cambio de un asesor a otro. */
    case REASIGNAR_ASESOR = 'reassign_advisor';

    /** La venta paso a ser directa de la empresa. */
    case MARCAR_EMPRESA = 'mark_company';

    /** La venta volvio a quedar sin asignar. */
    case MARCAR_SIN_ASIGNAR = 'mark_unassigned';

    /** Correccion producida por un proceso de reconciliacion. */
    case RECONCILIACION = 'reconciliation';
}
