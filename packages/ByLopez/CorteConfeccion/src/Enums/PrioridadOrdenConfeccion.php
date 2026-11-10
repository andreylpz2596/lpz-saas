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

    /** Columna del detalle que guarda la cantidad de este nivel; Normal no se guarda (es el resto). */
    public function columna(): ?string
    {
        return match ($this) {
            self::Normal => null,
            self::Alta => 'cantidad_alta',
            self::Urgente => 'cantidad_urgente',
        };
    }

    /**
     * Reparte un pendiente entre niveles sin superarlo: Urgente se cubre primero, luego Alta; Normal es el resto.
     *
     * @return array{urgente: int, alta: int, normal: int}
     */
    public static function repartir(int $pendiente, int $urgente, int $alta): array
    {
        $pendiente = max($pendiente, 0);
        $u = min(max($urgente, 0), $pendiente);
        $a = min(max($alta, 0), $pendiente - $u);

        return ['urgente' => $u, 'alta' => $a, 'normal' => $pendiente - $u - $a];
    }

    public static function badgeHtml(?string $value): string
    {
        $prioridad = self::tryFrom((string) $value) ?? self::Normal;

        return sprintf('<p class="%s">%s</p>', $prioridad->badgeClass(), e($prioridad->label()));
    }
}
