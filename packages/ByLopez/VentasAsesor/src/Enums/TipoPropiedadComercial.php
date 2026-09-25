<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Propiedad COMERCIAL de una venta (D-27, decidida en la Fase 01B).
 *
 * No debe confundirse con EstadoAtribucion, que es el estado TECNICO del
 * registro de atribucion.
 *
 * Aqui NO se decide comision. Que una venta sea de un asesor es condicion
 * necesaria pero no suficiente: la comision depende ademas de utilidad positiva,
 * costo congelado, entrega, devoluciones y reglas vigentes (D-01, D-03, D-31,
 * D-32), todas pendientes. La Fase 02A1 retiro el metodo esComisionable() que
 * afirmaba lo contrario.
 */
enum TipoPropiedadComercial: string
{
    /** La venta pertenece a un asesor. */
    case ASESOR = 'advisor';

    /** Venta directa de ByLopez: sin asesor. */
    case EMPRESA = 'company';

    /** Todavia no se decidio a quien pertenece; nunca se infiere. */
    case SIN_ASIGNAR = 'unassigned';

    public function exigeAsesor(): bool
    {
        return $this === self::ASESOR;
    }
}
