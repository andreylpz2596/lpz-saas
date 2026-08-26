<?php

namespace ByLopez\CorteConfeccion\Enums;

enum EstadoRollo: string
{
    case Disponible = 'disponible';
    case ParcialmenteUsado = 'parcialmente_usado';
    case ReservadoEnCorte = 'reservado_en_corte';
    case Agotado = 'agotado';
    case Anulado = 'anulado';
}
