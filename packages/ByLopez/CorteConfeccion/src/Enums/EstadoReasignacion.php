<?php

namespace ByLopez\CorteConfeccion\Enums;

enum EstadoReasignacion: string
{
    /** Salio del taller origen, el destino aun no confirma recibirlas: nadie puede reportarlas. */
    case EnTransito = 'en_transito';
    case Aplicada = 'aplicada';
    /** Compensada despues de aplicarse (las unidades volvieron al origen). */
    case Revertida = 'revertida';
    /** Cancelada antes de que el destino las recibiera. */
    case Cancelada = 'cancelada';

    public function label(): string
    {
        return match ($this) {
            self::EnTransito => 'En transito',
            self::Aplicada => 'Aplicada',
            self::Revertida => 'Revertida',
            self::Cancelada => 'Cancelada',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::EnTransito => 'label-pending',
            self::Aplicada => 'label-active',
            self::Revertida, self::Cancelada => 'label-canceled',
        };
    }

    /** La operacion sigue moviendo responsabilidad (no esta compensada). */
    public function vigente(): bool
    {
        return in_array($this, [self::EnTransito, self::Aplicada], true);
    }
}
