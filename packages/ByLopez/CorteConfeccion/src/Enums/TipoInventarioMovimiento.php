<?php

namespace ByLopez\CorteConfeccion\Enums;

enum TipoInventarioMovimiento: string
{
    case EntradaConfeccion = 'entrada_confeccion';
    case AjusteManual = 'ajuste_manual';
    case ReingresoDevolucion = 'reingreso_devolucion';

    public function label(): string
    {
        return match ($this) {
            self::EntradaConfeccion => 'Entrada confeccion',
            self::AjusteManual => 'Ajuste manual',
            self::ReingresoDevolucion => 'Reingreso por devolucion',
        };
    }
}
