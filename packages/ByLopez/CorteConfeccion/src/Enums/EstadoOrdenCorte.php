<?php

namespace ByLopez\CorteConfeccion\Enums;

enum EstadoOrdenCorte: string
{
    case PendienteEntrega = 'pendiente_entrega';
    case EntregadoACortador = 'entregado_a_cortador';
    case EnCorte = 'en_corte';
    case ReportadoPorCortador = 'reportado_por_cortador';
    case RecibidoBodega = 'recibido_bodega';
    case Cerrado = 'cerrado';
    case Anulado = 'anulado';
}
