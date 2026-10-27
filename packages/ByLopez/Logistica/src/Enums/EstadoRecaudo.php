<?php

namespace ByLopez\Logistica\Enums;

/**
 * Recaudo contra entrega. `pendiente_liquidar` = la transportadora DEBE recaudar $X; NO es dinero recibido por ByLopez
 * y no se registra en Cartera. `liquidado` queda reservado para la futura conciliacion con la transportadora.
 */
enum EstadoRecaudo: string
{
    case NO_APLICA = 'no_aplica';
    case PENDIENTE_LIQUIDAR = 'pendiente_liquidar';
    case LIQUIDADO = 'liquidado';
}
