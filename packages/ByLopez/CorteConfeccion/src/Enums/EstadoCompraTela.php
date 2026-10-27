<?php

namespace ByLopez\CorteConfeccion\Enums;

enum EstadoCompraTela: string
{
    case Registrada = 'registrada';
    case Anulada = 'anulada';

    public function label(): string
    {
        return match ($this) {
            self::Registrada => 'Registrada',
            self::Anulada => 'Anulada',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Registrada => 'label-active',
            self::Anulada => 'label-canceled',
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
