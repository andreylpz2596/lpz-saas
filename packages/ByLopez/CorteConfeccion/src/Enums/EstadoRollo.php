<?php

namespace ByLopez\CorteConfeccion\Enums;

enum EstadoRollo: string
{
    case Disponible = 'disponible';
    case ParcialmenteUsado = 'parcialmente_usado';
    case ReservadoEnCorte = 'reservado_en_corte';
    case Agotado = 'agotado';
    case Anulado = 'anulado';

    public function label(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::ParcialmenteUsado => 'Parcialmente usado',
            self::ReservadoEnCorte => 'Reservado en corte',
            self::Agotado => 'Agotado',
            self::Anulado => 'Anulado',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Disponible => 'label-active',
            self::ParcialmenteUsado => 'label-pending',
            self::ReservadoEnCorte => 'label-processing',
            self::Agotado => 'label-closed',
            self::Anulado => 'label-canceled',
        };
    }

    public static function badgeHtml(?string $value): string
    {
        $estado = self::tryFrom((string) $value);

        if (! $estado) {
            return sprintf('<p class="label-info">%s</p>', e(str_replace('_', ' ', ucfirst((string) $value))));
        }

        return sprintf('<p class="%s">%s</p>', $estado->badgeClass(), e($estado->label()));
    }
}
