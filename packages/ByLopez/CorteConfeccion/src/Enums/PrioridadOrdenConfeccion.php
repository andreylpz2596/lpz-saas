<?php

namespace ByLopez\CorteConfeccion\Enums;

enum PrioridadOrdenConfeccion: string
{
    case Normal = 'normal';
    case Alta = 'alta';
    case Urgente = 'urgente';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Alta => 'Alta',
            self::Urgente => 'Urgente',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Normal => 'label-info',
            self::Alta => 'label-pending',
            self::Urgente => 'label-canceled',
        };
    }

    public static function badgeHtml(?string $value): string
    {
        $prioridad = self::tryFrom((string) $value) ?? self::Normal;

        return sprintf('<p class="%s">%s</p>', $prioridad->badgeClass(), e($prioridad->label()));
    }
}
