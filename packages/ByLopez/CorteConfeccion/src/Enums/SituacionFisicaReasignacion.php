<?php

namespace ByLopez\CorteConfeccion\Enums;

enum SituacionFisicaReasignacion: string
{
    /** Las prendas nunca salieron de bodega hacia el taller origen: solo cambia la responsabilidad. */
    case SinEntregaFisica = 'sin_entrega_fisica';
    /** Ya estaban en el taller origen; el operador verifico que NO tienen trabajo y se trasladan fisicamente. */
    case EntregadaVerificada = 'entregada_verificada';

    public function label(): string
    {
        return match ($this) {
            self::SinEntregaFisica => 'Sin entrega fisica al taller (solo cambia la responsabilidad)',
            self::EntregadaVerificada => 'Ya entregadas al taller: verificadas sin confeccionar y trasladadas',
        };
    }
}
