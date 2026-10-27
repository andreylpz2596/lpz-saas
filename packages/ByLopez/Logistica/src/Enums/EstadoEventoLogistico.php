<?php

namespace ByLopez\Logistica\Enums;

/** Estado de una entrega o un retorno: solo `confirmado → anulado` (anulacion auditada, bloque 19). */
enum EstadoEventoLogistico: string
{
    case CONFIRMADO = 'confirmado';
    case ANULADO = 'anulado';
}
